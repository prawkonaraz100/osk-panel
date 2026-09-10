<?php

namespace App\Modules\ResourcesCore;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\IdentityTenant\TenantAuthorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VehicleService
{
    private const DOCUMENT_FIELDS = [
        'next_inspection_at' => 'technical_inspection',
        'oc_valid_until' => 'oc_insurance',
        'ac_valid_until' => 'ac_insurance',
    ];

    private const DOCUMENT_TYPES = ['technical_inspection', 'oc_insurance', 'ac_insurance'];

    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ResourceScopeAuthorizer $scopeAuthorizer,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /** @return array{data:list<array<string,mixed>>,meta:array<string,int>} */
    public function list(string $sessionId, int $page, int $perPage, ?string $q, ?string $sort, string $direction): array
    {
        $visibility = $this->scopeAuthorizer->visibility($sessionId, 'vehicles.view');
        $query = DB::table('vehicles')->where('organization_id', $visibility['membership']['organization_id']);

        if (! $visibility['unrestricted']) {
            if ($visibility['location_ids'] === []) {
                return ['data' => [], 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1]];
            }
            $query->whereIn('id', DB::table('vehicle_location_assignments')
                ->where('organization_id', $visibility['membership']['organization_id'])
                ->whereIn('location_id', $visibility['location_ids'])
                ->select('vehicle_id'));
        }

        if ($q !== null && trim($q) !== '') {
            $needle = '%'.mb_strtolower(trim($q)).'%';
            $query->where(function ($builder) use ($needle): void {
                $builder->whereRaw('LOWER(registration_number_normalized) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(make) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(model) LIKE ?', [$needle]);
            });
        }

        $sortMap = [
            'registration_number' => 'registration_number_normalized',
            'make' => 'make',
            'model' => 'model',
            'created_at' => 'created_at',
        ];
        $sortColumn = $sortMap[$sort ?? 'registration_number'] ?? 'registration_number_normalized';
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $total = (clone $query)->count();
        $rows = $query->orderBy($sortColumn, $direction)->forPage($page, $perPage)->get();

        return [
            'data' => $rows->map(fn ($row): array => $this->present($row))->all(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function get(string $sessionId, string $vehicleId): array
    {
        $membership = $this->scopeAuthorizer->requireVehicleTarget($sessionId, 'vehicles.view', $vehicleId);
        $row = DB::table('vehicles')->where('organization_id', $membership['organization_id'])->where('id', $vehicleId)->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $this->present($row);
    }

    /** @param array<string,mixed> $input */
    public function create(string $sessionId, array $input, string $requestId): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $input, $requestId): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $snapshot['organization_id'], 'vehicles.create');
            $registration = $this->registration((string) $input['registration_number']);
            $vin = $this->vin($input['vin'] ?? null);
            $this->assertIdentityAvailable($actor['organization_id'], $registration, $vin, null);
            $this->assertRelations($actor['organization_id'], $input);
            $this->assertAsset($actor['organization_id'], $input['photo_asset_id'] ?? null, 'vehicle_photo');

