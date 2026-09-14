<?php

namespace Tests\Feature;

use Aws\Exception\AwsException;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\ResourcesCore\VehicleService;
use Aws\S3\S3Client;
use Database\Seeders\FormalTrainingDocumentTemplateSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;
use Throwable;

final class UploadAssetRuntimeTest extends TestCase
{
    private string $bucket;

    private S3Client $s3;

    protected function setUp(): void
    {
        parent::setUp();

        FoundationSchema::reset();

        $this->bucket = 'osk-panel-upload-runtime-test';
        config([
            'uploads.disk' => 's3',
            'uploads.ttl_minutes' => 15,
            'filesystems.disks.s3.bucket' => $this->bucket,
        ]);
        Storage::forgetDisk('s3');

        $this->s3 = $this->s3Client();
        try {
            $this->s3->createBucket(['Bucket' => $this->bucket]);
        } catch (AwsException $exception) {
            if (! in_array($exception->getAwsErrorCode(), ['BucketAlreadyOwnedByYou', 'BucketAlreadyExists'], true)) {
                throw $exception;
            }
        }
        $this->clearBucket();
    }

    protected function tearDown(): void
    {
        try {
            $this->clearBucket();
            $this->s3->deleteBucket(['Bucket' => $this->bucket]);
        } catch (Throwable) {
            // Container teardown is authoritative; cleanup must not hide test assertions.
        }

        parent::tearDown();
    }

    public function test_staff_photo_upload_is_purpose_bound_hash_verified_and_immutable_after_completion(): void
    {
        $actor = FoundationSchema::actor();
        $bytes = $this->pngBytes();
        $hash = hash('sha256', $bytes);

        $presign = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->postJson('/api/v1/uploads/presign', [
                'purpose' => 'staff_photo',
                'filename' => '../portrait.png',
                'declared_mime' => 'image/png',
                'size_bytes' => strlen($bytes),
                'sha256' => $hash,
                'parent_type' => 'organization',
                'parent_id' => $actor['organization_id'],
            ])
            ->assertCreated();

        $uploadId = (string) $presign->json('upload_id');
        $this->assertNotSame('', (string) $presign->json('upload_url'));

        $pending = DB::table('file_assets')->where('id', $uploadId)->firstOrFail();
        $reservationKey = (string) $pending->storage_key;
        $this->assertSame('pending', $pending->status);
        $this->assertSame('portrait.png', $pending->original_filename);
        $this->assertStringStartsWith('upload-reservations/', $reservationKey);

        Storage::disk('s3')->put($reservationKey, $bytes);

        $key = (string) Str::uuid7();
        $completed = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/uploads/{$uploadId}/complete", ['sha256' => $hash])
            ->assertOk();

        $this->assertSame($uploadId, $completed->json('id'));
        $this->assertSame('staff_photo', $completed->json('purpose'));
        $this->assertSame('image/png', $completed->json('media_type'));
        $this->assertSame('ready', $completed->json('status'));

        $ready = DB::table('file_assets')->where('id', $uploadId)->firstOrFail();
        $finalKey = (string) $ready->storage_key;
        $this->assertStringStartsWith('assets/', $finalKey);
        $this->assertNotSame($reservationKey, $finalKey);
        $this->assertSame($hash, $ready->sha256);
        $this->assertSame('image/png', $ready->mime_type_detected);
        $this->assertFalse(Storage::disk('s3')->exists($reservationKey));
        $this->assertSame($hash, hash('sha256', (string) Storage::disk('s3')->get($finalKey)));

        Storage::disk('s3')->put($reservationKey, 'late overwrite attempt');
        $this->assertSame($hash, hash('sha256', (string) Storage::disk('s3')->get($finalKey)));

        $replayed = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/uploads/{$uploadId}/complete", ['sha256' => $hash])
            ->assertOk();

        $this->assertEquals($completed->json(), $replayed->json());
        $this->assertSame(1, DB::table('file_assets')->where('id', $uploadId)->count());
    }

