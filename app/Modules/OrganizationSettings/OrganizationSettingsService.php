<?php

namespace App\Modules\OrganizationSettings;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\IdentityTenant\TenantAuthorizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class OrganizationSettingsService
{
    private const ALLOWED_FIELDS = [
        'first_name', 'last_name', 'company_name', 'phone', 'address',
    ];

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     * @return array{version:int}
     */
    public function update(string $sessionId, int $expectedVersion, array $changes, string $requestId): array
    {
        $unknown = array_diff(array_keys($changes), self::ALLOWED_FIELDS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown or not-yet-materialized settings field: '.implode(',', $unknown));
        }

        $sessionMembership = $this->authorizer->activeMembershipForSession($sessionId);
        $organizationId = $sessionMembership['organization_id'];
        $actor = $this->authorizer->requireOrganizationPermission(
            $sessionId,
            $organizationId,
            'organization.settings.manage',
        );

        return DB::transaction(function () use (
            $expectedVersion,
            $changes,
            $requestId,
            $organizationId,
            $actor,
        ): array {
            $settings = DB::table('organization_settings')
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if ($settings === null) {
                throw new LogicException('Organization settings aggregate missing.');
            }
            if ((int) $settings->version !== $expectedVersion) {
                throw new LogicException('Stale organization settings version.');
            }

            $changedFields = [];

            $userUpdates = [];
            foreach (['first_name', 'last_name'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $userUpdates[$field] = $changes[$field];
                    $changedFields[] = $field;
                }
            }
            if ($userUpdates !== []) {
                $userUpdates['updated_at'] = now();
                DB::table('users')->where('id', $actor['user_id'])->update($userUpdates);
            }

            $organizationUpdates = [];
            if (array_key_exists('company_name', $changes)) {
                $organizationUpdates['name'] = $changes['company_name'];
                $changedFields[] = 'company_name';
            }
            if (array_key_exists('phone', $changes)) {
                $organizationUpdates['phone'] = $changes['phone'];
                $changedFields[] = 'phone';
            }
            if ($organizationUpdates !== []) {
                $organizationUpdates['updated_at'] = now();
                DB::table('organizations')->where('id', $organizationId)->update($organizationUpdates);
            }

            if (array_key_exists('address', $changes)) {
                if (! is_array($changes['address'])) {
                    throw new InvalidArgumentException('Address must be an object.');
                }
                $address = $changes['address'];
                foreach (['street', 'house_number', 'postal_code', 'city_name'] as $required) {
                    if (! isset($address[$required]) || ! is_string($address[$required]) || trim($address[$required]) === '') {
                        throw new InvalidArgumentException("Address {$required} is required.");
                    }
                }
                $allowedAddress = [
                    'street', 'house_number', 'unit_number', 'postal_code', 'city_name',
                    'city_reference', 'voivodeship_name', 'country_code',
                ];
                if (array_diff(array_keys($address), $allowedAddress) !== []) {
                    throw new InvalidArgumentException('Unknown address field.');
                }

                DB::table('organization_contact_addresses')->updateOrInsert(
                    ['organization_id' => $organizationId],
                    [
                        'street' => $address['street'],
                        'house_number' => $address['house_number'],
                        'unit_number' => $address['unit_number'] ?? null,
                        'postal_code' => $address['postal_code'],
                        'city_name' => $address['city_name'],
                        'city_reference' => $address['city_reference'] ?? null,
                        'voivodeship_name' => $address['voivodeship_name'] ?? null,
                        'country_code' => $address['country_code'] ?? 'PL',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
                $changedFields[] = 'address';
            }

            if ($changedFields === []) {
                return ['version' => (int) $settings->version];
            }

            $newVersion = (int) $settings->version + 1;
            DB::table('organization_settings')->where('organization_id', $organizationId)->update([
                'version' => $newVersion,
                'updated_at' => now(),
            ]);

            sort($changedFields);
            $this->auditOutbox->recordOrganizationEvent(
                $organizationId,
                $actor['id'],
                $actor['user_id'],
                'organization.settings.updated',
                'organization',
                $organizationId,
                $requestId,
                ['fields' => $changedFields, 'version' => (int) $settings->version],
                ['fields' => $changedFields, 'version' => $newVersion],
            );

            return ['version' => $newVersion];
        });
    }
}
