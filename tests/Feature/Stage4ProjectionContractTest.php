<?php

namespace Tests\Feature;

use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\Stage4ProjectionContract;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4ProjectionContractTest extends TestCase
{
    public function test_validated_projection_contract_has_no_destructive_scope_in_current_schema(): void
    {
        FoundationSchema::reset();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        $this->assertSame(170, $plan->implementedNodeCount());
        $this->assertSame(257, $plan->implementedStepCount());
        $this->assertCount(34, $plan->phaseSteps('validate'));
        $this->assertCount(0, $plan->phaseSteps('contract'));

        DB::beginTransaction();

        try {
            $this->runThroughValidate($plan);

            foreach ($this->contractNodes() as $nodeId) {
                $this->assertSame(
                    ['removed_objects' => 0, 'deauthorized_paths' => 0, 'no_op' => true],
                    Stage4ProjectionContract::assertNoDestructiveScope($nodeId),
                );
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_legacy_orders_status_path_blocks_contract_instead_of_being_dropped(): void
    {
        FoundationSchema::reset();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        DB::beginTransaction();

        try {
            $this->runThroughValidate($plan);
            DB::statement('ALTER TABLE orders ADD COLUMN status varchar(32) NULL');

            try {
                Stage4ProjectionContract::assertNoDestructiveScope('MIG-PRJ-PURCHASE-HISTORY');
                $this->fail('Expected legacy orders.status to block contract.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('separate authorized cleanup is required', $exception->getMessage());
            }

            $this->assertTrue(
                DB::selectOne(
                    "SELECT EXISTS (
                        SELECT 1
                          FROM information_schema.columns
                         WHERE table_schema = current_schema()
                           AND table_name = 'orders'
                           AND column_name = 'status'
                    ) AS present",
                )->present,
            );
        } finally {
            DB::rollBack();
        }
    }

    public function test_reopened_migration_case_blocks_contract_without_history_mutation(): void
    {
        FoundationSchema::reset();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        DB::beginTransaction();

        try {
            $this->runThroughValidate($plan);

            $caseId = (string) Str::uuid7();
            DB::table('event_projection_migration_cases')->insert([
                'id' => $caseId,
                'source_table' => 'notifications',
                'source_row_id' => (string) Str::uuid7(),
                'source_row_fingerprint' => str_repeat('b', 64),
                'issue_code' => 'contract_reopened_unresolved_case',
                'evidence_class' => 'ambiguous_or_conflicting',
                'resolution_state' => 'open',
                'evidence_reference_json_safe' => json_encode(['proof' => 'contract-stop'], JSON_THROW_ON_ERROR),
                'resolution_kind' => null,
                'resolution_reason' => null,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
                'created_at' => now(),
            ]);

            try {
                Stage4ProjectionContract::assertNoDestructiveScope('MIG-PRJ-NOTIFICATIONS');
                $this->fail('Expected reopened migration case to block contract.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('blocked by unresolved migration review evidence', $exception->getMessage());
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

    private function runThroughValidate(MigrationPlan $plan): void
    {
        foreach (['preflight', 'write_fence', 'backfill', 'reconcile', 'validate'] as $phase) {
            $exit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => $phase,
                '--force' => true,
            ]);
            $this->assertSame(0, $exit, Artisan::output());
        }
    }

    /** @return list<string> */
    private function contractNodes(): array
    {
        return [
            'MIG-PRJ-CALENDAR-RESOURCE-CLAIMS',
            'MIG-PRJ-PURCHASE-HISTORY',
            'MIG-PRJ-ORGANIZATION-ACTIVITY',
            'MIG-PRJ-NOTIFICATIONS',
        ];
    }
}