    public function test_invalid_content_is_rejected_and_never_becomes_ready(): void
    {
        $actor = FoundationSchema::actor();
        $bytes = $this->pdfBytes();
        $hash = hash('sha256', $bytes);

        $presign = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->postJson('/api/v1/uploads/presign', [
                'purpose' => 'staff_photo',
                'filename' => 'portrait.png',
                'declared_mime' => 'image/png',
                'size_bytes' => strlen($bytes),
                'sha256' => $hash,
                'parent_type' => 'organization',
                'parent_id' => $actor['organization_id'],
            ])
            ->assertCreated();

        $uploadId = (string) $presign->json('upload_id');
        $reservationKey = (string) DB::table('file_assets')->where('id', $uploadId)->value('storage_key');
        Storage::disk('s3')->put($reservationKey, $bytes);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/uploads/{$uploadId}/complete", ['sha256' => $hash])
            ->assertStatus(422);

        $asset = DB::table('file_assets')->where('id', $uploadId)->firstOrFail();
        $this->assertSame('rejected', $asset->status);
        $this->assertNotSame('ready', $asset->status);
        $this->assertFalse(Storage::disk('s3')->exists($reservationKey));

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->postJson('/api/v1/uploads/presign', [
                'purpose' => 'formal_training_document',
                'filename' => 'forbidden.pdf',
                'declared_mime' => 'application/pdf',
                'size_bytes' => strlen($bytes),
                'sha256' => $hash,
                'parent_type' => 'organization',
                'parent_id' => $actor['organization_id'],
            ])
            ->assertStatus(422);
    }

