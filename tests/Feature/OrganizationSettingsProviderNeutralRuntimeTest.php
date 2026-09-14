<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class OrganizationSettingsProviderNeutralRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_settings_get_and_patch_work_without_pkk_configuration(): void
    {
        $actor = $this->actorWithEmail();

        $get = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/organization/settings')
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('basic_data.email', 'owner@example.test')
            ->assertJsonPath('company_data.company_name', 'Synthetic OSK');

        $this->assertArrayNotHasKey('pkk_api_data', $get->json());
        $this->assertSame(0, DB::table('pkk_integration_settings')
            ->where('organization_id', $actor['organization_id'])
            ->count());

        $key = (string) Str::uuid7();
        $payload = [
            'basic_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Nowak',
            ],
            'company_data' => [
                'company_name' => 'OSK Bez PKK',
                'street' => 'Testowa',
                'house_number' => '7',
                'unit_number' => null,
                'city' => 'Warszawa',
                'postal_code' => '00-001',
                'phone' => '+48 500 600 700',
            ],
        ];

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeaders(['If-Match' => '"v1"', 'Idempotency-Key' => $key])
            ->patchJson('/api/v1/organization/settings', $payload)
            ->assertOk()
            ->assertJsonPath('version', 2)
            ->assertJsonPath('basic_data.first_name', 'Anna')
            ->assertJsonPath('company_data.company_name', 'OSK Bez PKK')
            ->assertJsonMissingPath('pkk_api_data');

        $this->assertSame(0, DB::table('pkk_integration_settings')
            ->where('organization_id', $actor['organization_id'])
            ->count());

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeaders(['If-Match' => '"v1"', 'Idempotency-Key' => $key])
            ->patchJson('/api/v1/organization/settings', $payload)
            ->assertOk()
            ->assertJsonPath('version', 2);

        $this->assertSame(2, (int) DB::table('organization_settings')
            ->where('organization_id', $actor['organization_id'])
            ->value('version'));
        $this->assertSame(1, DB::table('audit_logs')
            ->where('organization_id', $actor['organization_id'])
            ->where('action', 'organization.settings.updated')
            ->count());
    }

    public function test_partial_existing_address_update_preserves_other_address_fields(): void
    {
        $actor = $this->actorWithEmail();
        DB::table('organization_contact_addresses')->insert([
            'organization_id' => $actor['organization_id'],
            'street' => 'Stara',
            'house_number' => '1',
            'unit_number' => null,
            'postal_code' => '00-001',
            'city_name' => 'Warszawa',
            'city_reference' => null,
            'voivodeship_name' => null,
            'country_code' => 'PL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeaders(['If-Match' => 'v1', 'Idempotency-Key' => (string) Str::uuid7()])
            ->patchJson('/api/v1/organization/settings', [
                'company_data' => ['postal_code' => '00-002'],
            ])
            ->assertOk()
            ->assertJsonPath('company_data.street', 'Stara')
            ->assertJsonPath('company_data.house_number', '1')
            ->assertJsonPath('company_data.city', 'Warszawa')
            ->assertJsonPath('company_data.postal_code', '00-002');
    }

    public function test_provider_neutral_settings_never_expose_or_mutate_existing_pkk_record(): void
    {
        $actor = $this->actorWithEmail();

        DB::table('pkk_integration_settings')->insert([
            'organization_id' => $actor['organization_id'],
            'school_name' => 'PKK MARKER SCHOOL',
            'osk_registry_number' => 'PKK-MARKER-123',
            'external_osk_login_ciphertext' => 'PKK-CIPHERTEXT-MARKER',
            'external_osk_login_lookup_hash' => null,
            'readiness_status' => 'configured_unverified',
            'execution_configuration_revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/organization/settings')
            ->assertOk();

        $this->assertArrayNotHasKey('pkk_api_data', $response->json());
        $this->assertStringNotContainsString('PKK MARKER SCHOOL', $response->getContent());
        $this->assertStringNotContainsString('PKK-MARKER-123', $response->getContent());

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeaders(['If-Match' => '"v1"', 'Idempotency-Key' => (string) Str::uuid7()])
            ->patchJson('/api/v1/organization/settings', [
                'pkk_api_data' => ['school_name' => 'Forbidden'],
            ])
            ->assertUnprocessable();

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeaders(['If-Match' => '"v1"', 'Idempotency-Key' => (string) Str::uuid7()])
            ->patchJson('/api/v1/organization/settings', [
                'basic_data' => ['email' => 'new@example.test'],
            ])
            ->assertUnprocessable();

        $row = DB::table('pkk_integration_settings')
            ->where('organization_id', $actor['organization_id'])
            ->firstOrFail();
        $this->assertSame('PKK MARKER SCHOOL', (string) $row->school_name);
        $this->assertSame('PKK-MARKER-123', (string) $row->osk_registry_number);
    }

    public function test_settings_update_does_not_require_organization_view_permission(): void
    {
        $actor = $this->actorWithEmail();
        DB::table('membership_permissions')
            ->where('membership_id', $actor['membership_id'])
            ->where('permission_code', 'organization.view')
            ->update(['granted' => false]);
        DB::table('membership_permission_scopes')
            ->where('membership_id', $actor['membership_id'])
            ->where('permission_code', 'organization.view')
            ->delete();

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeaders(['If-Match' => 'v1', 'Idempotency-Key' => (string) Str::uuid7()])
            ->patchJson('/api/v1/organization/settings', [
                'basic_data' => ['first_name' => 'Anna'],
            ])
            ->assertOk()
            ->assertJsonPath('basic_data.first_name', 'Anna')
            ->assertJsonPath('version', 2);
    }

    public function test_organization_update_uses_settings_version_without_pkk_prerequisite(): void
    {
        $actor = $this->actorWithEmail();
        FoundationSchema::grant($actor['membership_id'], 'organization.edit', ['organization']);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeaders(['If-Match' => 'v1'])
            ->patchJson('/api/v1/organization', [
                'name' => 'OSK Provider Neutral',
                'nip' => '5250001009',
                'phone' => '+48 600 100 200',
                'timezone' => 'Europe/Warsaw',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'OSK Provider Neutral')
            ->assertJsonPath('nip', '5250001009');

        $this->assertSame(2, (int) DB::table('organization_settings')
            ->where('organization_id', $actor['organization_id'])
            ->value('version'));
        $this->assertSame(0, DB::table('pkk_integration_settings')
            ->where('organization_id', $actor['organization_id'])
            ->count());

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeaders(['If-Match' => 'v1'])
            ->patchJson('/api/v1/organization', ['name' => 'Stale'])
            ->assertConflict();

        $this->assertSame('OSK Provider Neutral', DB::table('organizations')
            ->where('id', $actor['organization_id'])
            ->value('name'));
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function actorWithEmail(): array
    {
        $actor = FoundationSchema::actor();

        DB::table('auth_login_identifiers')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $actor['user_id'],
            'identifier_type' => 'email',
            'identifier_normalized' => 'owner@example.test',
            'is_primary_for_type' => true,
            'verified_at' => now(),
            'created_at' => now(),
            'revoked_at' => null,
        ]);

        return $actor;
    }
}
