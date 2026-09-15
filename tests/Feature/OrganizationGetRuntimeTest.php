<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class OrganizationGetRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FoundationSchema::reset();
    }

    public function test_organization_get_projects_only_current_tenant_canonical_organization_fields(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('organizations')
            ->where('id', $actor['organization_id'])
            ->update([
                'nip' => '5250001009',
                'phone' => '+48 600 100 200',
                'timezone' => 'Europe/Warsaw',
            ]);

        $foreign = FoundationSchema::actor();
        DB::table('organizations')
            ->where('id', $foreign['organization_id'])
            ->update(['name' => 'Foreign OSK']);

        $response = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/organization')
            ->assertOk()
            ->assertJson([
                'id' => $actor['organization_id'],
                'name' => 'Synthetic OSK',
                'nip' => '5250001009',
                'phone' => '+48 600 100 200',
                'timezone' => 'Europe/Warsaw',
                'status' => 'active',
            ]);

        $this->assertArrayNotHasKey('osk_registry_number', $response->json());
        $this->assertStringNotContainsString('Foreign OSK', $response->getContent());
    }

    public function test_organization_get_requires_current_organization_view_permission(): void
    {
        $actor = FoundationSchema::actor();

        DB::table('membership_permissions')
            ->where('membership_id', $actor['membership_id'])
            ->where('permission_code', 'organization.view')
            ->update(['granted' => false]);
        DB::table('membership_permission_scopes')
            ->where('membership_id', $actor['membership_id'])
            ->where('permission_code', 'organization.view')
            ->delete();

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/organization')
            ->assertForbidden();
    }
}