    public function test_formal_signed_scan_upload_respects_assigned_student_scope_and_attaches_to_paper_document(): void
    {
        $actor = FoundationSchema::actor(false);
        app(FormalTrainingDocumentTemplateSeeder::class)->run();

        $assignedInstructor = $this->staffProfile($actor['organization_id'], 'Assigned', 'Instructor');
        DB::table('staff_membership_links')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $actor['organization_id'],
            'staff_profile_id' => $assignedInstructor,
            'organization_membership_id' => $actor['membership_id'],
            'linked_at' => now(),
            'linked_by_user_id' => $actor['user_id'],
        ]);
        FoundationSchema::grant($actor['membership_id'], 'formal_documents.deliver', ['assigned_students']);

        $assignedDocument = $this->paperFormalDocument($actor, $assignedInstructor);
        $otherInstructor = $this->staffProfile($actor['organization_id'], 'Other', 'Instructor');
        $unassignedDocument = $this->paperFormalDocument($actor, $otherInstructor);

        $bytes = $this->pdfBytes();
        $hash = hash('sha256', $bytes);

        $presign = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->postJson('/api/v1/uploads/presign', [
                'purpose' => 'formal_training_signed_scan',
                'filename' => 'signed-training-record.pdf',
                'declared_mime' => 'application/pdf',
                'size_bytes' => strlen($bytes),
                'sha256' => $hash,
                'parent_type' => 'formal_training_document',
                'parent_id' => $assignedDocument,
            ])
            ->assertCreated();

        $uploadId = (string) $presign->json('upload_id');
        $reservationKey = (string) DB::table('file_assets')->where('id', $uploadId)->value('storage_key');
        Storage::disk('s3')->put($reservationKey, $bytes);

        $ready = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/uploads/{$uploadId}/complete", ['sha256' => $hash])
            ->assertOk();

        $this->assertSame('formal_training_signed_scan', $ready->json('purpose'));
        $this->assertSame('ready', $ready->json('status'));

        $attached = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/formal-training-documents/{$assignedDocument}/delivery-events", [
                'event_type' => 'signed_scan_attached',
                'optional_asset_id' => $uploadId,
            ])
            ->assertCreated();

        $this->assertSame('signed_scan_attached', $attached->json('event_type'));
        $this->assertSame($uploadId, $attached->json('optional_asset_id'));

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->postJson('/api/v1/uploads/presign', [
                'purpose' => 'formal_training_signed_scan',
                'filename' => 'unassigned.pdf',
                'declared_mime' => 'application/pdf',
                'size_bytes' => strlen($bytes),
                'sha256' => $hash,
                'parent_type' => 'formal_training_document',
                'parent_id' => $unassignedDocument,
            ])
            ->assertNotFound();
    }

    /** @param array{organization_id:string,user_id:string,membership_id:string,session_id:string} $actor */
    private function paperFormalDocument(array $actor, string $instructorId): string
    {
        $studentId = (string) Str::uuid7();
        DB::table('students')->insert([
            'id' => $studentId,
            'organization_id' => $actor['organization_id'],
            'first_name' => 'Anna',
            'last_name' => 'Nowak',
            'birth_date' => '1990-05-17',
            'no_pesel_declared' => true,
            'version' => 1,
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        $categoryId = (string) DB::table('driving_categories')->where('code', 'B')->value('id');
        $courseId = (string) Str::uuid7();
        DB::table('course_enrollments')->insert([
            'id' => $courseId,
            'organization_id' => $actor['organization_id'],
            'student_id' => $studentId,
            'training_type' => 'basic',
            'driving_category_id' => $categoryId,
            'started_at' => now()->subDays(10),
            'lead_instructor_id' => $instructorId,
            'training_stage' => 'theory',
            'version' => 1,
            'requirements_revision' => 1,
            'document_mode' => 'paper',
            'document_mode_selected_at' => now()->subDays(11),
            'document_mode_selected_by_user_id' => $actor['user_id'],
            'created_at' => now()->subDays(12),
            'updated_at' => now()->subDays(10),
        ]);

        $template = DB::table('formal_training_document_templates')
            ->where('document_type', 'training_record_card')
            ->firstOrFail();

        $canonicalAssetId = (string) Str::uuid7();
        $canonicalBytes = $this->pdfBytes();
        $canonicalHash = hash('sha256', $canonicalBytes);
        DB::table('file_assets')->insert([
            'id' => $canonicalAssetId,
            'organization_id' => $actor['organization_id'],
            'storage_disk' => 's3',
            'storage_key' => 'server-generated/'.$canonicalAssetId,
            'original_filename' => 'training-record.pdf',
            'mime_type_declared' => 'application/pdf',
            'mime_type_detected' => 'application/pdf',
            'size_bytes' => strlen($canonicalBytes),
            'sha256' => $canonicalHash,
            'purpose' => 'formal_training_document',
            'status' => 'ready',
            'created_by_user_id' => $actor['user_id'],
            'created_at' => now(),
            'ready_at' => now(),
            'deleted_at' => null,
        ]);

        $documentId = (string) Str::uuid7();
        DB::table('formal_training_documents')->insert([
            'id' => $documentId,
            'organization_id' => $actor['organization_id'],
            'course_enrollment_id' => $courseId,
            'document_type' => 'training_record_card',
            'revision' => 1,
            'document_mode_snapshot' => 'paper',
            'formal_training_document_template_id' => (string) $template->id,
            'template_version_snapshot' => (string) $template->template_version,
            'renderer_version_snapshot' => (string) $template->renderer_version,
            'template_hash_snapshot' => (string) $template->template_content_hash,
            'course_version_snapshot' => 1,
            'requirements_revision_snapshot' => 1,
            'evidence_bundle_hash' => hash('sha256', 'evidence-'.$documentId),
            'asset_id' => $canonicalAssetId,
            'content_hash' => $canonicalHash,
            'approved_by_user_id' => $actor['user_id'],
            'approved_at' => now(),
            'generated_by_user_id' => $actor['user_id'],
            'generated_at' => now(),
            'created_at' => now(),
        ]);

        return $documentId;
    }

    private function staffProfile(string $organizationId, string $firstName, string $lastName): string
    {
        $id = (string) Str::uuid7();
        DB::table('staff_profiles')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email_normalized' => strtolower($id).'@example.test',
            'authorization_number' => 'INSTR-'.$id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_resource_photo_uploads_bind_to_concrete_staff_and_vehicle_before_attachment(): void
    {
        $actor = FoundationSchema::actor();

        $staff = app(StaffService::class)->create(
            $actor['session_id'],
            [
                'email' => 'resource-photo.staff@example.test',
                'first_name' => 'Photo',
                'last_name' => 'Staff',
                'staff_type_codes' => ['OfficeWorker'],
                'category_ids' => [],
                'location_ids' => [],
            ],
            (string) Str::uuid7(),
        );
        $vehicle = app(VehicleService::class)->create(
            $actor['session_id'],
            [
                'registration_number' => 'KRPHOTO1',
                'make' => 'Toyota',
                'model' => 'Yaris',
                'category_ids' => [],
                'location_ids' => [],
            ],
            (string) Str::uuid7(),
        );

        $staffAssetId = $this->readyPhotoUpload(
            $actor,
            'staff_photo',
            'staff_profile',
            (string) $staff['id'],
        );
        $vehicleAssetId = $this->readyPhotoUpload(
            $actor,
            'vehicle_photo',
            'vehicle',
            (string) $vehicle['id'],
        );

        $staff = app(StaffService::class)->update(
            $actor['session_id'],
            (string) $staff['id'],
            ['photo_asset_id' => $staffAssetId],
            (string) Str::uuid7(),
        );
        $vehicle = app(VehicleService::class)->update(
            $actor['session_id'],
            (string) $vehicle['id'],
            ['photo_asset_id' => $vehicleAssetId],
            (string) Str::uuid7(),
        );

        $this->assertSame($staffAssetId, $staff['photo_asset_id']);
        $this->assertSame($vehicleAssetId, $vehicle['photo_asset_id']);
        $this->assertSame('ready', DB::table('file_assets')->where('id', $staffAssetId)->value('status'));
        $this->assertSame('ready', DB::table('file_assets')->where('id', $vehicleAssetId)->value('status'));
        $this->assertStringContainsString(
            '/staff_profile/'.(string) $staff['id'].'/',
            (string) DB::table('file_assets')->where('id', $staffAssetId)->value('storage_key'),
        );
        $this->assertStringContainsString(
            '/vehicle/'.(string) $vehicle['id'].'/',
            (string) DB::table('file_assets')->where('id', $vehicleAssetId)->value('storage_key'),
        );
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     */
    private function readyPhotoUpload(
        array $actor,
        string $purpose,
        string $parentType,
        string $parentId,
    ): string {
        $bytes = $this->pngBytes();
        $hash = hash('sha256', $bytes);

        $presign = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->postJson('/api/v1/uploads/presign', [
                'purpose' => $purpose,
                'filename' => $purpose.'.png',
                'declared_mime' => 'image/png',
                'size_bytes' => strlen($bytes),
                'sha256' => $hash,
                'parent_type' => $parentType,
                'parent_id' => $parentId,
            ])
            ->assertCreated();

        $uploadId = (string) $presign->json('upload_id');
        $reservationKey = (string) DB::table('file_assets')->where('id', $uploadId)->value('storage_key');
        Storage::disk('s3')->put($reservationKey, $bytes);

        $complete = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/uploads/{$uploadId}/complete", ['sha256' => $hash])
            ->assertOk();

        $this->assertSame('ready', $complete->json('status'));

        return $uploadId;
    }

    private function pngBytes(): string
    {
        $signature = "\x89PNG\r\n\x1a\n";
        $ihdr = pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0);
        $scanline = "\x00\xff\x00\x00";

        return $signature
            .$this->pngChunk('IHDR', $ihdr)
            .$this->pngChunk('IDAT', gzcompress($scanline))
            .$this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            .$type
            .$data
            .pack('N', crc32($type.$data));
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF\n";
    }

    private function s3Client(): S3Client
    {
        $configuration = config('filesystems.disks.s3');
        if (! is_array($configuration)) {
            throw new \LogicException('S3 test configuration is unavailable.');
        }

        return new S3Client([
            'version' => 'latest',
            'region' => (string) ($configuration['region'] ?? 'us-east-1'),
            'credentials' => [
                'key' => (string) ($configuration['key'] ?? 'test'),
                'secret' => (string) ($configuration['secret'] ?? 'test'),
            ],
            'endpoint' => (string) ($configuration['endpoint'] ?? 'http://localhost:5000'),
            'use_path_style_endpoint' => true,
        ]);
    }

    private function clearBucket(): void
    {
        $objects = $this->s3->listObjectsV2(['Bucket' => $this->bucket])['Contents'] ?? [];
        if ($objects === []) {
            return;
        }

        $this->s3->deleteObjects([
            'Bucket' => $this->bucket,
            'Delete' => [
                'Objects' => array_values(array_map(
                    static fn (array $object): array => ['Key' => (string) $object['Key']],
                    $objects,
                )),
                'Quiet' => true,
            ],
        ]);
    }
}
