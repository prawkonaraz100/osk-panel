<?php

namespace Tests\Feature;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\TriggerGuards\EventsTriggerGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4EventsTriggerGuardsTest extends TestCase
{
    public function test_events_guards_preserve_audit_event_outbox_and_retention_evidence(): void
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

            $this->ensureSettingsAuditPolicy();
            EventsTriggerGuards::install();

            $triggers = $this->signedEventTriggers();
            $this->assertCount(9, $triggers);
            $this->assertCount(2, array_filter($triggers, static fn (array $row): bool => $row['constraint']));
            $this->assertCount(2, array_filter($triggers, static fn (array $row): bool => $row['deferrable'] && $row['initially_deferred']));

            $actor = FoundationSchema::actor();
            $requestId = (string) Str::uuid7();
            $triple = app(AtomicAuditOutbox::class)->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['membership_id'],
                $actor['user_id'],
                'organization.settings.updated',
                'organization_settings',
                $actor['organization_id'],
                $requestId,
                ['fields' => ['company_name'], 'version' => 1],
                ['fields' => ['company_name'], 'version' => 2],
            );
            $this->forceDeferredChecks();

            $this->expectImmediateGuardViolation(
                fn () => DB::table('audit_logs')
                    ->where('id', $triple['audit_log_id'])
                    ->update(['reason' => 'rewritten']),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('audit_logs')
                    ->where('id', $triple['audit_log_id'])
                    ->delete(),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('audit_action_policy_revisions')
                    ->where('action', 'organization.settings.updated')
                    ->where('policy_version', 1)
                    ->update(['policy_hash' => str_repeat('f', 64)]),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('audit_action_policy_currents')
                    ->where('action', 'organization.settings.updated')
                    ->update(['policy_version' => 999]),
            );

            $this->expectImmediateGuardViolation(function () use ($actor): void {
                DB::table('audit_logs')->insert([
                    'id' => (string) Str::uuid7(),
                    'audit_scope' => 'organization',
                    'organization_id' => $actor['organization_id'],
                    'actor_kind' => 'organization_membership',
                    'actor_organization_membership_id' => $actor['membership_id'],
                    'actor_user_id' => $actor['user_id'],
                    'action' => 'organization.settings.updated',
                    'audit_policy_version' => 1,
                    'entity_reference_mode' => 'snapshot_only',
                    'entity_type' => 'organization_settings',
                    'entity_id' => $actor['organization_id'],
                    'before_redacted_json' => json_encode(['token' => 'forbidden'], JSON_THROW_ON_ERROR),
                    'after_redacted_json' => json_encode(['fields' => ['company_name'], 'version' => 2], JSON_THROW_ON_ERROR),
                    'reason' => null,
                    'request_id' => (string) Str::uuid7(),
                    'created_at' => now(),
                ]);
            });

            $this->expectImmediateGuardViolation(
                fn () => DB::table('domain_events')
                    ->where('id', $triple['domain_event_id'])
                    ->update(['event_type' => 'rewritten.event']),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('outbox_messages')
                    ->where('id', $triple['outbox_message_id'])
                    ->update(['event_type' => 'rewritten.event']),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('outbox_messages')
                    ->where('id', $triple['outbox_message_id'])
                    ->update([
                        'publication_state' => 'published',
                        'published_at' => now(),
                    ]),
            );

            DB::table('outbox_messages')
                ->where('id', $triple['outbox_message_id'])
                ->update([
                    'publication_state' => 'leased',
                    'lease_token' => 'lease-'.str_replace('-', '', (string) Str::uuid7()),
                    'lease_version' => 1,
                    'leased_by' => 'worker-events-test',
                    'lease_expires_at' => now()->addMinutes(5),
                    'attempts' => 1,
                    'attempts_in_cycle' => 1,
                ]);
            DB::table('outbox_messages')
                ->where('id', $triple['outbox_message_id'])
                ->update([
                    'publication_state' => 'published',
                    'lease_token' => null,
                    'leased_by' => null,
                    'lease_expires_at' => null,
                    'published_at' => now(),
                ]);
            $this->forceDeferredChecks();

            $this->expectImmediateGuardViolation(
                fn () => DB::table('outbox_messages')
                    ->where('id', $triple['outbox_message_id'])
                    ->update(['publication_state' => 'pending', 'published_at' => null]),
            );

            $otherActor = FoundationSchema::actor();
            $otherAuditId = $this->insertAudit($otherActor, (string) Str::uuid7());
            $this->expectDeferredGuardViolation(function () use ($actor, $otherAuditId): void {
                DB::table('domain_events')->insert([
                    'id' => (string) Str::uuid7(),
                    'event_scope' => 'organization',
                    'organization_id' => $actor['organization_id'],
                    'event_type' => 'organization.settings.updated',
                    'aggregate_reference_mode' => 'snapshot_only',
                    'aggregate_type' => 'organization_settings',
                    'aggregate_id' => $actor['organization_id'],
                    'request_id' => (string) Str::uuid7(),
                    'causation_event_id' => null,
                    'required_audit_log_id' => $otherAuditId,
                    'occurred_at' => now(),
                    'created_at' => now(),
                ]);
            });

            $eventRequestId = (string) Str::uuid7();
            $auditId = $this->insertAudit($actor, $eventRequestId);
            $eventId = $this->insertDomainEvent($actor, $auditId, $eventRequestId);
            $this->forceDeferredChecks();

            $this->expectDeferredGuardViolation(function () use ($actor, $eventId, $eventRequestId): void {
                DB::table('outbox_messages')->insert([
                    'id' => (string) Str::uuid7(),
                    'domain_event_id' => $eventId,
                    'event_scope' => 'organization',
                    'organization_id' => $actor['organization_id'],
                    'aggregate_type' => 'organization_settings',
                    'aggregate_id' => $actor['organization_id'],
                    'event_type' => 'mismatched.event',
                    'payload' => json_encode(['domain_event_id' => $eventId], JSON_THROW_ON_ERROR),
                    'request_id' => $eventRequestId,
                    'publication_state' => 'pending',
                    'next_attempt_at' => now(),
                    'lease_version' => 0,
                    'attempts' => 0,
                    'attempts_in_cycle' => 0,
                    'replay_count' => 0,
                    'created_at' => now(),
                ]);
            });

            $retentionId = (string) Str::uuid7();
            DB::table('data_retention_execution_runs')->insert([
                'id' => $retentionId,
                'policy_version_reference' => 'privacy-v1',
                'data_class' => 'published_outbox',
                'cutoff_at' => now()->subMonth(),
                'organization_id' => $actor['organization_id'],
                'initiated_by_user_id' => $actor['user_id'],
                'reason' => 'approved policy execution evidence',
                'candidate_count' => 3,
                'deleted_or_redacted_count' => 3,
                'skipped_hold_count' => 0,
                'started_at' => now()->subMinute(),
                'completed_at' => now(),
                'result' => 'completed',
            ]);
            $this->expectImmediateGuardViolation(
                fn () => DB::table('data_retention_execution_runs')
                    ->where('id', $retentionId)
                    ->update(['reason' => 'rewritten']),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('data_retention_execution_runs')
                    ->where('id', $retentionId)
                    ->delete(),
            );
        } finally {
            DB::rollBack();
        }
    }

    private function ensureSettingsAuditPolicy(): void
    {
        DB::table('audit_action_policy_revisions')->updateOrInsert(
            ['action' => 'organization.settings.updated', 'policy_version' => 1],
            [
                'payload_validator_code' => 'foundation.settings.v1',
                'before_payload_requirement' => 'required',
                'after_payload_requirement' => 'required',
                'reason_requirement' => 'optional',
                'policy_hash' => hash('sha256', 'organization.settings.updated|1|foundation.settings.v1'),
                'created_at' => now(),
            ],
        );
        DB::table('audit_action_policy_currents')->updateOrInsert(
            ['action' => 'organization.settings.updated'],
            ['policy_version' => 1, 'updated_at' => now()],
        );
    }

    /** @param array{organization_id:string,user_id:string,membership_id:string,session_id:string} $actor */
    private function insertAudit(array $actor, string $requestId): string
    {
        $id = (string) Str::uuid7();
        DB::table('audit_logs')->insert([
            'id' => $id,
            'audit_scope' => 'organization',
            'organization_id' => $actor['organization_id'],
            'actor_kind' => 'organization_membership',
            'actor_organization_membership_id' => $actor['membership_id'],
            'actor_user_id' => $actor['user_id'],
            'action' => 'organization.settings.updated',
            'audit_policy_version' => 1,
            'entity_reference_mode' => 'snapshot_only',
            'entity_type' => 'organization_settings',
            'entity_id' => $actor['organization_id'],
            'before_redacted_json' => json_encode(['fields' => ['company_name'], 'version' => 1], JSON_THROW_ON_ERROR),
            'after_redacted_json' => json_encode(['fields' => ['company_name'], 'version' => 2], JSON_THROW_ON_ERROR),
            'reason' => null,
            'request_id' => $requestId,
            'created_at' => now(),
        ]);

        return $id;
    }

    /** @param array{organization_id:string,user_id:string,membership_id:string,session_id:string} $actor */
    private function insertDomainEvent(array $actor, string $auditId, string $requestId): string
    {
        $id = (string) Str::uuid7();
        DB::table('domain_events')->insert([
            'id' => $id,
            'event_scope' => 'organization',
            'organization_id' => $actor['organization_id'],
            'event_type' => 'organization.settings.updated',
            'aggregate_reference_mode' => 'snapshot_only',
            'aggregate_type' => 'organization_settings',
            'aggregate_id' => $actor['organization_id'],
            'request_id' => $requestId,
            'causation_event_id' => null,
            'required_audit_log_id' => $auditId,
            'occurred_at' => now(),
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
        DB::statement('SAVEPOINT stage4_events_immediate');

        try {
            $operation();
            $this->fail('Expected immediate events trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_events_immediate');
        }
    }

    private function expectDeferredGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_events_deferred');

        try {
            $operation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Expected deferred events trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_events_deferred');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }

    /**
     * @return list<array{
     *   table: string,
     *   constraint: bool,
     *   deferrable: bool,
     *   initially_deferred: bool
     * }>
     */
    private function signedEventTriggers(): array
    {
        return DB::table('pg_trigger as trg')
            ->join('pg_class as cls', 'cls.oid', '=', 'trg.tgrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 'cls.relnamespace')
            ->whereRaw('ns.nspname = current_schema()')
            ->where('trg.tgisinternal', false)
            ->whereRaw("COALESCE(obj_description(trg.oid, 'pg_trigger'), '') LIKE 'prawkonaraz:trigger-write-fence:v1:%'")
            ->whereIn('cls.relname', [
                'audit_action_policy_revisions',
                'audit_action_policy_currents',
                'audit_logs',
                'domain_events',
                'outbox_messages',
                'data_retention_execution_runs',
            ])
            ->orderBy('cls.relname')
            ->orderBy('trg.tgname')
            ->get([
                'cls.relname as table_name',
                DB::raw('CASE WHEN trg.tgconstraint <> 0 THEN 1 ELSE 0 END AS is_constraint'),
                DB::raw('CASE WHEN trg.tgdeferrable THEN 1 ELSE 0 END AS is_deferrable'),
                DB::raw('CASE WHEN trg.tginitdeferred THEN 1 ELSE 0 END AS is_initially_deferred'),
            ])
            ->map(static fn ($row): array => [
                'table' => (string) $row->table_name,
                'constraint' => (int) $row->is_constraint === 1,
                'deferrable' => (int) $row->is_deferrable === 1,
                'initially_deferred' => (int) $row->is_initially_deferred === 1,
            ])
            ->all();
    }
}
