<?php

namespace Tests\Feature;

use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\Stage4ExactEvidenceBackfill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4ExactEvidenceBackfillTest extends TestCase
{
    public function test_exact_evidence_backfill_mutates_only_safe_projection_facts_and_defers_unresolved_rows(): void
    {
        FoundationSchema::reset();

        $plan = app(MigrationPlan::class);
        $plan->validate();
        $this->assertSame(170, $plan->implementedNodeCount());
        $this->assertSame(216, $plan->implementedStepCount());
        $this->assertCount(52, $plan->phaseSteps('write_fence'));
        $this->assertCount(7, $plan->phaseSteps('backfill'));

        $actor = FoundationSchema::actor();

        DB::beginTransaction();

        try {
            $studentId = $this->student($actor['organization_id']);
            $eventId = $this->scheduledCalendarEvent($actor, $studentId);
            $purchase = $this->settledPurchaseWithoutBookedAt($actor);

            $this->assertSame(0, DB::table('calendar_resource_claims')->where('claim_owner_id', $eventId)->count());
            $this->assertNull(DB::table('orders')->where('id', $purchase['order_id'])->value('booked_at'));

            $preflightExit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'preflight',
                '--force' => true,
            ]);
            $this->assertSame(0, $preflightExit, Artisan::output());

            $writeFenceExit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'write_fence',
                '--force' => true,
            ]);
            $this->assertSame(0, $writeFenceExit, Artisan::output());

            $backfillExit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'backfill',
                '--force' => true,
            ]);
            $this->assertSame(0, $backfillExit, Artisan::output());

            $this->forceDeferredChecks();
            $this->assertSame(
                1,
                DB::table('calendar_resource_claims')
                    ->where('claim_owner_kind', 'calendar_event')
                    ->where('claim_owner_id', $eventId)
                    ->where('student_id', $studentId)
                    ->count(),
            );
            $this->assertTrue(
                Carbon::parse($purchase['settled_at'])->equalTo(
                    Carbon::parse((string) DB::table('orders')->where('id', $purchase['order_id'])->value('booked_at')),
                ),
            );

            foreach ([
                'MIG-FK-PURCHASE_DOWNSTREAM',
                'MIG-FK-EVENTS',
                'MIG-TRG-EVENTS',
                'MIG-PRJ-ORGANIZATION-ACTIVITY',
                'MIG-PRJ-NOTIFICATIONS',
            ] as $nodeId) {
                $report = Stage4ExactEvidenceBackfill::run($nodeId);
                $this->assertSame(0, $report['mutated']);
                $this->assertGreaterThanOrEqual(0, $report['deferred_to_reconcile']);
            }

            $this->assertSame(
                ['mutated' => 0, 'deferred_to_reconcile' => 0],
                Stage4ExactEvidenceBackfill::run('MIG-PRJ-CALENDAR-RESOURCE-CLAIMS'),
            );
            $this->assertSame(
                ['mutated' => 0, 'deferred_to_reconcile' => 0],
                Stage4ExactEvidenceBackfill::run('MIG-PRJ-PURCHASE-HISTORY'),
            );
        } finally {
            DB::rollBack();
        }
    }

    public function test_unresolved_event_projection_is_persisted_as_idempotent_safe_migration_case(): void
    {
        FoundationSchema::reset();

        $plan = app(MigrationPlan::class);
        $plan->validate();
        $actor = FoundationSchema::actor();

        DB::beginTransaction();

        try {
            $activityId = $this->unresolvedLegacyActivity($actor);

            foreach (['preflight', 'write_fence', 'backfill'] as $phase) {
                $exit = Artisan::call('migration:controlled', [
                    '--plan' => $plan->identity(),
                    '--execution' => $plan->executionIdentity(),
                    '--phase' => $phase,
                    '--force' => true,
                ]);
                $this->assertSame(0, $exit, Artisan::output());
            }

            $case = DB::table('event_projection_migration_cases')
                ->where('source_table', 'organization_activity_events')
                ->where('source_row_id', $activityId)
                ->where('issue_code', 'activity_projection_contract_unresolved')
                ->first();

            $this->assertNotNull($case);
            $this->assertSame('partial_but_nonconflicting', $case->evidence_class);
            $this->assertSame('open', $case->resolution_state);
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $case->source_row_fingerprint);
            $this->assertNull($case->reviewed_by_user_id);
            $this->assertNull($case->reviewed_at);

            $report = Stage4ExactEvidenceBackfill::run('MIG-TRG-EVENTS');
            $this->assertSame(0, $report['mutated']);
            $this->assertGreaterThanOrEqual(1, $report['deferred_to_reconcile']);
            $this->assertSame(
                1,
                DB::table('event_projection_migration_cases')
                    ->where('source_table', 'organization_activity_events')
                    ->where('source_row_id', $activityId)
                    ->where('issue_code', 'activity_projection_contract_unresolved')
                    ->count(),
            );
        } finally {
            DB::rollBack();
        }
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

    private function student(string $organizationId): string
    {
        $id = (string) Str::uuid7();
        DB::table('students')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'first_name' => 'Legacy',
            'last_name' => 'Student',
            'birth_date' => '1990-01-01',
            'no_pesel_declared' => true,
            'pesel_ciphertext' => null,
            'pesel_lookup_hash' => null,
            'contact_email_normalized' => null,
            'phone' => null,
            'default_location_id' => null,
            'archived_at' => null,
            'archived_by_user_id' => null,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     */
    private function scheduledCalendarEvent(array $actor, string $studentId): string
    {
        $id = (string) Str::uuid7();
        DB::table('calendar_events')->insert([
            'id' => $id,
            'organization_id' => $actor['organization_id'],
            'event_type' => 'general_event',
            'name' => 'Legacy exact event',
            'starts_at' => '2026-10-01 10:00:00+00',
            'ends_at' => '2026-10-01 11:00:00+00',
            'student_id' => $studentId,
            'instructor_id' => null,
            'vehicle_id' => null,
            'location_id' => null,
            'custom_meeting_place' => null,
            'status' => 'scheduled',
            'created_by_user_id' => $actor['user_id'],
            'version' => 1,
            'completed_at' => null,
            'completed_by_user_id' => null,
            'cancelled_at' => null,
            'cancelled_by_user_id' => null,
            'cancellation_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @return array{order_id:string,settled_at:string}
     */
    private function settledPurchaseWithoutBookedAt(array $actor): array
    {
        $catalogId = (string) Str::uuid7();
        $orderId = (string) Str::uuid7();
        $itemId = (string) Str::uuid7();
        $paymentId = (string) Str::uuid7();
        $settledAt = now()->addSecond()->startOfSecond();
        $orderedAt = $settledAt->copy()->subMinute();

        DB::table('commerce_catalog_items')->insert([
            'id' => $catalogId,
            'code' => 'BACKFILL-'.$catalogId,
            'product_kind' => 'generic_service',
            'license_product_id' => null,
            'active' => true,
            'created_at' => $orderedAt,
        ]);
        DB::table('orders')->insert([
            'id' => $orderId,
            'organization_id' => $actor['organization_id'],
            'order_sequence' => 9001,
            'ordered_at' => $orderedAt,
            'booked_at' => null,
            'currency' => 'PLN',
            'total_amount_minor' => 1000,
            'zero_total_settled_at' => null,
            'created_by_user_id' => $actor['user_id'],
            'created_at' => $orderedAt,
            'updated_at' => $orderedAt,
        ]);
        DB::table('order_items')->insert([
            'id' => $itemId,
            'organization_id' => $actor['organization_id'],
            'order_id' => $orderId,
            'commerce_catalog_item_id' => $catalogId,
            'product_kind' => 'generic_service',
            'license_product_id' => null,
            'quantity' => 1,
            'currency' => 'PLN',
            'list_unit_amount_minor' => 1000,
            'unit_amount_minor' => 1000,
            'unit_discount_amount_minor' => 0,
            'vat_rate_basis_points' => 2300,
            'total_amount_minor' => 1000,
            'product_snapshot' => json_encode([
                'service_type' => 'legacy-safe-service',
                'activation_mode' => 'explicit',
            ], JSON_THROW_ON_ERROR),
            'pricing_snapshot' => json_encode([
                'list_unit_amount_minor' => 1000,
                'unit_amount_minor' => 1000,
                'unit_discount_amount_minor' => 0,
                'vat_rate_basis_points' => 2300,
            ], JSON_THROW_ON_ERROR),
            'snapshot_hash' => str_repeat('e', 64),
            'created_at' => $orderedAt,
        ]);
        DB::table('payments')->insert([
            'id' => $paymentId,
            'organization_id' => $actor['organization_id'],
            'order_id' => $orderId,
            'provider' => 'verified_test_rail',
            'provider_payment_id' => 'backfill-'.$paymentId,
            'public_payment_reference' => bin2hex(random_bytes(32)),
            'status' => 'confirmed',
            'amount_minor' => 1000,
            'currency' => 'PLN',
            'confirmed_at' => $settledAt,
            'failed_at' => null,
            'created_at' => $orderedAt,
        ]);
        DB::table('order_payment_settlements')->insert([
            'organization_id' => $actor['organization_id'],
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'settled_at' => $settledAt,
            'confirmation_source' => 'reconciliation',
            'source_payment_event_id' => null,
            'reconciled_by_user_id' => $actor['user_id'],
            'reconciliation_reason' => 'Exact preserved settlement evidence',
            'created_at' => $settledAt,
        ]);
        DB::table('order_fulfillments')->insert([
            'organization_id' => $actor['organization_id'],
            'order_id' => $orderId,
            'source_kind' => 'payment_settlement',
            'settlement_payment_id' => $paymentId,
            'state' => 'pending',
            'created_at' => $settledAt,
            'fulfilled_at' => null,
            'requires_reconciliation_at' => null,
            'reconciliation_reason' => null,
        ]);

        return [
            'order_id' => $orderId,
            'settled_at' => $settledAt->toDateTimeString(),
        ];
    }

    private function forceDeferredChecks(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }
}
