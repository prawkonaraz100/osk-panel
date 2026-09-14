<?php

namespace Tests\Feature;

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4ValidationPackageTest extends TestCase
{
    public function test_all_thirty_four_validation_nodes_pass_without_entering_contract(): void
    {
        FoundationSchema::reset();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        $this->assertSame(170, $plan->implementedNodeCount());
        $this->assertSame(223, $plan->implementedStepCount());
        $this->assertCount(7, $plan->phaseSteps('reconcile'));
        $this->assertCount(0, $plan->phaseSteps('validate'));
        $this->assertCount(0, $plan->phaseSteps('contract'));

        DB::beginTransaction();

        try {
            $this->runThroughReconcile($plan);

            foreach ($this->validateSteps() as [$nodeId, $migrationFile]) {
                ControlledMigrationContext::enter('validate', $nodeId, $plan->executionIdentity());

                try {
                    /** @var Migration $migration */
                    $migration = require base_path($migrationFile);
                    $migration->up();
                } finally {
                    ControlledMigrationContext::leave();
                }
            }

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

            $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*)::int AS signed_count,
       COUNT(*) FILTER (WHERE NOT con.convalidated)::int AS not_validated_count
  FROM pg_constraint con
  JOIN pg_class cls ON cls.oid = con.conrelid
  JOIN pg_namespace ns ON ns.oid = cls.relnamespace
 WHERE ns.nspname = current_schema()
   AND (
       COALESCE(obj_description(con.oid, 'pg_constraint'), '') LIKE 'prawkonaraz:foreign-key-write-fence:v1:%'
       OR COALESCE(obj_description(con.oid, 'pg_constraint'), '') LIKE 'prawkonaraz:constraint-write-fence:v1:%'
   )
SQL);

            $this->assertGreaterThan(0, (int) ($row->signed_count ?? 0));
            $this->assertSame(0, (int) ($row->not_validated_count ?? -1));
            $this->assertCount(0, $plan->phaseSteps('contract'));
        } finally {
            DB::rollBack();
        }
    }

    public function test_new_unresolved_case_after_reconcile_blocks_validation_without_mutation(): void
    {
        FoundationSchema::reset();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        DB::beginTransaction();

        try {
            $this->runThroughReconcile($plan);

            $caseId = (string) Str::uuid7();
            DB::table('event_projection_migration_cases')->insert([
                'id' => $caseId,
                'source_table' => 'domain_events',
                'source_row_id' => (string) Str::uuid7(),
                'source_row_fingerprint' => str_repeat('a', 64),
                'issue_code' => 'validation_reopened_unresolved_case',
                'evidence_class' => 'ambiguous_or_conflicting',
                'resolution_state' => 'open',
                'evidence_reference_json_safe' => json_encode(['proof' => 'validate-stop'], JSON_THROW_ON_ERROR),
                'resolution_kind' => null,
                'resolution_reason' => null,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
                'created_at' => now(),
            ]);

            try {
                $this->runValidationMigration(
                    $plan,
                    'MIG-TRG-EVENTS',
                    'database/migrations/stage4/validate/MIG-TRG-EVENTS/2026_09_10_001660_validate_triggers_events.php',
                );
                $this->fail('Expected a newly opened reconciliation case to block validation.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('reviewed exact remediation is required', $exception->getMessage());
            }

            $case = DB::table('event_projection_migration_cases')->where('id', $caseId)->first();
            $this->assertNotNull($case);
            $this->assertSame('open', $case->resolution_state);
            $this->assertNull($case->reviewed_by_user_id);
            $this->assertNull($case->reviewed_at);
        } finally {
            DB::rollBack();
        }
    }

    private function runThroughReconcile(MigrationPlan $plan): void
    {
        foreach (['preflight', 'write_fence', 'backfill', 'reconcile'] as $phase) {
            $exit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => $phase,
                '--force' => true,
            ]);
            $this->assertSame(0, $exit, Artisan::output());
        }
    }

    private function runValidationMigration(MigrationPlan $plan, string $nodeId, string $migrationFile): void
    {
        ControlledMigrationContext::enter('validate', $nodeId, $plan->executionIdentity());

        try {
            /** @var Migration $migration */
            $migration = require base_path($migrationFile);
            $migration->up();
        } finally {
            ControlledMigrationContext::leave();
        }
    }

    /**
     * @return list<array{string,string}>
     */
    private function validateSteps(): array
    {
        return [
            ['MIG-FK-IDENTITY', 'database/migrations/stage4/validate/MIG-FK-IDENTITY/2026_09_10_001370_validate_foreign_keys_identity.php'],
            ['MIG-FK-RESOURCES', 'database/migrations/stage4/validate/MIG-FK-RESOURCES/2026_09_10_001380_validate_foreign_keys_resources.php'],
            ['MIG-FK-TRAINING', 'database/migrations/stage4/validate/MIG-FK-TRAINING/2026_09_10_001390_validate_foreign_keys_training.php'],
            ['MIG-FK-CALENDAR', 'database/migrations/stage4/validate/MIG-FK-CALENDAR/2026_09_10_001400_validate_foreign_keys_calendar.php'],
            ['MIG-FK-PKK', 'database/migrations/stage4/validate/MIG-FK-PKK/2026_09_10_001410_validate_foreign_keys_pkk.php'],
            ['MIG-FK-FINANCE', 'database/migrations/stage4/validate/MIG-FK-FINANCE/2026_09_10_001420_validate_foreign_keys_finance.php'],
            ['MIG-FK-LICENSES', 'database/migrations/stage4/validate/MIG-FK-LICENSES/2026_09_10_001430_validate_foreign_keys_licenses.php'],
            ['MIG-FK-EXAMS', 'database/migrations/stage4/validate/MIG-FK-EXAMS/2026_09_10_001440_validate_foreign_keys_exams.php'],
            ['MIG-FK-COMMERCE', 'database/migrations/stage4/validate/MIG-FK-COMMERCE/2026_09_10_001450_validate_foreign_keys_commerce.php'],
            ['MIG-FK-PURCHASE_DOWNSTREAM', 'database/migrations/stage4/validate/MIG-FK-PURCHASE_DOWNSTREAM/2026_09_10_001460_validate_foreign_keys_purchase_downstream.php'],
            ['MIG-FK-EVENTS', 'database/migrations/stage4/validate/MIG-FK-EVENTS/2026_09_10_001470_validate_foreign_keys_events.php'],
            ['MIG-CON-IDENTITY', 'database/migrations/stage4/validate/MIG-CON-IDENTITY/2026_09_10_001480_validate_constraints_identity.php'],
            ['MIG-CON-RESOURCES', 'database/migrations/stage4/validate/MIG-CON-RESOURCES/2026_09_10_001490_validate_constraints_resources.php'],
            ['MIG-CON-TRAINING', 'database/migrations/stage4/validate/MIG-CON-TRAINING/2026_09_10_001500_validate_constraints_training.php'],
            ['MIG-CON-CALENDAR', 'database/migrations/stage4/validate/MIG-CON-CALENDAR/2026_09_10_001510_validate_constraints_calendar.php'],
            ['MIG-CON-PKK', 'database/migrations/stage4/validate/MIG-CON-PKK/2026_09_10_001520_validate_constraints_pkk.php'],
            ['MIG-CON-FINANCE', 'database/migrations/stage4/validate/MIG-CON-FINANCE/2026_09_10_001530_validate_constraints_finance.php'],
            ['MIG-CON-LICENSES', 'database/migrations/stage4/validate/MIG-CON-LICENSES/2026_09_10_001540_validate_constraints_licenses.php'],
            ['MIG-CON-EXAMS', 'database/migrations/stage4/validate/MIG-CON-EXAMS/2026_09_10_001550_validate_constraints_exams.php'],
            ['MIG-CON-COMMERCE', 'database/migrations/stage4/validate/MIG-CON-COMMERCE/2026_09_10_001560_validate_constraints_commerce.php'],
            ['MIG-CON-EVENTS', 'database/migrations/stage4/validate/MIG-CON-EVENTS/2026_09_10_001570_validate_constraints_events.php'],
            ['MIG-TRG-IDENTITY', 'database/migrations/stage4/validate/MIG-TRG-IDENTITY/2026_09_10_001580_validate_triggers_identity.php'],
            ['MIG-TRG-TRAINING', 'database/migrations/stage4/validate/MIG-TRG-TRAINING/2026_09_10_001590_validate_triggers_training.php'],
            ['MIG-TRG-CALENDAR', 'database/migrations/stage4/validate/MIG-TRG-CALENDAR/2026_09_10_001600_validate_triggers_calendar.php'],
            ['MIG-TRG-PKK', 'database/migrations/stage4/validate/MIG-TRG-PKK/2026_09_10_001610_validate_triggers_pkk.php'],
            ['MIG-TRG-FINANCE', 'database/migrations/stage4/validate/MIG-TRG-FINANCE/2026_09_10_001620_validate_triggers_finance.php'],
            ['MIG-TRG-LICENSES', 'database/migrations/stage4/validate/MIG-TRG-LICENSES/2026_09_10_001630_validate_triggers_licenses.php'],
            ['MIG-TRG-EXAMS', 'database/migrations/stage4/validate/MIG-TRG-EXAMS/2026_09_10_001640_validate_triggers_exams.php'],
            ['MIG-TRG-COMMERCE', 'database/migrations/stage4/validate/MIG-TRG-COMMERCE/2026_09_10_001650_validate_triggers_commerce.php'],
            ['MIG-TRG-EVENTS', 'database/migrations/stage4/validate/MIG-TRG-EVENTS/2026_09_10_001660_validate_triggers_events.php'],
            ['MIG-PRJ-CALENDAR-RESOURCE-CLAIMS', 'database/migrations/stage4/validate/MIG-PRJ-CALENDAR-RESOURCE-CLAIMS/2026_09_10_001670_validate_projection_calendar_resource_claims.php'],
            ['MIG-PRJ-PURCHASE-HISTORY', 'database/migrations/stage4/validate/MIG-PRJ-PURCHASE-HISTORY/2026_09_10_001680_validate_projection_purchase_history.php'],
            ['MIG-PRJ-ORGANIZATION-ACTIVITY', 'database/migrations/stage4/validate/MIG-PRJ-ORGANIZATION-ACTIVITY/2026_09_10_001690_validate_projection_organization_activity.php'],
            ['MIG-PRJ-NOTIFICATIONS', 'database/migrations/stage4/validate/MIG-PRJ-NOTIFICATIONS/2026_09_10_001700_validate_projection_notifications.php'],
        ];
    }
}
