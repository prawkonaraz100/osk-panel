<?php

namespace App\Modules\UploadsAssets;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\ResourcesCore\ResourceScopeAuthorizer;
use App\Modules\StudentsCourses\StudentCourseScopeAuthorizer;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class UploadAssetService
{
    private const RESERVATION_PREFIX = 'upload-reservations';

    private const ASSET_PREFIX = 'assets';

    /** @var list<string> */
    private const CLIENT_UPLOAD_PURPOSES = [
        'formal_training_signed_scan',
        'staff_photo',
        'vehicle_photo',
        'vehicle_document',
    ];

    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ResourceScopeAuthorizer $resourceScope,
        private readonly StudentCourseScopeAuthorizer $courseScope,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array{upload_id:string,upload_url:string,expires_at:string}
     */
    public function presign(string $sessionId, array $input): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $purpose = (string) ($input['purpose'] ?? '');
        $parentType = (string) ($input['parent_type'] ?? '');
        $parentId = (string) ($input['parent_id'] ?? '');
        $declaredMime = $this->normalizeMime((string) ($input['declared_mime'] ?? ''));
        $sizeBytes = (int) ($input['size_bytes'] ?? 0);
        $expectedHash = $this->nullableHash($input['sha256'] ?? null);

        $policy = $this->policy($purpose);
        $this->authorizeParent($sessionId, $membership['organization_id'], $purpose, $parentType, $parentId);
        $this->assertDeclaredFilePolicy($policy, $declaredMime, $sizeBytes);

        $uploadId = (string) Str::uuid7();
        $filename = $this->sanitizeFilename((string) ($input['filename'] ?? ''));
        $storageKey = $this->storageKey(
            self::RESERVATION_PREFIX,
            $membership['organization_id'],
            $purpose,
            $parentType,
            $parentId,
            $uploadId,
        );
        $expiresAt = CarbonImmutable::now()->addMinutes($this->ttlMinutes());
        $disk = $this->storageDisk();
        $uploadUrl = $this->presignedPutUrl($disk, $storageKey, $expiresAt);

        DB::table('file_assets')->insert([
            'id' => $uploadId,
            'organization_id' => $membership['organization_id'],
            'storage_disk' => $disk,
            'storage_key' => $storageKey,
            'original_filename' => $filename,
            'mime_type_declared' => $declaredMime,
            'mime_type_detected' => null,
            'size_bytes' => $sizeBytes,
            'sha256' => $expectedHash,
            'purpose' => $purpose,
            'status' => 'pending',
            'created_by_user_id' => $membership['user_id'],
            'created_at' => CarbonImmutable::now(),
            'ready_at' => null,
            'deleted_at' => null,
        ]);

        return [
            'upload_id' => $uploadId,
            'upload_url' => $uploadUrl,
            'expires_at' => $expiresAt->utc()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{id:string,purpose:string,media_type:string,size_bytes:int,status:string}
     */
    public function complete(string $sessionId, string $uploadId, array $input): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        $row = DB::table('file_assets')
            ->where('organization_id', $membership['organization_id'])
            ->where('id', $uploadId)
            ->whereNull('deleted_at')
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        $data = (array) $row;
        $binding = $this->parseStorageBinding(
            (string) $data['storage_key'],
            $membership['organization_id'],
            (string) $data['purpose'],
            $uploadId,
        );
        $this->authorizeParent(
            $sessionId,
            $membership['organization_id'],
            (string) $data['purpose'],
            $binding['parent_type'],
            $binding['parent_id'],
        );

        if ((string) $data['status'] === 'ready') {
            return $this->present($row);
        }
        if ((string) $data['status'] !== 'pending') {
            throw ResourceDomainException::conflict('Upload reservation is not completable.');
        }

        $createdAt = CarbonImmutable::parse((string) $data['created_at']);
        if ($createdAt->addMinutes($this->ttlMinutes())->isPast()) {
            $this->rejectPending($membership['organization_id'], $uploadId, (string) $data['storage_disk'], (string) $data['storage_key']);
            throw ResourceDomainException::conflict('Upload reservation expired before completion.');
        }

        $disk = (string) $data['storage_disk'];
        $reservationKey = (string) $data['storage_key'];
        if (! Storage::disk($disk)->exists($reservationKey)) {
            throw ResourceDomainException::conflict('Uploaded object is not available yet.');
        }

        $bytes = Storage::disk($disk)->get($reservationKey);
        if (! is_string($bytes)) {
            throw ResourceDomainException::conflict('Uploaded object could not be read.');
        }

        $purpose = (string) $data['purpose'];
        $policy = $this->policy($purpose);
        $actualSize = strlen($bytes);
        $actualHash = hash('sha256', $bytes);
        $detectedMime = $this->detectMime($bytes);
        $requestedHash = $this->nullableHash($input['sha256'] ?? null);
        $reservedHash = $this->nullableHash($data['sha256'] ?? null);

        $rejection = $this->completionRejection(
            $policy,
            (string) $data['mime_type_declared'],
            (int) $data['size_bytes'],
            $actualSize,
            $reservedHash,
            $requestedHash,
            $actualHash,
            $detectedMime,
            $bytes,
        );
        if ($rejection !== null) {
            $this->rejectPending($membership['organization_id'], $uploadId, $disk, $reservationKey, $detectedMime);
            throw ResourceDomainException::rule($rejection);
        }

        $finalKey = $this->storageKey(
            self::ASSET_PREFIX,
            $membership['organization_id'],
            $purpose,
            $binding['parent_type'],
            $binding['parent_id'],
            $uploadId,
        );

        if (Storage::disk($disk)->put($finalKey, $bytes) !== true) {
            throw ResourceDomainException::conflict('Verified upload could not be materialized.');
        }

        $materialized = Storage::disk($disk)->get($finalKey);
        if (! is_string($materialized) || ! hash_equals($actualHash, hash('sha256', $materialized))) {
            Storage::disk($disk)->delete($finalKey);
            throw ResourceDomainException::conflict('Verified upload materialization failed integrity verification.');
        }

        try {
            $asset = DB::transaction(function () use (
                $membership,
                $uploadId,
                $reservationKey,
                $finalKey,
                $detectedMime,
                $actualHash,
            ): array {
                $current = DB::table('file_assets')
                    ->where('organization_id', $membership['organization_id'])
                    ->where('id', $uploadId)
                    ->lockForUpdate()
                    ->first();
                if ($current === null) {
                    throw ResourceDomainException::notFound();
                }
                if ((string) $current->status === 'ready') {
                    return $this->present($current);
                }
                if ((string) $current->status !== 'pending' || (string) $current->storage_key !== $reservationKey) {
                    throw ResourceDomainException::conflict('Upload reservation changed during completion.');
                }

                DB::table('file_assets')
                    ->where('organization_id', $membership['organization_id'])
                    ->where('id', $uploadId)
                    ->update([
                        'storage_key' => $finalKey,
                        'mime_type_detected' => $detectedMime,
                        'sha256' => $actualHash,
                        'status' => 'ready',
                        'ready_at' => CarbonImmutable::now(),
                    ]);

                $ready = DB::table('file_assets')
                    ->where('organization_id', $membership['organization_id'])
                    ->where('id', $uploadId)
                    ->first();
                if ($ready === null) {
                    throw ResourceDomainException::conflict('Ready upload asset was not persisted.');
                }

                return $this->present($ready);
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($finalKey);
            throw $exception;
        }

        Storage::disk($disk)->delete($reservationKey);

        return $asset;
    }

    /**
     * @param  array{max_size_bytes:int,mime_types:list<string>}  $policy
     */
    private function assertDeclaredFilePolicy(array $policy, string $declaredMime, int $sizeBytes): void
    {
        if ($sizeBytes < 1 || $sizeBytes > $policy['max_size_bytes']) {
            throw ResourceDomainException::rule('Upload size is outside the allowed range for this purpose.');
        }
        if (! in_array($declaredMime, $policy['mime_types'], true)) {
            throw ResourceDomainException::rule('Declared MIME type is not allowed for this upload purpose.');
        }
    }

    /**
     * @param  array{max_size_bytes:int,mime_types:list<string>}  $policy
     */
    private function completionRejection(
        array $policy,
        string $declaredMime,
        int $declaredSize,
        int $actualSize,
        ?string $reservedHash,
        ?string $requestedHash,
        string $actualHash,
        string $detectedMime,
        string $bytes,
    ): ?string {
        if ($actualSize !== $declaredSize || $actualSize < 1 || $actualSize > $policy['max_size_bytes']) {
            return 'Uploaded object size does not match the purpose-bound reservation.';
        }
        if ($reservedHash !== null && ! hash_equals($reservedHash, $actualHash)) {
            return 'Uploaded object SHA-256 does not match the reserved hash.';
        }
        if ($requestedHash !== null && ! hash_equals($requestedHash, $actualHash)) {
            return 'Uploaded object SHA-256 does not match completion input.';
        }

        $declaredMime = $this->normalizeMime($declaredMime);
        if (! in_array($declaredMime, $policy['mime_types'], true)
            || ! in_array($detectedMime, $policy['mime_types'], true)
            || $declaredMime !== $detectedMime) {
            return 'Uploaded object content type does not match the purpose-bound reservation.';
        }

        if (! $this->contentValid($detectedMime, $bytes)) {
            return 'Uploaded object failed content validation.';
        }

        return null;
    }

    private function contentValid(string $mime, string $bytes): bool
    {
        if ($mime === 'application/pdf') {
            return str_starts_with($bytes, '%PDF-')
                && str_contains(substr($bytes, -2048), '%%EOF');
        }

        if ($mime === 'image/png') {
            return str_starts_with($bytes, "\x89PNG\r\n\x1a\n");
        }
        if ($mime === 'image/jpeg') {
            return str_starts_with($bytes, "\xff\xd8\xff") && str_ends_with($bytes, "\xff\xd9");
        }
        if ($mime === 'image/webp') {
            return strlen($bytes) >= 12
                && substr($bytes, 0, 4) === 'RIFF'
                && substr($bytes, 8, 4) === 'WEBP';
        }

        return false;
    }

    private function detectMime(string $bytes): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);
        if (! is_string($mime) || trim($mime) === '') {
            throw ResourceDomainException::rule('Uploaded object MIME type could not be detected.');
        }

        return $this->normalizeMime($mime);
    }

    /**
     * @param  array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int}  $membership
     */
    private function authorizeParent(
        string $sessionId,
        string $organizationId,
        string $purpose,
        string $parentType,
        string $parentId,
    ): void {
        if ($purpose === 'formal_training_signed_scan') {
            if ($parentType !== 'formal_training_document') {
                throw ResourceDomainException::rule('Formal signed scan upload requires a formal training document parent.');
            }

            $document = DB::table('formal_training_documents')
                ->where('organization_id', $organizationId)
                ->where('id', $parentId)
                ->first();
            if ($document === null) {
                throw ResourceDomainException::notFound();
            }
            if ((string) $document->document_mode_snapshot !== 'paper') {
                throw ResourceDomainException::rule('Signed scan upload is only valid for a paper-mode formal document.');
            }

            $this->courseScope->requireCourseTarget(
                $sessionId,
                'formal_documents.deliver',
                (string) $document->course_enrollment_id,
            );

            return;
        }

        if ($purpose === 'staff_photo') {
            if ($parentType === 'organization' && $parentId === $organizationId) {
                $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $organizationId, 'staff.create');

                return;
            }
            if ($parentType === 'staff_profile') {
                $this->resourceScope->requireStaffTarget($sessionId, 'staff.edit', $parentId);

                return;
            }

            throw ResourceDomainException::rule('Staff photo upload parent is invalid.');
        }

        if ($purpose === 'vehicle_photo') {
            if ($parentType === 'organization' && $parentId === $organizationId) {
                $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $organizationId, 'vehicles.create');

                return;
            }
            if ($parentType === 'vehicle') {
                $this->resourceScope->requireVehicleTarget($sessionId, 'vehicles.edit', $parentId);

                return;
            }

            throw ResourceDomainException::rule('Vehicle photo upload parent is invalid.');
        }

        if ($purpose === 'vehicle_document') {
            if ($parentType !== 'vehicle') {
                throw ResourceDomainException::rule('Vehicle document upload requires a vehicle parent.');
            }

            $this->resourceScope->requireVehicleTarget($sessionId, 'vehicles.edit', $parentId);

            return;
        }

        throw ResourceDomainException::rule('Unsupported client upload purpose.');
    }

    /**
     * @return array{max_size_bytes:int,mime_types:list<string>}
     */
    private function policy(string $purpose): array
    {
        if (! in_array($purpose, self::CLIENT_UPLOAD_PURPOSES, true)) {
            throw ResourceDomainException::rule('Unsupported client upload purpose.');
        }

        $policy = config("uploads.purposes.{$purpose}");
        if (! is_array($policy)
            || ! isset($policy['max_size_bytes'], $policy['mime_types'])
            || ! is_int($policy['max_size_bytes'])
            || ! is_array($policy['mime_types'])) {
            throw new LogicException("Upload policy {$purpose} is not configured.");
        }

        $mimes = array_values(array_filter(
            array_map(static fn ($mime): string => is_string($mime) ? strtolower(trim($mime)) : '', $policy['mime_types']),
            static fn (string $mime): bool => $mime !== '',
        ));

        return [
            'max_size_bytes' => $policy['max_size_bytes'],
            'mime_types' => $mimes,
        ];
    }

    /**
     * @return array{parent_type:string,parent_id:string}
     */
    private function parseStorageBinding(
        string $storageKey,
        string $organizationId,
        string $purpose,
        string $uploadId,
    ): array {
        $segments = explode('/', $storageKey);
        if (count($segments) !== 6
            || ! in_array($segments[0], [self::RESERVATION_PREFIX, self::ASSET_PREFIX], true)
            || $segments[1] !== $organizationId
            || $segments[2] !== $purpose
            || $segments[5] !== $uploadId
            || ! Str::isUuid($segments[4])) {
            throw ResourceDomainException::conflict('Upload reservation binding is invalid.');
        }

        return [
            'parent_type' => $segments[3],
            'parent_id' => $segments[4],
        ];
    }

    private function storageKey(
        string $prefix,
        string $organizationId,
        string $purpose,
        string $parentType,
        string $parentId,
        string $uploadId,
    ): string {
        if (! preg_match('/^[a-z_]+$/', $parentType) || ! Str::isUuid($parentId)) {
            throw ResourceDomainException::rule('Upload parent binding is invalid.');
        }

        return implode('/', [$prefix, $organizationId, $purpose, $parentType, $parentId, $uploadId]);
    }

    private function rejectPending(
        string $organizationId,
        string $uploadId,
        string $disk,
        string $reservationKey,
        ?string $detectedMime = null,
    ): void {
        DB::table('file_assets')
            ->where('organization_id', $organizationId)
            ->where('id', $uploadId)
            ->where('status', 'pending')
            ->update([
                'mime_type_detected' => $detectedMime,
                'status' => 'rejected',
            ]);

        Storage::disk($disk)->delete($reservationKey);
    }

    private function sanitizeFilename(string $filename): string
    {
        $base = basename(str_replace('\\', '/', trim($filename)));
        $base = preg_replace('/[\x00-\x1F\x7F]/u', '', $base);
        $base = is_string($base) ? trim($base) : '';

        if ($base === '' || in_array($base, ['.', '..'], true)) {
            throw ResourceDomainException::rule('Upload filename is invalid.');
        }

        return mb_substr($base, 0, 255);
    }

    private function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        return $mime === 'image/jpg' ? 'image/jpeg' : $mime;
    }

    private function nullableHash(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw ResourceDomainException::rule('SHA-256 must be lowercase hexadecimal.');
        }

        return $value;
    }

    private function ttlMinutes(): int
    {
        $ttl = (int) config('uploads.ttl_minutes', 15);

        return min(60, max(1, $ttl));
    }

    private function storageDisk(): string
    {
        $disk = trim((string) config('uploads.disk', 's3'));
        if ($disk === '') {
            throw new LogicException('Upload storage disk is not configured.');
        }

        return $disk;
    }

    private function presignedPutUrl(string $disk, string $storageKey, CarbonImmutable $expiresAt): string
    {
        $configuration = config("filesystems.disks.{$disk}");
        if (! is_array($configuration) || ($configuration['driver'] ?? null) !== 's3') {
            throw new LogicException('Direct upload presigning requires an S3-compatible filesystem disk.');
        }

        $bucket = trim((string) ($configuration['bucket'] ?? ''));
        if ($bucket === '') {
            throw new LogicException('S3 upload bucket is not configured.');
        }

        $options = [
            'version' => 'latest',
            'region' => (string) ($configuration['region'] ?? 'us-east-1'),
            'use_path_style_endpoint' => (bool) ($configuration['use_path_style_endpoint'] ?? false),
        ];

        $endpoint = trim((string) ($configuration['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $options['endpoint'] = $endpoint;
        }

        $key = (string) ($configuration['key'] ?? '');
        $secret = (string) ($configuration['secret'] ?? '');
        if ($key !== '' && $secret !== '') {
            $options['credentials'] = ['key' => $key, 'secret' => $secret];
        }

        $client = new S3Client($options);
        $command = $client->getCommand('PutObject', [
            'Bucket' => $bucket,
            'Key' => $storageKey,
        ]);
        $request = $client->createPresignedRequest($command, $expiresAt->toDateTimeImmutable());

        return (string) $request->getUri();
    }

    /** @return array{id:string,purpose:string,media_type:string,size_bytes:int,status:string} */
    private function present(object $row): array
    {
        $data = (array) $row;

        return [
            'id' => (string) $data['id'],
            'purpose' => (string) $data['purpose'],
            'media_type' => (string) ($data['mime_type_detected'] ?? $data['mime_type_declared'] ?? ''),
            'size_bytes' => (int) $data['size_bytes'],
            'status' => (string) $data['status'],
        ];
    }
}
