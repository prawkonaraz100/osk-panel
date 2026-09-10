<?php

namespace App\Modules\ResourcesCore;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\IdentityTenant\TenantAuthorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LocationService
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ResourceScopeAuthorizer $scopeAuthorizer,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(string $sessionId): array
    {
        $visibility = $this->scopeAuthorizer->visibility($sessionId, 'locations.view');
        $query = DB::table('locations')
            ->where('organization_id', $visibility['membership']['organization_id'])
            ->orderByRaw('archived_at IS NOT NULL')
            ->orderBy('name');

        if (! $visibility['unrestricted']) {
            if ($visibility['location_ids'] === []) {
                return [];
            }
            $query->whereIn('id', $visibility['location_ids']);
        }

        return array_values($query->get()->map(fn ($row): array => $this->present($row))->all());
    }

    /** @return array<string,mixed> */
    public function get(string $sessionId, string $locationId): array
    {
        $membership = $this->scopeAuthorizer->requireLocationTarget($sessionId, 'locations.view', $locationId);
        $row = DB::table('locations')
            ->where('organization_id', $membership['organization_id'])
            ->where('id', $locationId)
            ->first();

        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $this->present($row);
    }

    /**
     * @param array{type_code:string,name:string,street_and_number:string,postal_code:string,city_reference:string} $input
     * @return array<string,mixed>
     */
    public function create(string $sessionId, array $input, string $requestId): array
    {
        $actor = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $actor, $input, $requestId): array {
            DB::table('organizations')->where('id', $actor['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $actor['organization_id'], 'locations.create');
            $this->assertType($input['type_code']);
            $postalCode = $this->postalCode($input['postal_code']);
            $cityName = $this->cityName($input['city_reference']);
            $id = (string) Str::uuid7();
            $now = now();

            DB::table('locations')->insert([
                'id' => $id,
                'organization_id' => $actor['organization_id'],
                'type_code' => $input['type_code'],
                'name' => trim($input['name']),
                'street_and_number' => trim($input['street_and_number']),
                'postal_code' => $postalCode,
                'city_reference' => trim($input['city_reference']),
                'city_name' => $cityName,
                'voivodeship_name' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'location.created',
                'location',
                $id,
                $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['type_code', 'name', 'street_and_number', 'postal_code', 'city_reference'], 'state' => 'active'],
            );

            return $this->present(DB::table('locations')->where('id', $id)->firstOrFail());
        });
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(string $sessionId, string $locationId, array $input, string $requestId, ?string $expectedTag = null): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $locationId, $input, $requestId, $expectedTag): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $snapshot['organization_id'], 'locations.edit');
            $row = DB::table('locations')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $locationId)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw ResourceDomainException::notFound();
            }
            if ($row->archived_at !== null) {
                throw ResourceDomainException::conflict('Archived location must be restored before editing.');
            }
            $this->assertExpectedTag($row, $expectedTag);

            $updates = [];
            if (array_key_exists('type_code', $input)) {
                $this->assertType((string) $input['type_code']);
                $updates['type_code'] = (string) $input['type_code'];
            }
            foreach (['name', 'street_and_number'] as $field) {
                if (array_key_exists($field, $input)) {
                    $updates[$field] = trim((string) $input[$field]);
                }
            }
            if (array_key_exists('postal_code', $input)) {
                $updates['postal_code'] = $this->postalCode((string) $input['postal_code']);
            }
            if (array_key_exists('city_reference', $input)) {
                $updates['city_reference'] = trim((string) $input['city_reference']);
                $updates['city_name'] = $this->cityName((string) $input['city_reference']);
                $updates['voivodeship_name'] = null;
            }

            if ($updates !== []) {
                $updates['updated_at'] = now();
                DB::table('locations')->where('id', $locationId)->update($updates);
                $this->auditOutbox->recordOrganizationEvent(
                    $actor['organization_id'], $actor['id'], $actor['user_id'],
                    'location.updated', 'location', $locationId, $requestId,
                    ['fields' => array_keys($updates), 'state' => 'active'],
                    ['fields' => array_keys($updates), 'state' => 'active'],
                );
            }

            return $this->present(DB::table('locations')->where('id', $locationId)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function archive(string $sessionId, string $locationId, string $requestId, ?string $reason): array
    {
        return $this->setArchived($sessionId, $locationId, $requestId, $reason, true);
    }

    /** @return array<string,mixed> */
    public function restore(string $sessionId, string $locationId, string $requestId): array
    {
        return $this->setArchived($sessionId, $locationId, $requestId, null, false);
    }

    /** @param array<string,mixed> $location */
    public function etag(array $location): string
    {
        return '"'.hash('sha256', json_encode($location, JSON_THROW_ON_ERROR)).'"';
    }

    /** @return array<string,mixed> */
    private function setArchived(string $sessionId, string $locationId, string $requestId, ?string $reason, bool $archive): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $permission = $archive ? 'locations.archive' : 'locations.restore';
        $action = $archive ? 'location.archived' : 'location.restored';

        return DB::transaction(function () use ($sessionId, $snapshot, $locationId, $requestId, $reason, $archive, $permission, $action): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission($sessionId, $snapshot['organization_id'], $permission);
            $row = DB::table('locations')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $locationId)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw ResourceDomainException::notFound();
            }
            $currentlyArchived = $row->archived_at !== null;
            if ($currentlyArchived === $archive) {
                return $this->present($row);
            }

            DB::table('locations')->where('id', $locationId)->update([
                'archived_at' => $archive ? now() : null,
                'archived_by_user_id' => $archive ? $actor['user_id'] : null,
                'updated_at' => now(),
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                $action, 'location', $locationId, $requestId,
                ['fields' => ['archived_at'], 'state' => $currentlyArchived ? 'archived' : 'active', 'archived' => $currentlyArchived],
                ['fields' => ['archived_at'], 'state' => $archive ? 'archived' : 'active', 'archived' => $archive],
                $reason,
            );

            return $this->present(DB::table('locations')->where('id', $locationId)->firstOrFail());
        });
    }

    private function assertType(string $code): void
    {
        if (! DB::table('location_types')->where('code', $code)->where('active', true)->exists()) {
            throw ResourceDomainException::rule('Unknown or inactive location type.');
        }
    }

    private function postalCode(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{2}-\d{3}$/', $value) !== 1) {
            throw ResourceDomainException::rule('Postal code must use NN-NNN format.');
        }

        return $value;
    }

    private function cityName(string $reference): string
    {
        $value = trim($reference);
        if ($value === '' || mb_strlen($value) > 160) {
            throw ResourceDomainException::rule('City reference must contain a valid display name.');
        }

        return $value;
    }

    private function assertExpectedTag(object $row, ?string $expectedTag): void
    {
        if ($expectedTag === null || trim($expectedTag) === '') {
            return;
        }

        if (! hash_equals($this->etag($this->present($row)), trim($expectedTag))) {
            throw ResourceDomainException::conflict('Location changed since it was loaded.');
        }
    }

    /** @return array<string,mixed> */
    private function present(object $row): array
    {
        $data = get_object_vars($row);

        return [
            'id' => (string) ($data['id'] ?? ''),
            'type_code' => (string) ($data['type_code'] ?? ''),
            'name' => (string) ($data['name'] ?? ''),
            'street_and_number' => (string) ($data['street_and_number'] ?? ''),
            'postal_code' => (string) ($data['postal_code'] ?? ''),
            'city_name' => (string) ($data['city_name'] ?? ''),
            'voivodeship_name' => ($data['voivodeship_name'] ?? null) === null ? null : (string) $data['voivodeship_name'],
            'archived_at' => ($data['archived_at'] ?? null) === null ? null : (string) $data['archived_at'],
        ];
    }
}
