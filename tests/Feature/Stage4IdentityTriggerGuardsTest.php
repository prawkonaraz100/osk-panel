<?php

namespace Tests\Feature;

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\TriggerGuards\IdentityTriggerGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4IdentityTriggerGuardsTest extends TestCase
{
    public function test_identity_trigger_guards_preserve_membership_owner_and_authorization_final_state(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        DB::beginTransaction();

        try {
            $preflightExit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'preflight',
                '--force' => true,
            ]);
            $this->assertSame(0, $preflightExit, Artisan::output());

            foreach ($plan->phaseSteps('write_fence') as $step) {
                ControlledMigrationContext::enter('write_fence', $step['node_id'], $plan->executionIdentity());

                try {
                    /** @var Migration $migration */
                    $migration = require base_path($step['migration_file']);
                    $migration->up();
                } finally {
                    ControlledMigrationContext::leave();
                }
            }

            IdentityTriggerGuards::install();

            $triggers = $this->signedIdentityTriggers();
            $this->assertCount(4, $triggers);
            $this->assertCount(3, array_filter($triggers, static fn (array $row): bool => $row['constraint']));
            $this->assertCount(3, array_filter($triggers, static fn (array $row): bool => $row['deferrable'] && $row['initially_deferred']));

            $actor = FoundationSchema::actor();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            $this->expectImmediateGuardViolation(
                fn () => DB::table('organization_memberships')
                    ->where('id', $actor['membership_id'])
                    ->update(['user_id' => DB::raw('(SELECT id FROM users WHERE id <> \''.$actor['user_id'].'\' LIMIT 1)')]),
            );

            $this->expectImmediateGuardViolation(
                fn () => DB::table('organization_memberships')
                    ->where('id', $actor['membership_id'])
                    ->delete(),
            );

            $this->expectDeferredGuardViolation(
                fn () => DB::table('organization_memberships')
                    ->where('id', $actor['membership_id'])
                    ->update(['is_owner' => false]),
            );

            $this->expectDeferredGuardViolation(
                fn () => DB::table('membership_permission_scopes')
                    ->where('membership_id', $actor['membership_id'])
                    ->where('permission_code', 'organization.view')
                    ->where('scope_code', 'organization')
                    ->delete(),
            );

            $member = FoundationSchema::member($actor['organization_id']);
            DB::table('membership_permissions')->insert([
                'membership_id' => $member,
                'permission_code' => 'organization.view',
                'granted' => true,
                'created_at' => now(),
            ]);
            $this->expectDeferredGuardViolation(static function (): void {
                // The pending granted permission has no scope. SET CONSTRAINTS in the helper proves final-state rejection.
            });

            DB::table('membership_permission_scopes')->insert([
                'membership_id' => $member,
                'permission_code' => 'organization.view',
                'scope_code' => 'organization',
                'created_at' => now(),
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            $this->expectDeferredGuardViolation(
                fn () => DB::table('organization_memberships')
                    ->where('id', $member)
                    ->update(['status' => 'revoked']),
            );

            DB::table('membership_permission_scopes')->where('membership_id', $member)->delete();
            DB::table('membership_permissions')->where('membership_id', $member)->update(['granted' => false]);
            DB::table('organization_memberships')->where('id', $member)->update([
                'status' => 'revoked',
                'is_owner' => false,
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            $successor = FoundationSchema::member($actor['organization_id']);
            foreach ([
                'organization.view',
                'organization.members.manage',
                'staff.permissions.manage',
                'sessions.manage.organization',
            ] as $permission) {
                FoundationSchema::grant($successor, $permission, ['organization']);
            }

            DB::table('organization_memberships')->where('id', $successor)->update(['is_owner' => true]);
            DB::table('organization_memberships')->where('id', $actor['membership_id'])->update(['is_owner' => false]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            $this->assertSame(
                1,
                DB::table('organization_memberships')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('status', 'active')
                    ->where('is_owner', true)
                    ->count(),
            );
        } finally {
            DB::rollBack();
        }
    }

    private function expectImmediateGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_identity_immediate');

        try {
            $operation();
            $this->fail('Expected immediate identity trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_identity_immediate');
        }
    }

    private function expectDeferredGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_identity_deferred');

        try {
            $operation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Expected deferred identity trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_identity_deferred');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }

    /**
     * @return list<array{
     *   table: string,
     *   name: string,
     *   constraint: bool,
     *   deferrable: bool,
     *   initially_deferred: bool
     * }>
     */
    private function signedIdentityTriggers(): array
    {
        return DB::table('pg_trigger as trg')
            ->join('pg_class as cls', 'cls.oid', '=', 'trg.tgrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 'cls.relnamespace')
            ->whereRaw('ns.nspname = current_schema()')
            ->where('trg.tgisinternal', false)
            ->whereRaw("COALESCE(obj_description(trg.oid, 'pg_trigger'), '') LIKE 'prawkonaraz:trigger-write-fence:v1:%'")
            ->whereIn('cls.relname', [
                'organization_memberships',
                'membership_permissions',
                'membership_permission_scopes',
            ])
            ->orderBy('cls.relname')
            ->orderBy('trg.tgname')
            ->get([
                'cls.relname as table_name',
                'trg.tgname',
                DB::raw('CASE WHEN trg.tgconstraint <> 0 THEN 1 ELSE 0 END AS is_constraint'),
                DB::raw('CASE WHEN trg.tgdeferrable THEN 1 ELSE 0 END AS is_deferrable'),
                DB::raw('CASE WHEN trg.tginitdeferred THEN 1 ELSE 0 END AS is_initially_deferred'),
            ])
            ->map(static fn ($row): array => [
                'table' => (string) $row->table_name,
                'name' => (string) $row->tgname,
                'constraint' => (int) $row->is_constraint === 1,
                'deferrable' => (int) $row->is_deferrable === 1,
                'initially_deferred' => (int) $row->is_initially_deferred === 1,
            ])
            ->all();
    }
}
