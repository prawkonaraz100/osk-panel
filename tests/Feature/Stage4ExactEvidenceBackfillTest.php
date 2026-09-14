<?php

namespace Tests\Feature;

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\Stage4ExactEvidenceBackfill;
use Illuminate\Database\Migrations\Migration;
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

            $calendar = Stage4ExactEvidenceBackfill::run('MIG-PRJ-CALENDAR-RESOURCE-CLAIMS');
            $history = Stage4ExactEvidenceBackfill::run('MIG-PRJ-PURCHASE-HISTORY');

            $this->forceDeferredChecks();

            $this->assertSame(['mutated' => 1, 'deferred_to_reconcile' => 0], $calendar);
            $this->assertSame(['mutated' => 1, 'deferred_to_reconcile' => 0], $history);
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
