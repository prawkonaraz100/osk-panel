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
    public function test_all_thirty_four_registered_validation_nodes_pass_without_entering_contract(): void
    {
        FoundationSchema::reset();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        $this->assertSame(170, $plan->implementedNodeCount());
        $this->assertSame(257, $plan->implementedStepCount());
        $this->assertCount(7, $plan->phaseSteps('reconcile'));
        $this->assertCount(34, $plan->phaseSteps('validate'));
        $this->assertCount(0, $plan->phaseSteps('contract'));

        DB::beginTransaction();

        try {
            $this->runThroughReconcile($plan);

            $validateExit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'validate',
                '--force' => true,
            ]);
            $this->assertSame(0, $validateExit, Artisan::output());

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
}
