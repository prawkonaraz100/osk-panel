<?php

namespace Tests\Feature;

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4ConstraintsWriteFenceTest extends TestCase
{
    public function test_ten_constraint_nodes_install_exact_not_valid_checks_without_scope_creep(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        $constraintNodes = [
            'MIG-CON-IDENTITY',
            'MIG-CON-RESOURCES',
            'MIG-CON-TRAINING',
            'MIG-CON-CALENDAR',
            'MIG-CON-PKK',
            'MIG-CON-FINANCE',
            'MIG-CON-LICENSES',
            'MIG-CON-EXAMS',
            'MIG-CON-COMMERCE',
            'MIG-CON-EVENTS',
        ];

        $this->assertSame(170, $plan->nodeCount());
        $this->assertSame(166, $plan->implementedNodeCount());
        $this->assertSame(205, $plan->implementedStepCount());
        $this->assertSame('15a237c1cf88deeb2a8943d243761e2d1abc6cadc83f93356983206306e20924', $plan->executionIdentity());
        $this->assertCount(48, $plan->phaseSteps('write_fence'));
        $this->assertSame(
            $constraintNodes,
            array_slice(array_column($plan->phaseSteps('write_fence'), 'node_id'), 29, 10),
        );

        $expectedChecks = [
            'organization_membership_status_closed' => 'organization_memberships',
            'organization_membership_version_positive' => 'organization_memberships',
            'organization_membership_authorization_version_positive' => 'organization_memberships',
            'revoked_membership_not_owner' => 'organization_memberships',
            'staff_pesel_pair_consistent' => 'staff_profiles',
            'staff_membership_link_time_order' => 'staff_membership_links',
            'staff_document_supersession_time_order' => 'staff_documents',
            'vehicle_document_supersession_time_order' => 'vehicle_documents',
            'student_identity_pair_and_declaration' => 'students',
            'course_stage_closed' => 'course_enrollments',
            'course_numeric_bounds' => 'course_enrollments',
            'course_terminal_matrix' => 'course_enrollments',
            'requirement_profile_numeric_bounds' => 'training_requirement_profiles',
            'training_session_time_and_status' => 'training_sessions',
            'training_session_terminal_matrix' => 'training_sessions',
            'attendance_status_closed' => 'training_session_attendance',
            'calendar_event_closed_state' => 'calendar_events',
            'calendar_event_meeting_place_xor' => 'calendar_events',
            'calendar_event_terminal_matrix' => 'calendar_events',
            'availability_slot_closed_state' => 'availability_slots',
            'availability_slot_state_matrix' => 'availability_slots',
            'calendar_claim_closed_shape' => 'calendar_resource_claims',
            'pkk_profile_revision_positive' => 'pkk_profiles',
            'pkk_operation_closed_state' => 'pkk_operations',
            'pkk_attempt_sequences_positive' => 'pkk_operation_attempts',
            'pkk_signature_handoff_shape' => 'pkk_signature_handoffs',
            'student_charge_cancellation_tuple' => 'student_charges',
            'student_payment_positive_and_reversal_tuple' => 'student_payments',
            'learning_account_closed_state' => 'student_learning_accounts',
            'license_inventory_closed_state_and_purchase_pair' => 'license_inventory_entries',
            'license_assignment_closed_state' => 'license_assignments',
            'license_activation_period' => 'license_activations',
            'exam_inventory_closed_state' => 'internal_exam_inventory_entries',
            'exam_inventory_ledger_closed_event' => 'internal_exam_inventory_ledger_entries',
            'exam_attempt_closed_state' => 'internal_exam_attempts',
            'exam_access_closed_state_and_mode' => 'internal_exam_accesses',
            'exam_station_session_end_tuple' => 'internal_exam_station_sessions',
            'order_numeric_and_time_bounds' => 'orders',
            'order_item_numeric_bounds' => 'order_items',
            'payment_closed_state' => 'payments',
            'fulfillment_closed_state' => 'order_fulfillments',
            'service_entitlement_source_xor' => 'service_entitlements',
            'service_activation_period' => 'service_activations',
            'audit_scope_actor_entity_closed' => 'audit_logs',
            'domain_event_scope_closed' => 'domain_events',
            'outbox_publication_state_matrix' => 'outbox_messages',
            'activity_projection_version_positive' => 'organization_activity_events',
            'notification_audience_closed' => 'notifications',
        ];
        $this->assertCount(48, $expectedChecks);

        DB::beginTransaction();

        try {
            $preflightExit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'preflight',
                '--force' => true,
            ]);
            $this->assertSame(0, $preflightExit, Artisan::output());

            $historicalWriteFenceSteps = array_slice($plan->phaseSteps('write_fence'), 0, 29);
            foreach ($historicalWriteFenceSteps as $step) {
                ControlledMigrationContext::enter('write_fence', $step['node_id'], $plan->executionIdentity());

                try {
                    /** @var Migration $migration */
                    $migration = require base_path($step['migration_file']);
                    $migration->up();
                } finally {
                    ControlledMigrationContext::leave();
                }
            }

            $tables = array_values(array_unique(array_values($expectedChecks)));
            $beforeIndexes = $this->indexFingerprint();
            $beforeUserTriggers = $this->userTriggerFingerprint();
            $beforeRows = $this->rowCounts($tables);

            $constraintSteps = array_slice($plan->phaseSteps('write_fence'), 29, 10);
            foreach ($constraintSteps as $step) {
                ControlledMigrationContext::enter('write_fence', $step['node_id'], $plan->executionIdentity());

                try {
                    /** @var Migration $migration */
                    $migration = require base_path($step['migration_file']);
                    $migration->up();
                } finally {
                    ControlledMigrationContext::leave();
                }
            }

            $this->assertSame($constraintNodes, array_column($constraintSteps, 'node_id'));

            $actual = $this->signedChecks();
            $this->assertCount(48, $actual);
            $expectedNames = array_keys($expectedChecks);
            sort($expectedNames);
            $this->assertSame($expectedNames, array_column($actual, 'name'));

            foreach ($actual as $check) {
                $this->assertSame($expectedChecks[$check['name']], $check['table']);
                $this->assertSame('c', $check['type']);
                $this->assertFalse($check['validated'], 'Write-fence CHECK must remain NOT VALID until validate phase: '.$check['name']);
                $this->assertStringStartsWith('prawkonaraz:constraint-write-fence:v1:', $check['signature']);
            }

            $this->assertSame($beforeIndexes, $this->indexFingerprint());
            $this->assertSame($beforeUserTriggers, $this->userTriggerFingerprint());
            $this->assertSame($beforeRows, $this->rowCounts($tables));
            $this->assertSame([], $plan->phaseSteps('backfill'));
            $this->assertSame([], $plan->phaseSteps('reconcile'));
            $this->assertSame([], $plan->phaseSteps('validate'));
            $this->assertSame([], $plan->phaseSteps('contract'));
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @return list<array{name: string, table: string, type: string, validated: bool, signature: string}>
     */
    private function signedChecks(): array
    {
        return DB::table('pg_constraint as con')
            ->join('pg_class as cls', 'cls.oid', '=', 'con.conrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 'cls.relnamespace')
            ->whereRaw('ns.nspname = current_schema()')
            ->where('con.contype', 'c')
            ->whereRaw("COALESCE(obj_description(con.oid, 'pg_constraint'), '') LIKE 'prawkonaraz:constraint-write-fence:v1:%'")
            ->orderBy('con.conname')
            ->get([
                'con.conname',
                'cls.relname as table_name',
                'con.contype',
                'con.convalidated',
                DB::raw("COALESCE(obj_description(con.oid, 'pg_constraint'), '') AS signature"),
            ])
            ->map(static fn ($row): array => [
                'name' => (string) $row->conname,
                'table' => (string) $row->table_name,
                'type' => (string) $row->contype,
                'validated' => (bool) $row->convalidated,
                'signature' => (string) $row->signature,
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function indexFingerprint(): array
    {
        return DB::table('pg_indexes')
            ->whereRaw('schemaname = current_schema()')
            ->orderBy('tablename')
            ->orderBy('indexname')
            ->get(['tablename', 'indexname', 'indexdef'])
            ->map(static fn ($row): string => implode('|', [(string) $row->tablename, (string) $row->indexname, (string) $row->indexdef]))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function userTriggerFingerprint(): array
    {
        return DB::table('pg_trigger as trg')
            ->join('pg_class as cls', 'cls.oid', '=', 'trg.tgrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 'cls.relnamespace')
            ->whereRaw('ns.nspname = current_schema()')
            ->where('trg.tgisinternal', false)
            ->orderBy('cls.relname')
            ->orderBy('trg.tgname')
            ->get(['cls.relname', 'trg.tgname'])
            ->map(static fn ($row): string => $row->relname.'|'.$row->tgname)
            ->all();
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function rowCounts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        ksort($counts);

        return $counts;
    }
}
