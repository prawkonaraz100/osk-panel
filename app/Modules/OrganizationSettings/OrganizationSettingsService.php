<?php

namespace App\Modules\OrganizationSettings;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class OrganizationSettingsService
{
    private const ALLOWED_FIELDS = [
        'first_name', 'last_name', 'company_name', 'phone', 'address',
    ];

    private const ORGANIZATION_UPDATE_FIELDS = [
        'name', 'nip', 'phone', 'timezone',
    ];

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /** @return array{id:string,name:string,nip:?string,phone:?string,timezone:string,status:string} */
    public function organization(string $sessionId): array
    {
        $membership = $this->authorizer->activeMembershipForSession($sessionId);
        $this->authorizer->requireOrganizationPermission(
            $sessionId,
            $membership['organization_id'],
            'organization.view',
        );

        return $this->organizationProjection($membership['organization_id']);
    }

    /**
     * @return array{
     *   version:int,
     *   basic_data:array{first_name:string,last_name:string,email:string},
     *   company_data:array{company_name:string,street:?string,house_number:?string,unit_number:?string,city:?string,postal_code:?string,phone:?string},
     *   accepted_terms:list<array{version:string,accepted_at:string,accepted_by_user_id:string,document_url:null}>
     * }
     */
    public function settings(string $sessionId): array
    {
        $membership = $this->authorizer->activeMembershipForSession($sessionId);
        $this->authorizer->requireOrganizationPermission(
            $sessionId,
            $membership['organization_id'],
            'organization.view',
        );

        return $this->settingsProjection(
            $membership['organization_id'],
            $membership['user_id'],
        );
    }

