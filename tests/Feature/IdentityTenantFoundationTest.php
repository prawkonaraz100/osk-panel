<?php

namespace Tests\Feature;

use App\Modules\IdentityTenant\MembershipGovernance;
use App\Modules\IdentityTenant\TenantAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class IdentityTenantFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_dbt_iam_009_tenant_request_without_membership_context_is_denied(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('auth_sessions')->where('id', $actor['session_id'])->update(['organization_membership_id' => null]);

        $this->expectException(AuthorizationException::class);
        app(TenantAuthorizer::class)->requireOrganizationPermission(
            $actor['session_id'],
            $actor['organization_id'],
            'organization.view',
        );
    }

    public function test_dbt_iam_013_granted_permission_without_scope_is_denied(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('membership_permission_scopes')
            ->where('membership_id', $actor['membership_id'])
            ->where('permission_code', 'organization.view')
            ->delete();

        $this->expectException(AuthorizationException::class);
        app(TenantAuthorizer::class)->requireOrganizationPermission(
            $actor['session_id'],
            $actor['organization_id'],
            'organization.view',
        );
    }

    public function test_dbt_iam_015_organization_scope_never_bypasses_cross_tenant_validation(): void
    {
        $actor = FoundationSchema::actor();
        $other = FoundationSchema::actor();

        $this->expectException(AuthorizationException::class);
        app(TenantAuthorizer::class)->requireOrganizationPermission(
            $actor['session_id'],
            $other['organization_id'],
            'organization.view',
        );
    }

    public function test_dbt_core_009_owner_marker_alone_does_not_grant_permissions(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('membership_permissions')->where('membership_id', $actor['membership_id'])->delete();
        DB::table('membership_permission_scopes')->where('membership_id', $actor['membership_id'])->delete();

        $this->expectException(AuthorizationException::class);
        app(TenantAuthorizer::class)->requireOrganizationPermission(
            $actor['session_id'],
            $actor['organization_id'],
            'organization.view',
        );
    }

    public function test_dbt_iam_020_suspended_membership_cannot_authorize_with_retained_permission_rows(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('organization_memberships')->where('id', $actor['membership_id'])->update(['status' => 'suspended']);

        $this->expectException(AuthorizationException::class);
        app(TenantAuthorizer::class)->requireOrganizationPermission(
            $actor['session_id'],
            $actor['organization_id'],
            'organization.view',
        );
    }

    public function test_dbt_iam_017_actor_cannot_grant_permission_above_own_ceiling(): void
    {
        $actor = FoundationSchema::actor();
        $target = FoundationSchema::member($actor['organization_id']);
        DB::table('permissions')->insert(['code' => 'students.manage', 'description' => 'students.manage']);
        DB::table('permission_scope_options')->insert([
            'permission_code' => 'students.manage', 'scope_code' => 'organization', 'resolver_code' => 'tenant_resource',
        ]);

        $this->expectException(AuthorizationException::class);
        app(MembershipGovernance::class)->replacePermissionScopes(
            $actor['session_id'],
            $target,
            1,
            'students.manage',
            true,
            ['organization'],
            (string) Str::uuid7(),
        );
    }

    public function test_dbt_iam_016_owner_transfer_requires_materialized_successor_baseline(): void
    {
        $actor = FoundationSchema::actor();
        $target = FoundationSchema::member($actor['organization_id']);

        $this->expectException(AuthorizationException::class);
        app(MembershipGovernance::class)->transferOwner(
            $actor['session_id'],
            $actor['membership_id'],
            $target,
            (string) Str::uuid7(),
        );
    }

    public function test_dbt_core_015_authorization_mutation_increments_authorization_version_once(): void
    {
        $actor = FoundationSchema::actor();
        $target = FoundationSchema::member($actor['organization_id']);
        FoundationSchema::grant($actor['membership_id'], 'students.manage', ['organization']);

        $result = app(MembershipGovernance::class)->replacePermissionScopes(
            $actor['session_id'],
            $target,
            1,
            'students.manage',
            true,
            ['organization'],
            (string) Str::uuid7(),
        );

        $this->assertSame(2, $result['version']);
        $this->assertSame(2, $result['authorization_version']);
        $row = DB::table('organization_memberships')->where('id', $target)->first();
        $this->assertSame(2, (int) $row->version);
        $this->assertSame(2, (int) $row->authorization_version);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('domain_events', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }
}
