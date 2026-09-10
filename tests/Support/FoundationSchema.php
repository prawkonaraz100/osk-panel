<?php

namespace Tests\Support;

use App\Support\Migrations\MigrationPlan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class FoundationSchema
{
    /** @var list<string> */
    private const TABLES = [
        'outbox_messages',
        'domain_events',
        'audit_logs',
        'audit_action_policy_currents',
        'audit_action_policy_revisions',
        'auth_sessions',
        'membership_permission_scopes',
        'permission_scope_options',
        'membership_permissions',
        'organization_memberships',
        'auth_login_identifiers',
        'organization_contact_addresses',
        'organization_settings',
        'data_scopes',
        'permissions',
        'users',
        'organizations',
    ];

    public static function ensureMigrated(): void
    {
        $plan = app(MigrationPlan::class);
        $plan->validate();

        if (! DB::getSchemaBuilder()->hasTable('organizations')) {
            $exit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'expand',
                '--force' => true,
            ]);
            if ($exit !== 0) {
                throw new LogicException('Foundation controlled migration failed: '.Artisan::output());
            }
        }
    }

    public static function reset(): void
    {
        self::ensureMigrated();
        foreach (self::TABLES as $table) {
            DB::table($table)->delete();
        }
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    public static function actor(bool $owner = true): array
    {
        $org = (string) Str::uuid7();
        $user = (string) Str::uuid7();
        $membership = (string) Str::uuid7();
        $session = (string) Str::uuid7();
        $now = now();

        DB::table('organizations')->insert([
            'id' => $org, 'name' => 'Synthetic OSK', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('organization_settings')->insert([
            'organization_id' => $org, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('users')->insert([
            'id' => $user, 'first_name' => 'Test', 'last_name' => 'Owner', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('organization_memberships')->insert([
            'id' => $membership, 'organization_id' => $org, 'user_id' => $user,
            'status' => 'active', 'is_owner' => $owner, 'version' => 1, 'authorization_version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('auth_sessions')->insert([
            'id' => $session, 'user_id' => $user, 'organization_membership_id' => $membership,
            'token_or_framework_session_hash' => hash('sha256', $session), 'created_at' => $now,
        ]);

        self::seedScopes();
        self::grant($membership, 'staff.permissions.manage', ['organization']);
        self::grant($membership, 'organization.members.manage', ['organization']);
        self::grant($membership, 'organization.settings.manage', ['organization']);

        if ($owner) {
            foreach (['organization.view', 'sessions.manage.organization'] as $permission) {
                self::grant($membership, $permission, ['organization']);
            }
        }

        self::installAuditPolicies();

        return [
            'organization_id' => $org,
            'user_id' => $user,
            'membership_id' => $membership,
            'session_id' => $session,
        ];
    }

    /** @param list<string> $scopes */
    public static function grant(string $membershipId, string $permission, array $scopes): void
    {
        DB::table('permissions')->updateOrInsert(
            ['code' => $permission],
            ['description' => $permission],
        );
        foreach ($scopes as $scope) {
            DB::table('permission_scope_options')->updateOrInsert(
                ['permission_code' => $permission, 'scope_code' => $scope],
                ['resolver_code' => $scope === 'organization' ? 'tenant_resource' : 'not_materialized'],
            );
        }
        DB::table('membership_permissions')->updateOrInsert(
            ['membership_id' => $membershipId, 'permission_code' => $permission],
            ['granted' => true, 'created_at' => now()],
        );
        DB::table('membership_permission_scopes')
            ->where('membership_id', $membershipId)
            ->where('permission_code', $permission)
            ->delete();
        foreach ($scopes as $scope) {
            DB::table('membership_permission_scopes')->insert([
                'membership_id' => $membershipId,
                'permission_code' => $permission,
                'scope_code' => $scope,
                'created_at' => now(),
            ]);
        }
    }

    public static function member(string $organizationId, bool $owner = false): string
    {
        $user = (string) Str::uuid7();
        $membership = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $user, 'first_name' => 'Synthetic', 'last_name' => 'Member', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('organization_memberships')->insert([
            'id' => $membership, 'organization_id' => $organizationId, 'user_id' => $user,
            'status' => 'active', 'is_owner' => $owner, 'version' => 1, 'authorization_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $membership;
    }

    public static function installAuditPolicies(): void
    {
        $policies = [
            'authorization.permission.changed' => 'foundation.authorization.v1',
            'authorization.owner.transferred' => 'foundation.authorization.v1',
            'organization.settings.updated' => 'foundation.settings.v1',
        ];
        foreach ($policies as $action => $validator) {
            DB::table('audit_action_policy_revisions')->updateOrInsert(
                ['action' => $action, 'policy_version' => 1],
                [
                    'payload_validator_code' => $validator,
                    'before_payload_requirement' => 'required',
                    'after_payload_requirement' => 'required',
                    'reason_requirement' => 'optional',
                    'policy_hash' => hash('sha256', $action.'|1|'.$validator),
                    'created_at' => now(),
                ],
            );
            DB::table('audit_action_policy_currents')->updateOrInsert(
                ['action' => $action],
                ['policy_version' => 1, 'updated_at' => now()],
            );
        }
    }

    private static function seedScopes(): void
    {
        foreach ([
            'organization' => 'Tenant-wide resource scope',
            'own' => 'Own resource scope',
            'assigned_students' => 'Assigned students scope',
            'assigned_locations' => 'Assigned locations scope',
        ] as $code => $description) {
            DB::table('data_scopes')->updateOrInsert(['code' => $code], ['description' => $description]);
        }
    }
}