    /** @return list<array{version:string,accepted_at:string,accepted_by_user_id:string,document_url:null}> */
    public function acceptedTerms(string $sessionId): array
    {
        $membership = $this->authorizer->activeMembershipForSession($sessionId);
        $this->authorizer->requireOrganizationPermission(
            $sessionId,
            $membership['organization_id'],
            'organization.view',
        );

        return $this->acceptedTermsForOrganization($membership['organization_id']);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array{id:string,name:string,nip:?string,phone:?string,timezone:string,status:string}
     */
    public function updateOrganization(
        string $sessionId,
        int $expectedVersion,
        array $changes,
        string $requestId,
    ): array {
        $unknown = array_values(array_diff(array_keys($changes), self::ORGANIZATION_UPDATE_FIELDS));
        if ($unknown !== []) {
            throw ResourceDomainException::rule('Unknown organization field: '.implode(',', $unknown));
        }
        if ($changes === []) {
            throw ResourceDomainException::rule('At least one organization field is required.');
        }

        return DB::transaction(function () use ($sessionId, $expectedVersion, $changes, $requestId): array {
            $snapshot = $this->authorizer->activeMembershipForSession($sessionId);
            $organizationId = $snapshot['organization_id'];

            $organization = DB::table('organizations')
                ->where('id', $organizationId)
                ->lockForUpdate()
                ->first();
            if ($organization === null) {
                throw ResourceDomainException::notFound('Organization not found.');
            }

            if (DB::table('organization_memberships')
                ->where('id', $snapshot['id'])
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first() === null) {
                throw ResourceDomainException::conflict('Actor membership disappeared during organization mutation.');
            }

            $actor = $this->authorizer->requireOrganizationPermission(
                $sessionId,
                $organizationId,
                'organization.edit',
            );

            $settings = DB::table('organization_settings')
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();
            if ($settings === null) {
                throw ResourceDomainException::conflict('Organization settings aggregate missing.');
            }
            $this->assertVersion((int) $settings->version, $expectedVersion);

            $updates = [];
            $changedFields = [];
            foreach (self::ORGANIZATION_UPDATE_FIELDS as $field) {
                if (array_key_exists($field, $changes)) {
                    $updates[$field] = $changes[$field];
                    $changedFields[] = $field;
                }
            }

            $updates['updated_at'] = now();
            DB::table('organizations')->where('id', $organizationId)->update($updates);

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

            return $this->organizationProjection($organizationId);
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array{
     *   version:int,
     *   basic_data:array{first_name:string,last_name:string,email:string},
     *   company_data:array{company_name:string,street:?string,house_number:?string,unit_number:?string,city:?string,postal_code:?string,phone:?string},
     *   accepted_terms:list<array{version:string,accepted_at:string,accepted_by_user_id:string,document_url:null}>
     * }
     */
    public function update(string $sessionId, int $expectedVersion, array $changes, string $requestId): array
    {
        $unknown = array_values(array_diff(array_keys($changes), self::ALLOWED_FIELDS));
        if ($unknown !== []) {
            throw ResourceDomainException::rule('Unknown provider-neutral settings field: '.implode(',', $unknown));
        }
        if ($changes === []) {
            throw ResourceDomainException::rule('At least one provider-neutral settings field is required.');
        }

        return DB::transaction(function () use ($sessionId, $expectedVersion, $changes, $requestId): array {
            $actorSnapshot = $this->authorizer->activeMembershipForSession($sessionId);
            $organizationId = $actorSnapshot['organization_id'];

            if (DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first() === null) {
                throw ResourceDomainException::notFound('Organization not found.');
            }
            if (DB::table('organization_memberships')
                ->where('id', $actorSnapshot['id'])
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first() === null) {
                throw ResourceDomainException::conflict('Actor membership disappeared during settings mutation.');
            }

            $actor = $this->authorizer->requireOrganizationPermission(
                $sessionId,
                $organizationId,
                'organization.settings.manage',
            );

            $settings = DB::table('organization_settings')
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if ($settings === null) {
                throw ResourceDomainException::conflict('Organization settings aggregate missing.');
            }
            $this->assertVersion((int) $settings->version, $expectedVersion);

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
                    throw ResourceDomainException::rule('Address must be an object.');
                }
                $this->applyPartialAddress($organizationId, $changes['address']);
                $changedFields[] = 'address';
            }

            if ($changedFields === []) {
                return $this->settingsProjection($organizationId, $actor['user_id']);
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

            return $this->settingsProjection($organizationId, $actor['user_id']);
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function applyPartialAddress(string $organizationId, array $changes): void
    {
        $allowed = [
            'street', 'house_number', 'unit_number', 'postal_code', 'city_name',
            'city_reference', 'voivodeship_name', 'country_code',
        ];
        $unknown = array_values(array_diff(array_keys($changes), $allowed));
        if ($unknown !== []) {
            throw ResourceDomainException::rule('Unknown address field: '.implode(',', $unknown));
        }

        $existing = DB::table('organization_contact_addresses')
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->first();

        $values = [
            'street' => $existing?->street,
            'house_number' => $existing?->house_number,
            'unit_number' => $existing?->unit_number,
            'postal_code' => $existing?->postal_code,
            'city_name' => $existing?->city_name,
            'city_reference' => $existing?->city_reference,
            'voivodeship_name' => $existing?->voivodeship_name,
            'country_code' => $existing->country_code ?? 'PL',
        ];

        foreach ($changes as $field => $value) {
            $values[$field] = $value;
        }

        foreach (['street', 'house_number', 'postal_code', 'city_name'] as $required) {
            if (! is_string($values[$required]) || trim($values[$required]) === '') {
                throw ResourceDomainException::rule(
                    "Address {$required} is required when the company address does not yet contain it.",
                );
            }
        }

        $payload = [
            ...$values,
            'updated_at' => now(),
        ];
        if ($existing === null) {
            $payload['created_at'] = now();
        }

        DB::table('organization_contact_addresses')->updateOrInsert(
            ['organization_id' => $organizationId],
            $payload,
        );
    }

    /** @return array{id:string,name:string,nip:?string,phone:?string,timezone:string,status:string} */
    private function organizationProjection(string $organizationId): array
    {
        $row = DB::table('organizations')->where('id', $organizationId)->first();
        if ($row === null) {
            throw ResourceDomainException::notFound('Organization not found.');
        }

        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'nip' => $row->nip === null ? null : (string) $row->nip,
            'phone' => $row->phone === null ? null : (string) $row->phone,
            'timezone' => (string) $row->timezone,
            'status' => (string) $row->status,
        ];
    }

    /**
     * @return array{
     *   version:int,
     *   basic_data:array{first_name:string,last_name:string,email:string},
     *   company_data:array{company_name:string,street:?string,house_number:?string,unit_number:?string,city:?string,postal_code:?string,phone:?string},
     *   accepted_terms:list<array{version:string,accepted_at:string,accepted_by_user_id:string,document_url:null}>
     * }
     */
    private function settingsProjection(string $organizationId, string $userId): array
    {
        $organization = DB::table('organizations')->where('id', $organizationId)->first();
        $settings = DB::table('organization_settings')->where('organization_id', $organizationId)->first();
        $user = DB::table('users')->where('id', $userId)->first();
        $address = DB::table('organization_contact_addresses')
            ->where('organization_id', $organizationId)
            ->first();
        $email = DB::table('auth_login_identifiers')
            ->where('user_id', $userId)
            ->where('identifier_type', 'email')
            ->where('is_primary_for_type', true)
            ->whereNull('revoked_at')
            ->value('identifier_normalized');

        if ($organization === null || $settings === null || $user === null) {
            throw ResourceDomainException::conflict('Organization settings aggregate is incomplete.');
        }
        if (! is_string($email) || trim($email) === '') {
            throw ResourceDomainException::conflict('Current primary e-mail identifier is missing.');
        }

        return [
            'version' => (int) $settings->version,
            'basic_data' => [
                'first_name' => (string) $user->first_name,
                'last_name' => (string) $user->last_name,
                'email' => $email,
            ],
            'company_data' => [
                'company_name' => (string) $organization->name,
                'street' => $address === null ? null : (string) $address->street,
                'house_number' => $address === null ? null : (string) $address->house_number,
                'unit_number' => $address === null || $address->unit_number === null ? null : (string) $address->unit_number,
                'city' => $address === null ? null : (string) $address->city_name,
                'postal_code' => $address === null ? null : (string) $address->postal_code,
                'phone' => $organization->phone === null ? null : (string) $organization->phone,
            ],
            'accepted_terms' => $this->acceptedTermsForOrganization($organizationId),
        ];
    }

    /** @return list<array{version:string,accepted_at:string,accepted_by_user_id:string,document_url:null}> */
    private function acceptedTermsForOrganization(string $organizationId): array
    {
        $rows = DB::table('terms_acceptances as acceptance')
            ->join('legal_documents as document', 'document.id', '=', 'acceptance.legal_document_id')
            ->where('acceptance.organization_id', $organizationId)
            ->orderByDesc('acceptance.accepted_at')
            ->orderByDesc('acceptance.id')
            ->get([
                'document.version as version',
                'acceptance.accepted_at',
                'acceptance.user_id as accepted_by_user_id',
            ]);

        $projection = [];
        foreach ($rows as $row) {
            $projection[] = [
                'version' => (string) $row->version,
                'accepted_at' => CarbonImmutable::parse((string) $row->accepted_at)->utc()->toIso8601String(),
                'accepted_by_user_id' => (string) $row->accepted_by_user_id,
                'document_url' => null,
            ];
        }

        return $projection;
    }

    private function assertVersion(int $currentVersion, int $expectedVersion): void
    {
        if ($currentVersion !== $expectedVersion) {
            throw ResourceDomainException::conflict('Organization settings changed since it was loaded.');
        }
    }
}
