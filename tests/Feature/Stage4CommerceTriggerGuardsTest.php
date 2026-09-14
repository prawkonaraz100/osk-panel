<?php

namespace Tests\Feature;

use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentService;
use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\TriggerGuards\CommerceTriggerGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4CommerceTriggerGuardsTest extends TestCase
{
    public function test_commerce_guards_preserve_payment_settlement_fulfillment_grants_and_course_cost_origin(): void
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

            CommerceTriggerGuards::install();

            $actor = $this->commerceActor();
            $catalogId = $this->catalogGenericService('trigger-service', 'explicit');
            $order = $this->insertOrderWithItem(
                $actor['organization_id'],
                $actor['user_id'],
                $catalogId,
                1,
                2,
                1000,
                null,
                null,
            );
            $this->forceDeferredChecks();

            $this->expectImmediateGuardViolation(
                fn () => DB::table('order_items')
                    ->where('id', $order['item_id'])
                    ->update(['quantity' => 3]),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('commerce_catalog_items')
                    ->where('id', $catalogId)
                    ->update(['code' => 'rewritten-code']),
            );

            $this->expectDeferredGuardViolation(function () use ($actor, $catalogId): void {
                $this->insertOrderWithItem(
                    $actor['organization_id'],
                    $actor['user_id'],
                    $catalogId,
                    2,
                    1,
                    1000,
                    999,
                    null,
                );
            });

            $paymentId = (string) Str::uuid7();
            $providerPaymentId = 'provider-'.str_replace('-', '', (string) Str::uuid7());
            DB::table('payments')->insert([
                'id' => $paymentId,
                'organization_id' => $actor['organization_id'],
                'order_id' => $order['order_id'],
                'provider' => 'test_provider',
                'provider_payment_id' => $providerPaymentId,
                'public_payment_reference' => bin2hex(random_bytes(32)),
                'status' => 'pending',
                'amount_minor' => 2000,
                'currency' => 'PLN',
                'confirmed_at' => null,
                'failed_at' => null,
                'created_at' => now(),
            ]);

            $eventId = (string) Str::uuid7();
            DB::table('payment_events')->insert([
                'id' => $eventId,
                'organization_id' => $actor['organization_id'],
                'payment_id' => $paymentId,
                'provider' => 'test_provider',
                'provider_payment_id' => $providerPaymentId,
                'provider_event_id' => 'event-'.str_replace('-', '', (string) Str::uuid7()),
                'event_type' => 'payment.confirmed',
                'normalized_outcome' => 'confirmed',
                'payload_hash' => str_repeat('a', 64),
                'received_at' => now(),
                'processed_at' => now(),
                'application_result' => 'state_applied',
            ]);

            $settledAt = now();
            DB::table('payments')->where('id', $paymentId)->update([
                'status' => 'confirmed',
                'confirmed_at' => $settledAt,
            ]);
            DB::table('order_payment_settlements')->insert([
                'organization_id' => $actor['organization_id'],
                'order_id' => $order['order_id'],
                'payment_id' => $paymentId,
                'settled_at' => $settledAt,
                'confirmation_source' => 'provider_event',
                'source_payment_event_id' => $eventId,
                'reconciled_by_user_id' => null,
                'reconciliation_reason' => null,
                'created_at' => $settledAt,
            ]);
            DB::table('orders')->where('id', $order['order_id'])->update([
                'booked_at' => $settledAt,
            ]);
            DB::table('order_fulfillments')->insert([
                'organization_id' => $actor['organization_id'],
                'order_id' => $order['order_id'],
                'source_kind' => 'payment_settlement',
                'settlement_payment_id' => $paymentId,
                'state' => 'pending',
                'created_at' => $settledAt,
                'fulfilled_at' => null,
                'requires_reconciliation_at' => null,
                'reconciliation_reason' => null,
            ]);

            $entitlementIds = [];
            foreach ([1, 2] as $ordinal) {
                $id = (string) Str::uuid7();
                $entitlementIds[] = $id;
                DB::table('service_entitlements')->insert([
                    'id' => $id,
                    'organization_id' => $actor['organization_id'],
                    'service_type' => 'trigger-service',
                    'activation_mode' => 'explicit',
                    'source_order_item_id' => $order['item_id'],
                    'source_order_item_grant_ordinal' => $ordinal,
                    'source_grant_reference' => null,
                    'status' => 'available',
                    'granted_at' => $settledAt,
                    'expires_at' => null,
                    'created_at' => $settledAt,
                ]);
            }
            DB::table('order_fulfillments')
                ->where('organization_id', $actor['organization_id'])
                ->where('order_id', $order['order_id'])
                ->update([
                    'state' => 'fulfilled',
                    'fulfilled_at' => now(),
                ]);
            $this->forceDeferredChecks();

            $this->expectImmediateGuardViolation(
                fn () => DB::table('payments')
                    ->where('id', $paymentId)
                    ->update(['status' => 'failed', 'confirmed_at' => null, 'failed_at' => now()]),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('payment_events')
                    ->where('id', $eventId)
                    ->update(['payload_hash' => str_repeat('b', 64)]),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('order_payment_settlements')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('order_id', $order['order_id'])
                    ->delete(),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('service_entitlements')
                    ->where('id', $entitlementIds[0])
                    ->update(['source_order_item_grant_ordinal' => 2]),
            );

            DB::table('service_activations')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $actor['organization_id'],
                'service_entitlement_id' => $entitlementIds[0],
                'activated_by_user_id' => $actor['user_id'],
                'activated_at' => now(),
                'effective_from' => now(),
                'effective_to' => null,
                'created_at' => now(),
            ]);
            DB::table('service_entitlements')->where('id', $entitlementIds[0])->update([
                'status' => 'activated',
            ]);
            $this->forceDeferredChecks();

            $activationId = (string) DB::table('service_activations')
                ->where('service_entitlement_id', $entitlementIds[0])
                ->value('id');
            $this->expectImmediateGuardViolation(
                fn () => DB::table('service_activations')
                    ->where('id', $activationId)
                    ->update(['effective_from' => now()->addMinute()]),
            );

            $this->expectDeferredGuardViolation(function () use ($actor, $catalogId): void {
                $now = now();
                $bad = $this->insertOrderWithItem(
                    $actor['organization_id'],
                    $actor['user_id'],
                    $catalogId,
                    3,
                    2,
                    0,
                    null,
                    $now,
                );
                DB::table('orders')->where('id', $bad['order_id'])->update(['booked_at' => $now]);
                DB::table('order_fulfillments')->insert([
                    'organization_id' => $actor['organization_id'],
                    'order_id' => $bad['order_id'],
                    'source_kind' => 'zero_total',
                    'settlement_payment_id' => null,
                    'state' => 'fulfilled',
                    'created_at' => $now,
                    'fulfilled_at' => $now,
                    'requires_reconciliation_at' => null,
                    'reconciliation_reason' => null,
                ]);
                DB::table('service_entitlements')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $actor['organization_id'],
                    'service_type' => 'trigger-service',
                    'activation_mode' => 'explicit',
                    'source_order_item_id' => $bad['item_id'],
                    'source_order_item_grant_ordinal' => 1,
                    'source_grant_reference' => null,
                    'status' => 'available',
                    'granted_at' => $now,
                    'expires_at' => null,
                    'created_at' => $now,
                ]);
            });

            $course = $this->courseFixture($actor);
            $chargeId = (string) Str::uuid7();
            DB::table('student_charges')->insert([
                'id' => $chargeId,
                'organization_id' => $actor['organization_id'],
                'student_id' => $course['student_id'],
                'course_enrollment_id' => $course['course_id'],
                'title' => 'Koszt kursu',
                'amount_minor' => 350000,
                'currency' => 'PLN',
                'due_at' => null,
                'created_by_user_id' => $actor['user_id'],
                'cancelled_at' => null,
                'cancelled_by_user_id' => null,
                'cancellation_reason' => null,
                'created_at' => now(),
            ]);
            $originId = (string) Str::uuid7();
            DB::table('course_cost_charge_origins')->insert([
                'id' => $originId,
                'organization_id' => $actor['organization_id'],
                'course_enrollment_id' => $course['course_id'],
                'student_id' => $course['student_id'],
                'student_charge_id' => $chargeId,
                'source_amount_minor' => 350000,
                'source_currency' => 'PLN',
                'created_at' => now(),
            ]);
            $this->forceDeferredChecks();

            $this->expectImmediateGuardViolation(
                fn () => DB::table('course_cost_charge_origins')
                    ->where('id', $originId)
                    ->update(['source_amount_minor' => 1]),
            );
            $this->expectDeferredGuardViolation(
                fn () => DB::table('student_charges')
                    ->where('id', $chargeId)
                    ->update(['amount_minor' => 349999]),
            );
        } finally {
            DB::rollBack();
        }
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function commerceActor(): array
    {
        $actor = FoundationSchema::actor();
        foreach ([
            'students.view',
            'students.create',
            'students.edit',
            'courses.view',
            'courses.create',
            'courses.edit',
            'course_requirements.correct',
            'purchases.view',
            'purchases.pay',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        return $actor;
    }

    private function catalogGenericService(string $serviceType, string $activationMode): string
    {
        $id = (string) Str::uuid7();
        DB::table('commerce_catalog_items')->insert([
            'id' => $id,
            'code' => 'SERVICE-'.str_replace('-', '', $id),
            'product_kind' => 'generic_service',
            'license_product_id' => null,
            'active' => true,
            'created_at' => now(),
        ]);

        return $id;
    }

    /**
     * @return array{order_id:string,item_id:string}
     */
    private function insertOrderWithItem(
        string $organizationId,
        string $userId,
        string $catalogId,
        int $sequence,
        int $quantity,
        int $unitAmount,
        ?int $totalOverride,
        mixed $zeroTotalSettledAt,
    ): array {
        $orderId = (string) Str::uuid7();
        $itemId = (string) Str::uuid7();
        $total = $quantity * $unitAmount;
        DB::table('orders')->insert([
            'id' => $orderId,
            'organization_id' => $organizationId,
            'order_sequence' => $sequence,
            'ordered_at' => now(),
            'booked_at' => null,
            'currency' => 'PLN',
            'total_amount_minor' => $totalOverride ?? $total,
            'zero_total_settled_at' => $zeroTotalSettledAt,
            'created_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'id' => $itemId,
            'organization_id' => $organizationId,
            'order_id' => $orderId,
            'commerce_catalog_item_id' => $catalogId,
            'product_kind' => 'generic_service',
            'license_product_id' => null,
            'quantity' => $quantity,
            'currency' => 'PLN',
            'list_unit_amount_minor' => $unitAmount,
            'unit_amount_minor' => $unitAmount,
            'unit_discount_amount_minor' => 0,
            'vat_rate_basis_points' => 2300,
            'total_amount_minor' => $total,
            'product_snapshot' => json_encode([
                'service_type' => 'trigger-service',
                'activation_mode' => 'explicit',
            ], JSON_THROW_ON_ERROR),
            'pricing_snapshot' => json_encode([
                'list_unit_amount_minor' => $unitAmount,
                'unit_amount_minor' => $unitAmount,
                'unit_discount_amount_minor' => 0,
                'vat_rate_basis_points' => 2300,
            ], JSON_THROW_ON_ERROR),
            'snapshot_hash' => str_repeat('c', 64),
            'created_at' => now(),
        ]);

        return ['order_id' => $orderId, 'item_id' => $itemId];
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @return array{student_id:string,course_id:string}
     */
    private function courseFixture(array $actor): array
    {
        $student = app(StudentService::class)->create(
            $actor['session_id'],
            [
                'first_name' => 'Anna',
                'last_name' => 'Commerce',
                'no_pesel' => true,
                'birth_date' => '1990-01-01',
            ],
            (string) Str::uuid7(),
        );
        $instructor = app(StaffService::class)->create(
            $actor['session_id'],
            [
                'email' => 'commerce.trigger.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
                'first_name' => 'Jan',
                'last_name' => 'Instruktor',
                'staff_type_codes' => ['Instructor'],
                'category_ids' => [],
                'location_ids' => [],
            ],
            (string) Str::uuid7(),
        );
        $course = app(CourseEnrollmentService::class)->create(
            $actor['session_id'],
            $student['id'],
            [
                'training_type' => 'basic',
                'driving_category_code' => 'B',
                'pkk_number' => 'PKK-'.str_replace('-', '', (string) Str::uuid7()),
                'started_at' => '2026-09-11T08:00:00+02:00',
                'declared_theory_minutes' => 0,
                'declared_practical_minutes' => 0,
                'recognized_external_theory_minutes' => 0,
                'recognized_external_practical_minutes' => 0,
                'lead_instructor_id' => $instructor['id'],
                'location_id' => null,
            ],
            (string) Str::uuid7(),
        );

        return [
            'student_id' => (string) $student['id'],
            'course_id' => (string) $course['id'],
        ];
    }

    private function forceDeferredChecks(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function expectImmediateGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_commerce_immediate');

        try {
            $operation();
            $this->fail('Expected immediate commerce trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_commerce_immediate');
        }
    }

    private function expectDeferredGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_commerce_deferred');

        try {
            $operation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Expected deferred commerce trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_commerce_deferred');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }
}
