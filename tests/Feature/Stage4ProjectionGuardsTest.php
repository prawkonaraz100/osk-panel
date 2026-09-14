<?php

namespace Tests\Feature;

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4ProjectionGuardsTest extends TestCase
{
    public function test_projection_write_fences_preserve_canonical_sources_and_projection_lifecycles(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        $this->assertSame(170, $plan->implementedNodeCount());
        $this->assertSame(209, $plan->implementedStepCount());
        $this->assertCount(52, $plan->phaseSteps('write_fence'));

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

            $triggers = $this->signedProjectionTriggers();
            $this->assertCount(10, $triggers);
            $this->assertCount(5, array_filter($triggers, static fn (array $row): bool => $row['constraint']));
            $this->assertCount(5, array_filter($triggers, static fn (array $row): bool => $row['deferrable'] && $row['initially_deferred']));

            $actor = FoundationSchema::actor();

            $this->expectImmediateGuardViolation(function () use ($actor): void {
                DB::table('calendar_resource_claims')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $actor['organization_id'],
                    'claim_owner_kind' => 'calendar_event',
                    'claim_owner_id' => (string) Str::uuid7(),
                    'student_id' => (string) Str::uuid7(),
                    'instructor_id' => null,
                    'vehicle_id' => null,
                    'location_id' => null,
                    'starts_at' => now(),
                    'ends_at' => now()->addHour(),
                    'created_at' => now(),
                ]);
            });

            $order = $this->unpaidOrder($actor, 991);
            $this->forceDeferredChecks();

            $this->expectDeferredGuardViolation(
                fn () => DB::table('orders')
                    ->where('id', $order)
                    ->update(['booked_at' => now()]),
            );

            $eventType = 'commerce.payment.started';
            $policyVersion = 1;
            DB::table('activity_projection_policy_revisions')->insert([
                'event_type' => $eventType,
                'policy_version' => $policyVersion,
                'payload_validator_code' => 'commerce.payment.safe.v1',
                'description_builder_code' => 'commerce.payment.started.v1',
                'actor_snapshot_rule_code' => 'required_audit_actor.v1',
                'subject_reference_rule_code' => 'snapshot_only.v1',
                'related_student_rule_code' => 'none.v1',
                'navigation_rule_code' => 'none.v1',
                'supports_expand' => false,
                'policy_hash' => hash('sha256', $eventType.'|'.$policyVersion),
                'created_at' => now(),
            ]);
            DB::table('activity_projection_policy_currents')->insert([
                'event_type' => $eventType,
                'policy_version' => $policyVersion,
                'updated_at' => now(),
            ]);

            $occurredAt = now();
            $sourceEvent = $this->sourceEvent($actor['organization_id'], $eventType, $occurredAt);
            $activityId = (string) Str::uuid7();
            DB::table('organization_activity_events')->insert([
                'id' => $activityId,
                'organization_id' => $actor['organization_id'],
                'source_event_id' => $sourceEvent,
                'projection_policy_version' => $policyVersion,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'description_snapshot' => 'Rozpoczęto płatność',
                'safe_payload' => json_encode(['amount_minor' => 1200, 'currency' => 'PLN'], JSON_THROW_ON_ERROR),
                'actor_reference_mode' => 'organization_membership',
                'actor_organization_membership_id' => $actor['membership_id'],
                'actor_user_id' => $actor['user_id'],
                'actor_display_name_snapshot' => 'Test Owner',
                'actor_role_snapshot' => 'Owner',
                'subject_reference_mode' => 'snapshot_only',
                'subject_type' => 'platform_payment',
                'subject_id' => (string) Str::uuid7(),
                'related_student_id' => null,
                'created_at' => now(),
            ]);

            $this->expectImmediateGuardViolation(
                fn () => DB::table('organization_activity_events')
                    ->where('id', $activityId)
                    ->update(['description_snapshot' => 'rewritten']),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('activity_projection_policy_revisions')
                    ->where('event_type', $eventType)
                    ->where('policy_version', $policyVersion)
                    ->update(['policy_hash' => str_repeat('f', 64)]),
            );

            $notificationEvent = $this->sourceEvent(
                $actor['organization_id'],
                'notification.projection.test',
                now(),
            );
            $notificationId = (string) Str::uuid7();
            DB::table('notifications')->insert([
                'id' => $notificationId,
                'organization_id' => $actor['organization_id'],
                'source_event_id' => $notificationEvent,
                'organization_membership_id' => $actor['membership_id'],
                'user_id' => $actor['user_id'],
                'audience_kind' => 'direct_membership',
                'type' => 'system_notice',
                'payload' => json_encode(['message' => 'Safe'], JSON_THROW_ON_ERROR),
                'read_at' => null,
                'created_at' => now(),
            ]);

            $clientTimestamp = Carbon::parse('2001-01-01T00:00:00+00:00');
            DB::table('notifications')->where('id', $notificationId)->update(['read_at' => $clientTimestamp]);
            $persistedReadAt = Carbon::parse((string) DB::table('notifications')->where('id', $notificationId)->value('read_at'));
            $this->assertNotSame($clientTimestamp->toIso8601String(), $persistedReadAt->toIso8601String());

            $this->expectImmediateGuardViolation(
                fn () => DB::table('notifications')
                    ->where('id', $notificationId)
                    ->update(['read_at' => now()->addMinute()]),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('notifications')
                    ->where('id', $notificationId)
                    ->update(['payload' => json_encode(['message' => 'rewritten'], JSON_THROW_ON_ERROR)]),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('notifications')->where('id', $notificationId)->delete(),
            );

            $inactiveMembership = FoundationSchema::member($actor['organization_id']);
            $inactiveUser = (string) DB::table('organization_memberships')
                ->where('id', $inactiveMembership)
                ->value('user_id');
            DB::table('organization_memberships')->where('id', $inactiveMembership)->update(['status' => 'suspended']);

            $inactiveEvent = $this->sourceEvent($actor['organization_id'], 'notification.inactive.test', now());
            $this->expectImmediateGuardViolation(function () use ($actor, $inactiveMembership, $inactiveUser, $inactiveEvent): void {
                DB::table('notifications')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $actor['organization_id'],
                    'source_event_id' => $inactiveEvent,
                    'organization_membership_id' => $inactiveMembership,
                    'user_id' => $inactiveUser,
                    'audience_kind' => 'direct_membership',
                    'type' => 'system_notice',
                    'payload' => json_encode(['message' => 'Safe'], JSON_THROW_ON_ERROR),
                    'read_at' => null,
                    'created_at' => now(),
                ]);
            });

            $broadcastMember = FoundationSchema::member($actor['organization_id']);
            $broadcastEvent = $this->sourceEvent($actor['organization_id'], 'notification.broadcast.test', now());
            $this->expectDeferredGuardViolation(function () use ($actor, $broadcastEvent): void {
                DB::table('notifications')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $actor['organization_id'],
                    'source_event_id' => $broadcastEvent,
                    'organization_membership_id' => $actor['membership_id'],
                    'user_id' => $actor['user_id'],
                    'audience_kind' => 'organization_broadcast',
                    'type' => 'system_notice',
                    'payload' => json_encode(['message' => 'Broadcast'], JSON_THROW_ON_ERROR),
                    'read_at' => null,
                    'created_at' => now(),
                ]);
            });
            $this->assertNotSame('', $broadcastMember);
        } finally {
            DB::rollBack();
        }
    }

    /** @param array{organization_id:string,user_id:string,membership_id:string,session_id:string} $actor */
    private function unpaidOrder(array $actor, int $sequence): string
    {
        $catalogId = (string) Str::uuid7();
        $orderId = (string) Str::uuid7();
        $itemId = (string) Str::uuid7();
        $now = now();

        DB::table('commerce_catalog_items')->insert([
            'id' => $catalogId,
            'code' => 'PROJECTION-'.$catalogId,
            'product_kind' => 'generic_service',
            'license_product_id' => null,
            'active' => true,
            'created_at' => $now,
        ]);
        DB::table('orders')->insert([
            'id' => $orderId,
            'organization_id' => $actor['organization_id'],
            'order_sequence' => $sequence,
            'ordered_at' => $now,
            'booked_at' => null,
            'currency' => 'PLN',
            'total_amount_minor' => 1000,
            'zero_total_settled_at' => null,
            'created_by_user_id' => $actor['user_id'],
            'created_at' => $now,
            'updated_at' => $now,
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
                'service_type' => 'projection-service',
                'activation_mode' => 'explicit',
            ], JSON_THROW_ON_ERROR),
            'pricing_snapshot' => json_encode([
                'list_unit_amount_minor' => 1000,
                'unit_amount_minor' => 1000,
                'unit_discount_amount_minor' => 0,
                'vat_rate_basis_points' => 2300,
            ], JSON_THROW_ON_ERROR),
            'snapshot_hash' => str_repeat('d', 64),
            'created_at' => $now,
        ]);

        return $orderId;
    }

    private function sourceEvent(string $organizationId, string $eventType, mixed $occurredAt): string
    {
        $id = (string) Str::uuid7();
        DB::table('domain_events')->insert([
            'id' => $id,
            'event_scope' => 'organization',
            'organization_id' => $organizationId,
            'event_type' => $eventType,
            'aggregate_reference_mode' => 'snapshot_only',
            'aggregate_type' => 'projection_test',
            'aggregate_id' => (string) Str::uuid7(),
            'request_id' => (string) Str::uuid7(),
            'causation_event_id' => null,
            'required_audit_log_id' => null,
            'occurred_at' => $occurredAt,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function forceDeferredChecks(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function expectImmediateGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_projection_immediate');

        try {
            $operation();
            $this->fail('Expected immediate projection write-fence violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_projection_immediate');
        }
    }

    private function expectDeferredGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_projection_deferred');

        try {
            $operation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Expected deferred projection write-fence violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_projection_deferred');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }

    /**
     * @return list<array{table:string,name:string,constraint:bool,deferrable:bool,initially_deferred:bool}>
     */
    private function signedProjectionTriggers(): array
    {
        return DB::table('pg_trigger as trg')
            ->join('pg_class as cls', 'cls.oid', '=', 'trg.tgrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 'cls.relnamespace')
            ->join('pg_proc as pro', 'pro.oid', '=', 'trg.tgfoid')
            ->whereRaw('ns.nspname = current_schema()')
            ->where('trg.tgisinternal', false)
            ->where('pro.proname', 'like', 'fn_guard_projection_%')
            ->whereRaw("COALESCE(obj_description(trg.oid, 'pg_trigger'), '') LIKE 'prawkonaraz:trigger-write-fence:v1:%'")
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