            $id = (string) Str::uuid7();
            $now = now();
            DB::table('vehicles')->insert([
                'id' => $id,
                'organization_id' => $actor['organization_id'],
                'registration_number_normalized' => $registration,
                'side_number' => $this->nullableString($input['side_number'] ?? null),
                'make' => trim((string) $input['make']),
                'model' => trim((string) $input['model']),
                'production_year' => $input['production_year'] ?? null,
                'engine_capacity_cm3' => $input['engine_capacity_cm3'] ?? null,
                'vin_normalized' => $vin,
                'photo_asset_id' => $input['photo_asset_id'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->syncRelations($actor['organization_id'], $id, $input);
            foreach (self::DOCUMENT_FIELDS as $field => $type) {
                if (array_key_exists($field, $input) && $input[$field] !== null) {
                    $this->replaceDocumentLocked($actor, $id, $type, (string) $input[$field], null, $requestId, false);
                }
            }

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'vehicle.created', 'vehicle', $id, $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => array_keys($input), 'state' => 'active'],
            );

            return $this->present(DB::table('vehicles')->where('id', $id)->firstOrFail());
        });
    }

    /** @param array<string,mixed> $input */
    public function update(string $sessionId, string $vehicleId, array $input, string $requestId, ?string $expectedTag = null): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $vehicleId, $input, $requestId, $expectedTag): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $snapshot['organization_id'], 'vehicles.edit');
            $row = DB::table('vehicles')->where('organization_id', $actor['organization_id'])->where('id', $vehicleId)->lockForUpdate()->first();
            if ($row === null) {
                throw ResourceDomainException::notFound();
            }
            if ($row->archived_at !== null) {
                throw ResourceDomainException::conflict('Archived vehicle must be restored before editing.');
            }
            if ($expectedTag !== null && trim($expectedTag) !== '' && ! hash_equals($this->etag($this->present($row)), trim($expectedTag))) {
                throw ResourceDomainException::conflict('Vehicle changed since it was loaded.');
            }

            $registration = array_key_exists('registration_number', $input)
                ? $this->registration((string) $input['registration_number'])
                : (string) $row->registration_number_normalized;
            $vin = array_key_exists('vin', $input) ? $this->vin($input['vin']) : ($row->vin_normalized === null ? null : (string) $row->vin_normalized);
            $this->assertIdentityAvailable($actor['organization_id'], $registration, $vin, $vehicleId);
            $this->assertRelations($actor['organization_id'], $input);
            if (array_key_exists('photo_asset_id', $input)) {
                $this->assertAsset($actor['organization_id'], $input['photo_asset_id'], 'vehicle_photo');
            }

            $updates = ['updated_at' => now()];
            $fieldMap = [
                'registration_number' => 'registration_number_normalized',
                'side_number' => 'side_number',
                'make' => 'make',
                'model' => 'model',
                'production_year' => 'production_year',
                'engine_capacity_cm3' => 'engine_capacity_cm3',
                'photo_asset_id' => 'photo_asset_id',
            ];
            foreach ($fieldMap as $source => $target) {
                if (array_key_exists($source, $input)) {
                    $value = $input[$source];
                    if ($source === 'registration_number') {
                        $value = $registration;
                    } elseif ($source === 'side_number') {
                        $value = $this->nullableString($value);
                    } elseif (in_array($source, ['make', 'model'], true)) {
                        $value = trim((string) $value);
                    }
                    $updates[$target] = $value;
                }
            }
            if (array_key_exists('vin', $input)) {
                $updates['vin_normalized'] = $vin;
            }
            DB::table('vehicles')->where('id', $vehicleId)->update($updates);
            $this->syncRelations($actor['organization_id'], $vehicleId, $input);

            foreach (self::DOCUMENT_FIELDS as $field => $type) {
                if (array_key_exists($field, $input)) {
                    $this->replaceDocumentLocked(
                        $actor,
                        $vehicleId,
                        $type,
                        $input[$field] === null ? null : (string) $input[$field],
                        null,
                        $requestId,
                        false,
                    );
                }
            }

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'vehicle.updated', 'vehicle', $vehicleId, $requestId,
                ['fields' => array_keys($input), 'state' => 'active'],
                ['fields' => array_keys($input), 'state' => 'active'],
            );

            return $this->present(DB::table('vehicles')->where('id', $vehicleId)->firstOrFail());
        });
    }

    public function archive(string $sessionId, string $vehicleId, string $requestId, ?string $reason): array
    {
        return $this->archiveState($sessionId, $vehicleId, $requestId, $reason, true);
    }

    public function restore(string $sessionId, string $vehicleId, string $requestId): array
    {
        return $this->archiveState($sessionId, $vehicleId, $requestId, null, false);
    }

    /** @return list<array<string,mixed>> */
    public function documents(string $sessionId, string $vehicleId): array
    {
        $membership = $this->scopeAuthorizer->requireVehicleTarget($sessionId, 'vehicles.view', $vehicleId);
        if (! DB::table('vehicles')->where('organization_id', $membership['organization_id'])->where('id', $vehicleId)->exists()) {
            throw ResourceDomainException::notFound();
        }

        return DB::table('vehicle_documents')
            ->where('organization_id', $membership['organization_id'])
            ->where('vehicle_id', $vehicleId)
            ->whereNull('superseded_at')
            ->orderBy('document_type')
            ->get()
            ->map(fn ($row): array => $this->presentDocument($row))
            ->all();
    }

    public function replaceDocument(
        string $sessionId,
        string $vehicleId,
        string $documentType,
        ?string $validUntil,
        ?string $assetId,
        string $requestId,
    ): array {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $vehicleId, $documentType, $validUntil, $assetId, $requestId): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $snapshot['organization_id'], 'vehicles.edit');
            if (! in_array($documentType, self::DOCUMENT_TYPES, true)) {
                throw ResourceDomainException::rule('Unsupported vehicle document type.');
            }
            if ($assetId !== null) {
                $this->assertAsset($actor['organization_id'], $assetId, 'vehicle_document');
            }
            $vehicle = DB::table('vehicles')->where('organization_id', $actor['organization_id'])->where('id', $vehicleId)->lockForUpdate()->first();
            if ($vehicle === null) {
                throw ResourceDomainException::notFound();
            }

            $row = $this->replaceDocumentLocked($actor, $vehicleId, $documentType, $validUntil, $assetId, $requestId, true);
            if ($row === null) {
                throw ResourceDomainException::rule('A vehicle document replacement requires a date or asset.');
            }

            return $this->presentDocument($row);
        });
    }

    public function etag(array $vehicle): string
    {
        return '"'.hash('sha256', json_encode($vehicle, JSON_THROW_ON_ERROR)).'"';
    }

    private function archiveState(string $sessionId, string $vehicleId, string $requestId, ?string $reason, bool $archive): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $permission = $archive ? 'vehicles.archive' : 'vehicles.restore';
        $action = $archive ? 'vehicle.archived' : 'vehicle.restored';

        return DB::transaction(function () use ($sessionId, $snapshot, $vehicleId, $requestId, $reason, $archive, $permission, $action): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $snapshot['organization_id'], $permission);
            $row = DB::table('vehicles')->where('organization_id', $actor['organization_id'])->where('id', $vehicleId)->lockForUpdate()->first();
            if ($row === null) {
                throw ResourceDomainException::notFound();
            }
            $current = $row->archived_at !== null;
            if ($current === $archive) {
                return $this->present($row);
            }
            if (! $archive) {
                $this->assertIdentityAvailable(
                    $actor['organization_id'],
                    (string) $row->registration_number_normalized,
                    $row->vin_normalized === null ? null : (string) $row->vin_normalized,
                    $vehicleId,
                );
            }

            DB::table('vehicles')->where('id', $vehicleId)->update([
                'archived_at' => $archive ? now() : null,
                'archived_by_user_id' => $archive ? $actor['user_id'] : null,
                'updated_at' => now(),
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                $action, 'vehicle', $vehicleId, $requestId,
                ['fields' => ['archived_at'], 'state' => $current ? 'archived' : 'active', 'archived' => $current],
                ['fields' => ['archived_at'], 'state' => $archive ? 'archived' : 'active', 'archived' => $archive],
                $reason,
            );

            return $this->present(DB::table('vehicles')->where('id', $vehicleId)->firstOrFail());
        });
    }

    /** @param array<string,mixed> $input */
    private function assertRelations(string $organizationId, array $input): void
    {
        if (array_key_exists('category_ids', $input)) {
            $ids = array_values(array_unique(array_map('strval', (array) $input['category_ids'])));
            $count = $ids === [] ? 0 : DB::table('driving_categories')->whereIn('id', $ids)->where('active', true)->count();
            if ($count !== count($ids)) {
                throw ResourceDomainException::rule('Vehicle category selection contains an unknown or inactive category.');
            }
        }
        if (array_key_exists('location_ids', $input)) {
            $ids = array_values(array_unique(array_map('strval', (array) $input['location_ids'])));
            $count = $ids === [] ? 0 : DB::table('locations')
                ->where('organization_id', $organizationId)
                ->whereIn('id', $ids)
                ->whereNull('archived_at')
                ->count();
            if ($count !== count($ids)) {
                throw ResourceDomainException::rule('Vehicle location selection contains an unavailable tenant location.');
            }
        }
    }

    /** @param array<string,mixed> $input */
    private function syncRelations(string $organizationId, string $vehicleId, array $input): void
    {
        if (array_key_exists('category_ids', $input)) {
            DB::table('vehicle_category_assignments')->where('vehicle_id', $vehicleId)->delete();
            foreach (array_values(array_unique(array_map('strval', (array) $input['category_ids']))) as $id) {
                DB::table('vehicle_category_assignments')->insert(['vehicle_id' => $vehicleId, 'driving_category_id' => $id]);
            }
        }
        if (array_key_exists('location_ids', $input)) {
            DB::table('vehicle_location_assignments')->where('organization_id', $organizationId)->where('vehicle_id', $vehicleId)->delete();
            foreach (array_values(array_unique(array_map('strval', (array) $input['location_ids']))) as $id) {
                DB::table('vehicle_location_assignments')->insert(['organization_id' => $organizationId, 'vehicle_id' => $vehicleId, 'location_id' => $id]);
            }
        }
    }

    private function assertIdentityAvailable(string $organizationId, string $registration, ?string $vin, ?string $ignoreId): void
    {
        $registrationQuery = DB::table('vehicles')
            ->where('organization_id', $organizationId)
            ->where('registration_number_normalized', $registration)
            ->whereNull('archived_at');
        if ($ignoreId !== null) {
            $registrationQuery->where('id', '<>', $ignoreId);
        }
        if ($registrationQuery->exists()) {
            throw ResourceDomainException::conflict('Registration number is already used by a current vehicle.');
        }

        if ($vin !== null) {
            $vinQuery = DB::table('vehicles')->where('organization_id', $organizationId)->where('vin_normalized', $vin);
            if ($ignoreId !== null) {
                $vinQuery->where('id', '<>', $ignoreId);
            }
            if ($vinQuery->exists()) {
                throw ResourceDomainException::conflict('VIN is already present in organization history.');
            }
        }
    }

    private function assertAsset(string $organizationId, mixed $assetId, string $purpose): void
    {
        if ($assetId === null) {
            return;
        }
        if (! is_string($assetId) || ! DB::table('file_assets')
            ->where('id', $assetId)
            ->where('organization_id', $organizationId)
            ->where('purpose', $purpose)
            ->where('status', 'ready')
            ->exists()) {
            throw ResourceDomainException::rule('Private asset is not ready for the required tenant purpose.');
        }
    }

    private function registration(string $value): string
    {
        $normalized = mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
        if ($normalized === '' || strlen($normalized) > 32) {
            throw ResourceDomainException::rule('Invalid registration number.');
        }

        return $normalized;
    }

    private function vin(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $normalized = mb_strtoupper(trim((string) $value));
        if (preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $normalized) !== 1) {
            throw ResourceDomainException::rule('VIN must contain 17 valid characters.');
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int}  $actor
     */
    private function replaceDocumentLocked(
        array $actor,
        string $vehicleId,
        string $documentType,
        ?string $validUntil,
        ?string $assetId,
        string $requestId,
        bool $emitAudit,
    ): ?object {
        $current = DB::table('vehicle_documents')
            ->where('organization_id', $actor['organization_id'])
            ->where('vehicle_id', $vehicleId)
            ->where('document_type', $documentType)
            ->whereNull('superseded_at')
            ->lockForUpdate()
            ->first();

        if ($current !== null) {
            DB::table('vehicle_documents')->where('id', $current->id)->update([
                'superseded_at' => now(),
                'superseded_by_user_id' => $actor['user_id'],
                'supersession_reason' => 'replacement',
            ]);
        }

        $replacement = null;
        if ($validUntil !== null || $assetId !== null) {
            $id = (string) Str::uuid7();
            DB::table('vehicle_documents')->insert([
                'id' => $id,
                'organization_id' => $actor['organization_id'],
                'vehicle_id' => $vehicleId,
                'document_type' => $documentType,
                'valid_until' => $validUntil,
                'asset_id' => $assetId,
                'created_at' => now(),
                'created_by_user_id' => $actor['user_id'],
            ]);
            $replacement = DB::table('vehicle_documents')->where('id', $id)->firstOrFail();
        }

        if ($emitAudit) {
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'vehicle.document.replaced', 'vehicle', $vehicleId, $requestId,
                ['fields' => ['valid_until', 'asset_id'], 'state' => $current === null ? 'absent' : 'current', 'document_type' => $documentType],
                ['fields' => ['valid_until', 'asset_id'], 'state' => $replacement === null ? 'cleared' : 'current', 'document_type' => $documentType],
            );
        }

        return $replacement;
    }

    /** @return array<string,mixed> */
    private function present(object $row): array
    {
        $categoryIds = DB::table('vehicle_category_assignments')->where('vehicle_id', $row->id)
            ->pluck('driving_category_id')->map(static fn ($v): string => (string) $v)->all();
        $locationIds = DB::table('vehicle_location_assignments')->where('organization_id', $row->organization_id)->where('vehicle_id', $row->id)
            ->pluck('location_id')->map(static fn ($v): string => (string) $v)->all();
        $documents = DB::table('vehicle_documents')->where('organization_id', $row->organization_id)->where('vehicle_id', $row->id)
            ->whereNull('superseded_at')->get()->keyBy('document_type');

        return [
            'id' => (string) $row->id,
            'registration_number' => (string) $row->registration_number_normalized,
            'side_number' => $row->side_number === null ? null : (string) $row->side_number,
            'make' => (string) $row->make,
            'model' => (string) $row->model,
            'production_year' => $row->production_year === null ? null : (int) $row->production_year,
            'engine_capacity_cm3' => $row->engine_capacity_cm3 === null ? null : (int) $row->engine_capacity_cm3,
            'vin' => $row->vin_normalized === null ? null : (string) $row->vin_normalized,
            'category_ids' => array_values($categoryIds),
            'location_ids' => array_values($locationIds),
            'next_inspection_at' => isset($documents['technical_inspection']) ? (string) $documents['technical_inspection']->valid_until : null,
            'oc_valid_until' => isset($documents['oc_insurance']) ? (string) $documents['oc_insurance']->valid_until : null,
            'ac_valid_until' => isset($documents['ac_insurance']) ? (string) $documents['ac_insurance']->valid_until : null,
            'photo_asset_id' => $row->photo_asset_id === null ? null : (string) $row->photo_asset_id,
            'archived_at' => $row->archived_at === null ? null : (string) $row->archived_at,
        ];
    }

    /** @return array<string,mixed> */
    private function presentDocument(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'document_type' => (string) $row->document_type,
            'valid_until' => $row->valid_until === null ? null : (string) $row->valid_until,
            'asset_id' => $row->asset_id === null ? null : (string) $row->asset_id,
        ];
    }
}
