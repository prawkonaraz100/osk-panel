<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class AuthRegistrationCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_registration_atomically_materializes_self_service_owner_terms_marketing_and_structured_address_without_session(): void
    {
        $termsId = $this->legalDocument('terms', 'terms-v1');
        $marketingId = $this->legalDocument('marketing_consent', 'marketing-v1');
        $pkkBefore = DB::table('pkk_integration_settings')->count();

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Anna',
            'last_name' => 'Nowak',
            'email' => '  OWNER@EXAMPLE.TEST  ',
            'password' => 'Correct-Horse-42!',
            'organization_name' => 'OSK Bezpieczny Start',
            'nip' => '1234567890',
            'phone' => '+48 500 600 700',
            'accepted_terms_version' => 'terms-v1',
            'marketing_consent' => true,
            'marketing_consent_version' => 'marketing-v1',
            'company_address' => [
                'street' => 'Długa',
                'house_number' => '10',
                'unit_number' => '2',
                'postal_code' => '00-001',
                'city' => 'Warszawa',
                'country_code' => 'pl',
            ],
        ])->assertCreated();

        $identifier = DB::table('auth_login_identifiers')
            ->where('identifier_normalized', 'owner@example.test')
            ->whereNull('revoked_at')
            ->firstOrFail();
        $userId = (string) $identifier->user_id;
        $user = DB::table('users')->where('id', $userId)->firstOrFail();
        $this->assertSame('Anna', (string) $user->first_name);
        $this->assertSame('Nowak', (string) $user->last_name);
        $this->assertTrue(Hash::check('Correct-Horse-42!', (string) $user->password_hash));
        $this->assertTrue((bool) $identifier->is_primary_for_type);
        $this->assertNull($identifier->verified_at);

        $passwordAuthority = DB::table('user_password_management')->where('user_id', $userId)->firstOrFail();
        $this->assertSame('self_service', (string) $passwordAuthority->management_mode);
        $this->assertNull($passwordAuthority->managing_organization_id);
        $this->assertSame(1, (int) $passwordAuthority->credential_version);
        $this->assertNotNull($passwordAuthority->password_changed_at);

        $membership = DB::table('organization_memberships')->where('user_id', $userId)->firstOrFail();
        $organizationId = (string) $membership->organization_id;
        $membershipId = (string) $membership->id;
        $this->assertTrue((bool) $membership->is_owner);
        $this->assertSame('Owner', (string) $membership->role_template_code);
        $this->assertSame('core-v1-2026-09-05', (string) $membership->role_template_catalog_version);
        $this->assertSame(1, (int) $membership->version);
        $this->assertSame(1, (int) $membership->authorization_version);

        $organization = DB::table('organizations')->where('id', $organizationId)->firstOrFail();
        $this->assertSame('OSK Bezpieczny Start', (string) $organization->name);
        $this->assertSame('1234567890', (string) $organization->nip);
        $this->assertSame('+48 500 600 700', (string) $organization->phone);
        $this->assertSame('active', (string) $organization->status);
        $this->assertSame(1, (int) DB::table('organization_settings')->where('organization_id', $organizationId)->value('version'));

        $address = DB::table('organization_contact_addresses')->where('organization_id', $organizationId)->firstOrFail();
        $this->assertSame('Długa', (string) $address->street);
        $this->assertSame('10', (string) $address->house_number);
        $this->assertSame('2', (string) $address->unit_number);
        $this->assertSame('00-001', (string) $address->postal_code);
        $this->assertSame('Warszawa', (string) $address->city_name);
        $this->assertSame('PL', (string) $address->country_code);

        $permissionCount = DB::table('permissions')->count();
        $this->assertSame($permissionCount, DB::table('membership_permissions')->where('membership_id', $membershipId)->count());
        $this->assertSame($permissionCount, DB::table('membership_permissions')->where('membership_id', $membershipId)->where('granted', true)->count());
        $this->assertSame($permissionCount, DB::table('membership_permission_scopes')->where('membership_id', $membershipId)->count());

        foreach (['organization.view', 'organization.members.manage', 'staff.permissions.manage', 'sessions.manage.organization'] as $permission) {
            $this->assertTrue(DB::table('membership_permissions')
                ->where('membership_id', $membershipId)
                ->where('permission_code', $permission)
                ->where('granted', true)
                ->exists());
            $this->assertTrue(DB::table('membership_permission_scopes')
                ->where('membership_id', $membershipId)
                ->where('permission_code', $permission)
                ->where('scope_code', 'organization')
                ->exists());
        }

        $accepted = DB::table('terms_acceptances')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->pluck('legal_document_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        sort($accepted);
        $expectedDocuments = [$termsId, $marketingId];
        sort($expectedDocuments);
        $this->assertSame($expectedDocuments, $accepted);

        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', $userId)->count());
        $this->assertSame($pkkBefore, DB::table('pkk_integration_settings')->count());

        $audit = DB::table('audit_logs')
            ->where('organization_id', $organizationId)
            ->where('action', 'auth.registration.completed')
            ->firstOrFail();
        $this->assertSame($membershipId, (string) $audit->actor_organization_membership_id);
        $this->assertSame($userId, (string) $audit->actor_user_id);

        $serializedEvidence = implode('|', [
            (string) $audit->before_redacted_json,
            (string) $audit->after_redacted_json,
            (string) DB::table('outbox_messages')
                ->where('event_type', 'auth.registration.completed')
                ->where('organization_id', $organizationId)
                ->value('payload'),
        ]);
        $this->assertStringNotContainsString('Correct-Horse-42!', $serializedEvidence);
        $this->assertStringNotContainsString((string) $user->password_hash, $serializedEvidence);
        $this->assertStringNotContainsString('owner@example.test', $serializedEvidence);
    }

    public function test_marketing_false_records_only_terms_and_registration_creates_no_implicit_marketing_authority(): void
    {
        $termsId = $this->legalDocument('terms', 'terms-v1');
        $marketingId = $this->legalDocument('marketing_consent', 'marketing-v1');

        $this->postJson('/api/v1/auth/register', [
            ...$this->basePayload(),
            'marketing_consent' => false,
            'marketing_consent_version' => null,
        ])->assertCreated();

        $organizationId = (string) DB::table('organizations')->where('name', 'OSK Registration Test')->value('id');
        $accepted = DB::table('terms_acceptances')
            ->where('organization_id', $organizationId)
            ->pluck('legal_document_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $this->assertSame([$termsId], $accepted);
        $this->assertNotContains($marketingId, $accepted);
    }

    public function test_duplicate_normalized_email_rolls_back_second_registration_without_partial_state(): void
    {
        $this->legalDocument('terms', 'terms-v1');

        $this->postJson('/api/v1/auth/register', $this->basePayload())->assertCreated();

        $organizations = DB::table('organizations')->count();
        $users = DB::table('users')->count();
        $memberships = DB::table('organization_memberships')->count();

        $duplicate = $this->basePayload();
        $duplicate['email'] = 'OWNER@EXAMPLE.TEST';
        $duplicate['organization_name'] = 'Should Roll Back';

        $this->postJson('/api/v1/auth/register', $duplicate)->assertConflict();

        $this->assertSame($organizations, DB::table('organizations')->count());
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame($memberships, DB::table('organization_memberships')->count());
        $this->assertFalse(DB::table('organizations')->where('name', 'Should Roll Back')->exists());
    }

    public function test_unknown_or_future_legal_document_versions_fail_before_partial_registration(): void
    {
        $this->legalDocument('terms', 'future-terms', now()->addDay(), now()->addDay());

        $unknown = $this->basePayload();
        $unknown['accepted_terms_version'] = 'unknown';
        $this->postJson('/api/v1/auth/register', $unknown)->assertUnprocessable();
        $this->assertSame(0, DB::table('organizations')->count());

        $future = $this->basePayload();
        $future['accepted_terms_version'] = 'future-terms';
        $this->postJson('/api/v1/auth/register', $future)->assertUnprocessable();
        $this->assertSame(0, DB::table('organizations')->count());

        $this->legalDocument('terms', 'terms-v1');
        $this->legalDocument('marketing_consent', 'future-marketing', now()->addDay(), now()->addDay());

        $futureMarketing = $this->basePayload();
        $futureMarketing['marketing_consent'] = true;
        $futureMarketing['marketing_consent_version'] = 'future-marketing';
        $this->postJson('/api/v1/auth/register', $futureMarketing)->assertUnprocessable();
        $this->assertSame(0, DB::table('organizations')->count());
    }

    public function test_registration_contract_rejects_unversioned_marketing_legacy_address_and_incomplete_structured_address(): void
    {
        $this->legalDocument('terms', 'terms-v1');
        $this->legalDocument('marketing_consent', 'marketing-v1');

        $missingMarketingVersion = $this->basePayload();
        $missingMarketingVersion['marketing_consent'] = true;
        $this->postJson('/api/v1/auth/register', $missingMarketingVersion)->assertUnprocessable();

        $versionWithoutConsent = $this->basePayload();
        $versionWithoutConsent['marketing_consent'] = false;
        $versionWithoutConsent['marketing_consent_version'] = 'marketing-v1';
        $this->postJson('/api/v1/auth/register', $versionWithoutConsent)->assertUnprocessable();

        $legacyAddress = $this->basePayload();
        $legacyAddress['address'] = 'Długa 10, Warszawa';
        $this->postJson('/api/v1/auth/register', $legacyAddress)->assertUnprocessable();

        $incomplete = $this->basePayload();
        $incomplete['company_address'] = ['street' => 'Długa'];
        $this->postJson('/api/v1/auth/register', $incomplete)->assertUnprocessable();

        $this->assertSame(0, DB::table('organizations')->count());
        $this->assertSame(0, DB::table('users')->count());
    }

    /** @return array<string,mixed> */
    private function basePayload(): array
    {
        return [
            'first_name' => 'Anna',
            'last_name' => 'Nowak',
            'email' => 'owner@example.test',
            'password' => 'Correct-Horse-42!',
            'organization_name' => 'OSK Registration Test',
            'accepted_terms_version' => 'terms-v1',
            'marketing_consent' => false,
        ];
    }

    private function legalDocument(
        string $type,
        string $version,
        mixed $publishedAt = null,
        mixed $effectiveFrom = null,
    ): string {
        $id = (string) Str::uuid7();
        $publishedAt ??= now()->subDay();
        $effectiveFrom ??= now()->subDay();

        DB::table('legal_documents')->insert([
            'id' => $id,
            'document_type' => $type,
            'version' => $version,
            'content_hash' => hash('sha256', $type.'|'.$version),
            'storage_asset_id' => null,
            'published_at' => $publishedAt,
            'effective_from' => $effectiveFrom,
            'created_at' => now(),
        ]);

        return $id;
    }
}
