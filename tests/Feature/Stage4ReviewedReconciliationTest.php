<?php

namespace Tests\Feature;

use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\Stage4ReviewedReconciliation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4ReviewedReconciliationTest extends TestCase
{
    public function test_clean_post_backfill_state_passes_all_seven_read_only_reconcile_checks(): void
    {
        FoundationSchema::reset();

        $plan = app(MigrationPlan::class);
        $plan->validate();
        $this->assertSame(170, $plan->implementedNodeCount());
        $this->assertSame(257, $plan->implementedStepCount());
        $this->assertCount(7, $plan->phaseSteps('reconcile'));
        $this->assertCount(34, $plan->phaseSteps('validate'));

        DB::beginTransaction();

        try {
            $this->runThroughBackfill($plan);

            $exit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'reconcile',
                '--force' => true,
            ]);
            $this->assertSame(0, $exit, Artisan::output());
        } finally {
            DB::rollBack();
        }
    }

    public function test_open_legacy_projection_case_blocks_reconcile_without_mutating_history(): void
    {
        FoundationSchema::reset();

        $plan = app(MigrationPlan::class);
        $plan->validate();
        $actor = FoundationSchema::actor();

        DB::beginTransaction();

        try {
            $activityId = $this->unresolvedLegacyActivity($actor);
            $this->runThroughBackfill($plan);

            $case = DB::table('event_projection_migration_cases')
                ->where('source_table', 'organization_activity_events')
                ->where('source_row_id', $activityId)
                ->where('issue_code', 'activity_projection_contract_unresolved')
                ->first();

            $this->assertNotNull($case);
            $this->assertSame('open', $case->resolution_state);

            $beforeActivity = DB::table('organization_activity_events')->where('id', $activityId)->first();
            $beforeCase = DB::table('event_projection_migration_cases')->where('id', $case->id)->first();

            try {
                Stage4ReviewedReconciliation::assertResolved('MIG-TRG-EVENTS');
                $this->fail('Expected open reviewed reconciliation case to block Stage-4 reconcile.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('reviewed exact remediation is required', $exception->getMessage());
            }

            $this->assertEquals(
                $beforeActivity,
                DB::table('organization_activity_events')->where('id', $activityId)->first(),
            );
            $this->assertEquals(
                $beforeCase,
                DB::table('event_projection_migration_cases')->where('id', $case->id)->first(),
            );
        } finally {
            DB::rollBack();
        }
    }

    private function runThroughBackfill(MigrationPlan $plan): void
    {
        foreach (['preflight', 'write_fence', 'backfill'] as $phase) {
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
    private function reconcileNodes(): array
    {
        return [
            'MIG-FK-PURCHASE_DOWNSTREAM',
            'MIG-FK-EVENTS',
            'MIG-TRG-EVENTS',
            'MIG-PRJ-CALENDAR-RESOURCE-CLAIMS',
            'MIG-PRJ-PURCHASE-HISTORY',
            'MIG-PRJ-ORGANIZATION-ACTIVITY',
            'MIG-PRJ-NOTIFICATIONS',
        ];
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     */
    private function unresolvedLegacyActivity(array $actor): string
    {
        $sourceEventId = (string) Str::uuid7();
        $activityId = (string) Str::uuid7();
        $occurredAt = now()->subMinute()->startOfSecond();
        $sourceEventType = 'legacy.activity.expected';
        $activityEventType = 'legacy.activity.snapshot';

        DB::table('activity_projection_policy_revisions')->insert([
            'event_type' => $activityEventType,
            'policy_version' => 1,
            'payload_validator_code' => 'legacy.safe.v1',
            'description_builder_code' => 'legacy.description.v1',
            'actor_snapshot_rule_code' => 'required_audit_actor.v1',
            'subject_reference_rule_code' => 'none.v1',
            'related_student_rule_code' => 'none.v1',
            'navigation_rule_code' => 'none.v1',
            'supports_expand' => false,
            'policy_hash' => hash('sha256', $activityEventType.'|1'),
            'created_at' => $occurredAt,
        ]);
        DB::table('activity_projection_policy_currents')->insert([
            'event_type' => $activityEventType,
            'policy_version' => 1,
            'updated_at' => $occurredAt,
        ]);
        DB::table('domain_events')->insert([
            'id' => $sourceEventId,
            'event_scope' => 'organization',
            'organization_id' => $actor['organization_id'],
            'event_type' => $sourceEventType,
            'aggregate_reference_mode' => 'snapshot_only',
            'aggregate_type' => 'legacy_projection_test',
            'aggregate_id' => (string) Str::uuid7(),
            'request_id' => (string) Str::uuid7(),
            'causation_event_id' => null,
            'required_audit_log_id' => null,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
        DB::table('organization_activity_events')->insert([
            'id' => $activityId,
            'organization_id' => $actor['organization_id'],
            'source_event_id' => $sourceEventId,
            'projection_policy_version' => 1,
            'event_type' => $activityEventType,
            'occurred_at' => $occurredAt,
            'description_snapshot' => 'Legacy safe snapshot',
            'safe_payload' => json_encode(['kind' => 'legacy-safe'], JSON_THROW_ON_ERROR),
            'actor_reference_mode' => 'organization_membership',
            'actor_organization_membership_id' => $actor['membership_id'],
            'actor_user_id' => $actor['user_id'],
            'actor_display_name_snapshot' => 'Legacy Reviewer',
            'actor_role_snapshot' => 'Owner',
            'subject_reference_mode' => 'none',
            'subject_type' => null,
            'subject_id' => null,
            'related_student_id' => null,
            'created_at' => $occurredAt,
        ]);

        return $activityId;
    }
}
