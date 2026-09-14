<?php

namespace Tests\Feature;

use App\Modules\LearningAccess\LearningAccountService;
use App\Modules\LearningAccess\LicenseService;
use App\Modules\StudentsCourses\StudentService;
use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\TriggerGuards\LicensesTriggerGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4LicensesTriggerGuardsTest extends TestCase
{
    public function test_license_guards_preserve_identity_password_inventory_assignment_and_entitlement_history(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        DB::beginTransaction();

        try {
            $exit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'preflight',
                '--force' => true,
            ]);
            $this->assertSame(0, $exit, Artisan::output());

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

            LicensesTriggerGuards::install();

            $actor = $this->accessActor();
            $student = $this->student($actor);
            $account = app(LearningAccountService::class)->create(
                $actor['session_id'],
                $student['id'],
                [
                    'login_identifier' => 'licenses.guard.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
                    'language_code' => 'pl',
                ],
                (string) Str::uuid7(),
            );
            $this->forceDeferredChecks();

            $identifierId = (string) DB::table('student_learning_accounts')
                ->where('id', $account['id'])
                ->value('auth_login_identifier_id');
            $this->expectDeferredGuardViolation(
                fn () => DB::table('auth_login_identifiers')
                    ->where('id', $identifierId)
                    ->update(['revoked_at' => now()]),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('student_learning_accounts')
                    ->where('id', $account['id'])
                    ->update(['user_id' => $actor['user_id']]),
            );

            $passwordStudent = $this->student($actor);
            $passwordAccount = app(LearningAccountService::class)->create(
                $actor['session_id'],
                $passwordStudent['id'],
                [
                    'login_identifier' => 'password.guard.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
                    'language_code' => 'pl',
                    'initial_password' => str_repeat('x', 16),
                ],
                (string) Str::uuid7(),
            );
            $this->forceDeferredChecks();
            $passwordUserId = (string) DB::table('student_learning_accounts')
                ->where('id', $passwordAccount['id'])
                ->value('user_id');

            $this->expectDeferredGuardViolation(
                fn () => DB::table('user_password_management')
                    ->where('user_id', $passwordUserId)
                    ->update(['credential_version' => 0]),
            );
            $this->expectDeferredGuardViolation(function () use ($passwordUserId): void {
                DB::table('auth_social_accounts')->insert([
                    'id' => (string) Str::uuid7(),
                    'user_id' => $passwordUserId,
                    'provider' => 'google',
                    'provider_subject' => 'guard-'.str_replace('-', '', (string) Str::uuid7()),
                    'created_at' => now(),
                    'revoked_at' => null,
                ]);
            });

            $this->expectDeferredGuardViolation(function () use ($actor): void {
                DB::table('student_access_export_batches')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $actor['organization_id'],
                    'requested_by_user_id' => $actor['user_id'],
                    'export_mode' => 'nonsecret_combined_pdf',
                    'selected_account_count' => 1,
                    'created_at' => now(),
                ]);
            });

            $this->expectImmediateGuardViolation(function () use ($actor, $account): void {
                DB::table('student_access_handoffs')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $actor['organization_id'],
                    'student_learning_account_id' => $account['id'],
                    'handoff_type' => 'password_reset',
                    'generated_by_user_id' => $actor['user_id'],
                    'document_asset_id' => null,
                    'credential_version_snapshot' => 0,
                    'contains_fresh_secret' => true,
                    'fresh_secret_issued_at' => now(),
                    'batch_id' => null,
                    'batch_ordinal' => null,
                    'created_at' => now(),
                ]);
            });

            $inventory = $this->inventory($actor['organization_id'], 30);
            $this->forceDeferredChecks();

            $this->expectImmediateGuardViolation(
                fn () => DB::table('license_products')
                    ->where('id', $inventory['product_id'])
                    ->update(['duration_days' => 31]),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('license_inventory_entries')
                    ->where('id', $inventory['inventory_id'])
                    ->update(['license_product_id' => (string) Str::uuid7()]),
            );

            $assignment = app(LicenseService::class)->createAssignment(
                $actor['session_id'],
                [
                    'license_inventory_entry_id' => $inventory['inventory_id'],
                    'target' => ['existing_learning_account_id' => $account['id']],
                    'language_code' => 'pl',
                ],
                (string) Str::uuid7(),
            );
            $this->forceDeferredChecks();

            $this->expectImmediateGuardViolation(
                fn () => DB::table('license_assignments')
                    ->where('id', $assignment['id'])
                    ->update(['language_code' => 'en']),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('license_assignments')
                    ->where('id', $assignment['id'])
                    ->delete(),
            );
            $this->expectDeferredGuardViolation(
                fn () => DB::table('license_inventory_entries')
                    ->where('id', $inventory['inventory_id'])
                    ->update(['status' => 'available']),
            );

            $assignedAt = DB::table('license_assignments')
                ->where('id', $assignment['id'])
                ->value('assigned_at');
            $this->expectDeferredGuardViolation(
                fn () => DB::table('license_product_language_capabilities')
                    ->where('id', $inventory['capability_id'])
                    ->update(['disabled_at' => now()->subSecond()]),
            );

            $activation = app(LicenseService::class)->activate(
                $actor['session_id'],
                $assignment['id'],
                (string) Str::uuid7(),
                '"v1"',
            );
            $this->assertSame(1, $activation['entitlement_sequence']);
            $this->forceDeferredChecks();

            $activationId = (string) DB::table('license_activations')
                ->where('license_assignment_id', $assignment['id'])
                ->value('id');
            $this->expectImmediateGuardViolation(
                fn () => DB::table('license_activations')
                    ->where('id', $activationId)
                    ->update(['effective_to' => now()->addDays(90)]),
            );
            $this->expectImmediateGuardViolation(function () use ($actor, $account, $assignment): void {
                DB::table('license_activations')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $actor['organization_id'],
                    'license_assignment_id' => $assignment['id'],
                    'student_learning_account_id' => $account['id'],
                    'entitlement_sequence' => 2,
                    'activation_origin' => 'legacy_unknown',
                    'duration_snapshot_source' => 'legacy_effect_reconstructed',
                    'duration_days_snapshot' => 30,
                    'expiry_before' => null,
                    'activated_by_user_id' => null,
                    'activated_at' => now(),
                    'effective_from' => now(),
                    'effective_to' => now()->addDays(30),
                    'created_at' => now(),
                ]);
            });

            $secondInventory = $this->inventory($actor['organization_id'], 15);
            $secondAssignment = app(LicenseService::class)->createAssignment(
                $actor['session_id'],
                [
                    'license_inventory_entry_id' => $secondInventory['inventory_id'],
                    'target' => ['existing_learning_account_id' => $account['id']],
                    'language_code' => 'pl',
                ],
                (string) Str::uuid7(),
            );
            $this->forceDeferredChecks();

            $this->expectDeferredGuardViolation(function () use (
                $actor,
                $account,
                $secondAssignment,
                $secondInventory,
                $activation,
            ): void {
                $effectiveAt = now();
                DB::table('license_activations')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $actor['organization_id'],
                    'license_assignment_id' => $secondAssignment['id'],
                    'student_learning_account_id' => $account['id'],
                    'entitlement_sequence' => 3,
                    'activation_origin' => 'organization_user',
                    'duration_snapshot_source' => 'product_at_activation',
                    'duration_days_snapshot' => 15,
                    'expiry_before' => $activation['effective_to'],
                    'activated_by_user_id' => $actor['user_id'],
                    'activated_at' => $effectiveAt,
                    'effective_from' => $activation['effective_to'],
                    'effective_to' => Carbon::parse($activation['effective_to'])->addSeconds(15 * 86400),
                    'created_at' => $effectiveAt,
                ]);
                DB::table('license_assignments')->where('id', $secondAssignment['id'])->update([
                    'status' => 'activated',
                    'version' => 2,
                ]);
                DB::table('license_inventory_entries')->where('id', $secondInventory['inventory_id'])->update([
                    'status' => 'consumed',
                ]);
            });

            $this->assertNotNull($assignedAt);
        } finally {
            DB::rollBack();
        }
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function accessActor(): array
    {
        $actor = FoundationSchema::actor();
        foreach ([
            'students.view',
            'students.create',
            'students.archive',
            'students.restore',
            'student_access.view',
            'student_access.create',
            'student_access.manage_credentials',
            'student_access.reset_password',
            'licenses.view',
            'licenses.assign',
            'licenses.activate',
            'licenses.revoke_unactivated',
            'licenses.progress.view',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        return $actor;
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @return array<string,mixed>
     */
    private function student(array $actor): array
    {
        return app(StudentService::class)->create(
            $actor['session_id'],
            [
                'first_name' => 'Jan',
                'last_name' => 'Licencja',
                'no_pesel' => true,
                'birth_date' => '1990-01-01',
            ],
            (string) Str::uuid7(),
        );
    }

    /** @return array{product_id:string,capability_id:string,inventory_id:string} */
    private function inventory(string $organizationId, int $durationDays): array
    {
        $product = (string) Str::uuid7();
        $capability = (string) Str::uuid7();
        $inventory = (string) Str::uuid7();

        DB::table('license_products')->insert([
            'id' => $product,
            'code' => 'TRIGGER-'.$product,
            'duration_days' => $durationDays,
            'active' => true,
            'activation_mode' => 'manual',
            'metadata' => null,
        ]);
        DB::table('license_product_language_capabilities')->insert([
            'id' => $capability,
            'license_product_id' => $product,
            'language_code' => 'pl',
            'enabled_at' => now()->subDay(),
            'disabled_at' => null,
            'created_at' => now()->subDay(),
        ]);
        DB::table('license_inventory_entries')->insert([
            'id' => $inventory,
            'organization_id' => $organizationId,
            'license_product_id' => $product,
            'source_order_item_id' => null,
            'source_order_item_grant_ordinal' => null,
            'status' => 'available',
            'granted_at' => now(),
            'created_at' => now(),
        ]);

        return [
            'product_id' => $product,
            'capability_id' => $capability,
            'inventory_id' => $inventory,
        ];
    }

    private function forceDeferredChecks(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function expectImmediateGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_licenses_immediate');

        try {
            $operation();
            $this->fail('Expected immediate licenses trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_licenses_immediate');
        }
    }

    private function expectDeferredGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_licenses_deferred');

        try {
            $operation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Expected deferred licenses trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_licenses_deferred');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }
}
