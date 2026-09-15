<?php

namespace App\Modules\IdentityTenant;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\ResourcesCore\ResourceDomainException;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Yaml\Yaml;

final class RegistrationService
{
    private const OWNER_TEMPLATE = 'Owner';

    private const OWNER_CATALOG_VERSION = 'core-v1-2026-09-05';

    /** @var list<string> */
    private const OWNER_BASELINE = [
        'organization.view',
        'organization.members.manage',
        'staff.permissions.manage',
        'sessions.manage.organization',
    ];

    public function __construct(
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array{organization_id:string,user_id:string,membership_id:string}
     */
    public function register(
        array $input,
        string $requestId,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        $email = $this->normalizeEmail((string) $input['email']);
        $firstName = $this->requiredText((string) $input['first_name'], 120, 'First name');
        $lastName = $this->requiredText((string) $input['last_name'], 120, 'Last name');
        $organizationName = $this->requiredText((string) $input['organization_name'], 255, 'Organization name');
        $termsVersion = $this->requiredText((string) $input['accepted_terms_version'], 64, 'Terms version');
        $marketingConsent = (bool) ($input['marketing_consent'] ?? false);
        $marketingVersion = isset($input['marketing_consent_version'])
            ? trim((string) $input['marketing_consent_version'])
            : null;

        if ($marketingConsent && ($marketingVersion === null || $marketingVersion === '')) {
            throw ResourceDomainException::rule('Marketing consent version is required when marketing consent is granted.');
        }
        if (! $marketingConsent && $marketingVersion !== null && $marketingVersion !== '') {
            throw ResourceDomainException::rule('Marketing consent version is allowed only when marketing consent is granted.');
        }

        $commandTime = now();

        try {
            return DB::transaction(function () use (
                $input,
                $requestId,
                $ipAddress,
                $userAgent,
                $email,
                $firstName,
                $lastName,
                $organizationName,
                $termsVersion,
                $marketingConsent,
                $marketingVersion,
                $commandTime,
            ): array {
                if (DB::table('auth_login_identifiers')
                    ->where('identifier_normalized', $email)
                    ->whereNull('revoked_at')
                    ->exists()) {
                    throw ResourceDomainException::conflict('Registration identity already exists.');
                }

                $termsDocumentId = $this->resolveLegalDocument('terms', $termsVersion, $commandTime);
                $marketingDocumentId = $marketingConsent
                    ? $this->resolveLegalDocument('marketing_consent', (string) $marketingVersion, $commandTime)
                    : null;

                $organizationId = (string) Str::uuid7();
                $userId = (string) Str::uuid7();
                $membershipId = (string) Str::uuid7();

                DB::table('organizations')->insert([
                    'id' => $organizationId,
                    'name' => $organizationName,
                    'nip' => $this->optionalText($input['nip'] ?? null, 16),
                    'phone' => $this->optionalText($input['phone'] ?? null, 40),
                    'status' => 'active',
                    'created_at' => $commandTime,
                    'updated_at' => $commandTime,
                ]);

                DB::table('organization_settings')->insert([
                    'organization_id' => $organizationId,
                    'version' => 1,
                    'created_at' => $commandTime,
                    'updated_at' => $commandTime,
                ]);

                $this->insertCompanyAddress($organizationId, $input['company_address'] ?? null, $commandTime);

                DB::table('users')->insert([
                    'id' => $userId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'password_hash' => Hash::make((string) $input['password']),
                    'status' => 'active',
                    'created_at' => $commandTime,
                    'updated_at' => $commandTime,
                ]);

                DB::table('auth_login_identifiers')->insert([
                    'id' => (string) Str::uuid7(),
                    'user_id' => $userId,
                    'identifier_type' => 'email',
                    'identifier_normalized' => $email,
                    'is_primary_for_type' => true,
                    'verified_at' => null,
                    'created_at' => $commandTime,
                    'revoked_at' => null,
                ]);

                DB::table('user_password_management')->insert([
                    'user_id' => $userId,
                    'management_mode' => 'self_service',
                    'managing_organization_id' => null,
                    'credential_version' => 1,
                    'password_changed_at' => $commandTime,
                    'created_at' => $commandTime,
                    'updated_at' => $commandTime,
                ]);

                DB::table('organization_memberships')->insert([
                    'id' => $membershipId,
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'status' => 'active',
                    'is_owner' => true,
                    'role_template_code' => self::OWNER_TEMPLATE,
                    'role_template_catalog_version' => self::OWNER_CATALOG_VERSION,
                    'version' => 1,
                    'authorization_version' => 1,
                    'created_at' => $commandTime,
                    'updated_at' => $commandTime,
                ]);

                $this->materializeOwnerPermissions($membershipId, $commandTime);
                $this->assertOwnerBaseline($membershipId);

                $this->insertAcceptance(
                    $organizationId,
                    $userId,
                    $termsDocumentId,
                    $requestId,
                    $ipAddress,
                    $userAgent,
                    $commandTime,
                );

                if ($marketingDocumentId !== null) {
                    $this->insertAcceptance(
                        $organizationId,
                        $userId,
                        $marketingDocumentId,
                        $requestId,
                        $ipAddress,
                        $userAgent,
                        $commandTime,
                    );
                }

                $fields = ['organization', 'user', 'owner_membership', 'terms_acceptance'];
                if ($marketingDocumentId !== null) {
                    $fields[] = 'marketing_consent_acceptance';
                }

                $this->auditOutbox->recordOrganizationEvent(
                    $organizationId,
                    $membershipId,
                    $userId,
                    'auth.registration.completed',
                    'organization',
                    $organizationId,
                    $requestId,
                    ['fields' => [], 'state' => 'absent'],
                    ['fields' => $fields, 'state' => 'active', 'membership_id' => $membershipId],
                );

                return [
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'membership_id' => $membershipId,
                ];
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23505') {
                throw ResourceDomainException::conflict('Registration identity already exists.');
            }

            throw $exception;
        }
    }

    private function normalizeEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || mb_strlen($email) > 320 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ResourceDomainException::rule('Registration e-mail is invalid.');
        }

        return $email;
    }

    private function requiredText(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw ResourceDomainException::rule($label.' is invalid.');
        }

        return $value;
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }
        if (mb_strlen($normalized) > $maxLength) {
            throw ResourceDomainException::rule('Optional registration field is too long.');
        }

        return $normalized;
    }

    private function resolveLegalDocument(string $documentType, string $version, CarbonInterface $commandTime): string
    {
        $ids = DB::table('legal_documents')
            ->where('document_type', $documentType)
            ->where('version', $version)
            ->where('published_at', '<=', $commandTime)
            ->where(function ($query) use ($commandTime): void {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $commandTime);
            })
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if (count($ids) !== 1) {
            throw ResourceDomainException::rule("Exact effective {$documentType} document version is required.");
        }

        return $ids[0];
    }

    private function insertCompanyAddress(string $organizationId, mixed $address, CarbonInterface $commandTime): void
    {
        if ($address === null) {
            return;
        }
        if (! is_array($address)) {
            throw ResourceDomainException::rule('Company address must be structured.');
        }

        $street = $this->requiredText((string) ($address['street'] ?? ''), 255, 'Street');
        $houseNumber = $this->requiredText((string) ($address['house_number'] ?? ''), 32, 'House number');
        $postalCode = $this->requiredText((string) ($address['postal_code'] ?? ''), 20, 'Postal code');
        $city = $this->requiredText((string) ($address['city'] ?? ''), 160, 'City');
        $countryCode = strtoupper(trim((string) ($address['country_code'] ?? 'PL')));
        if (strlen($countryCode) !== 2 || preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            throw ResourceDomainException::rule('Country code must be a two-letter code.');
        }

        DB::table('organization_contact_addresses')->insert([
            'organization_id' => $organizationId,
            'street' => $street,
            'house_number' => $houseNumber,
            'unit_number' => $this->optionalText($address['unit_number'] ?? null, 32),
            'postal_code' => $postalCode,
            'city_name' => $city,
            'country_code' => $countryCode,
            'created_at' => $commandTime,
            'updated_at' => $commandTime,
        ]);
    }

    private function materializeOwnerPermissions(string $membershipId, CarbonInterface $commandTime): void
    {
        $document = Yaml::parseFile(base_path('specs/security/permissions.yml'));
        if (! is_array($document)) {
            throw new LogicException('Permission catalog must decode to a map.');
        }

        $meta = $document['meta'] ?? null;
        $templates = $document['role_templates'] ?? null;
        $permissionGroups = $document['permission_groups'] ?? null;
        $scopeProfiles = $document['scope_profiles'] ?? null;
        $profileByPermission = $document['permission_scope_profile_by_code'] ?? null;

        if (! is_array($meta)
            || ($meta['role_template_catalog_version'] ?? null) !== self::OWNER_CATALOG_VERSION
            || ! is_array($templates)
            || ! is_array($permissionGroups)
            || ! is_array($scopeProfiles)
            || ! is_array($profileByPermission)) {
            throw new LogicException('Owner permission catalog authority is incomplete.');
        }

        $owner = $templates[self::OWNER_TEMPLATE] ?? null;
        if (! is_array($owner) || ($owner['strategy'] ?? null) !== 'all_core_permissions_known_in_this_catalog_version') {
            throw new LogicException('Owner template authority is not the expected all-core strategy.');
        }

        /** @var list<string> $permissionCodes */
        $permissionCodes = [];
        foreach ($permissionGroups as $codes) {
            if (! is_array($codes)) {
                throw new LogicException('Permission group must be a list.');
            }
            foreach ($codes as $code) {
                if (! is_string($code) || $code === '') {
                    throw new LogicException('Permission code must be a non-empty string.');
                }
                $permissionCodes[] = $code;
            }
        }

        $permissionCodes = array_values(array_unique($permissionCodes));
        sort($permissionCodes);

        foreach ($permissionCodes as $permissionCode) {
            $profileCode = $profileByPermission[$permissionCode] ?? null;
            if (! is_string($profileCode)) {
                throw new LogicException("Missing scope profile for {$permissionCode}.");
            }

            $profile = $scopeProfiles[$profileCode] ?? null;
            $allowedScopes = is_array($profile) ? ($profile['allowed_scopes'] ?? null) : null;
            if (! is_array($allowedScopes)) {
                throw new LogicException("Invalid scope profile {$profileCode}.");
            }

            $scopeCode = in_array('organization', $allowedScopes, true)
                ? 'organization'
                : (in_array('own', $allowedScopes, true) ? 'own' : null);
            if ($scopeCode === null) {
                throw new LogicException("Owner template cannot resolve a safe scope for {$permissionCode}.");
            }

            if (! DB::table('permissions')->where('code', $permissionCode)->exists()
                || ! DB::table('permission_scope_options')
                    ->where('permission_code', $permissionCode)
                    ->where('scope_code', $scopeCode)
                    ->exists()) {
                throw new LogicException("Permission catalog is not materialized for {$permissionCode}.");
            }

            DB::table('membership_permissions')->insert([
                'membership_id' => $membershipId,
                'permission_code' => $permissionCode,
                'granted' => true,
                'created_at' => $commandTime,
            ]);
            DB::table('membership_permission_scopes')->insert([
                'membership_id' => $membershipId,
                'permission_code' => $permissionCode,
                'scope_code' => $scopeCode,
                'created_at' => $commandTime,
            ]);
        }
    }

    private function assertOwnerBaseline(string $membershipId): void
    {
        foreach (self::OWNER_BASELINE as $permission) {
            if (! DB::table('membership_permissions')
                ->where('membership_id', $membershipId)
                ->where('permission_code', $permission)
                ->where('granted', true)
                ->exists()
                || ! DB::table('membership_permission_scopes')
                    ->where('membership_id', $membershipId)
                    ->where('permission_code', $permission)
                    ->where('scope_code', 'organization')
                    ->exists()) {
                throw new LogicException('Initial Owner protected permission baseline is incomplete.');
            }
        }
    }

    private function insertAcceptance(
        string $organizationId,
        string $userId,
        string $legalDocumentId,
        string $requestId,
        ?string $ipAddress,
        ?string $userAgent,
        CarbonInterface $commandTime,
    ): void {
        $ipAddress = $ipAddress === null ? '' : trim($ipAddress);
        $userAgent = $userAgent === null ? '' : trim($userAgent);

        DB::table('terms_acceptances')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'legal_document_id' => $legalDocumentId,
            'accepted_at' => $commandTime,
            'ip_hash' => $ipAddress === '' ? null : hash('sha256', $ipAddress),
            'user_agent' => $userAgent === '' ? null : mb_substr($userAgent, 0, 512),
            'request_id' => $requestId,
        ]);
    }
}
