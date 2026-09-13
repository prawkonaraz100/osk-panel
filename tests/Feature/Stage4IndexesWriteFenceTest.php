<?php

namespace Tests\Feature;

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4IndexesWriteFenceTest extends TestCase
{
    public function test_ten_index_nodes_install_exact_unique_indexes_and_calendar_exclusions_after_full_preflight(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        $indexNodes = [
            'MIG-IDX-IDENTITY',
            'MIG-IDX-RESOURCES',
            'MIG-IDX-TRAINING',
            'MIG-IDX-CALENDAR_GIST',
            'MIG-IDX-PKK',
            'MIG-IDX-FINANCE',
            'MIG-IDX-LICENSES',
            'MIG-IDX-EXAMS',
            'MIG-IDX-COMMERCE',
            'MIG-IDX-EVENTS',
        ];

        $this->assertSame(170, $plan->nodeCount());
        $this->assertSame(157, $plan->implementedNodeCount());
        $this->assertSame(186, $plan->implementedStepCount());
        $this->assertSame('1c3d384b6b52c68228111f08fb1a1d966f94b2281670a82550860f851cd4745d', $plan->executionIdentity());
        $this->assertCount(29, $plan->phaseSteps('write_fence'));
        $this->assertSame(
            $indexNodes,
            array_slice(array_column($plan->phaseSteps('write_fence'), 'node_id'), 8, count($indexNodes)),
        );

        $expectedIndexes = [
            [
                'name' => 'auth_login_identifier_global_current_unique',
                'table' => 'auth_login_identifiers',
                'columns' => ['identifier_normalized'],
                'predicate' => 'revoked_at IS NULL',
            ],
            [
                'name' => 'auth_login_identifier_primary_current_unique_per_user_type',
                'table' => 'auth_login_identifiers',
                'columns' => ['user_id', 'identifier_type'],
                'predicate' => 'revoked_at IS NULL AND is_primary_for_type = true',
            ],
            [
                'name' => 'organization_membership_unique_org_user',
                'table' => 'organization_memberships',
                'columns' => ['organization_id', 'user_id'],
                'predicate' => null,
            ],
            [
                'name' => 'membership_permission_unique',
                'table' => 'membership_permissions',
                'columns' => ['membership_id', 'permission_code'],
                'predicate' => null,
            ],
            [
                'name' => 'membership_permission_scope_unique',
                'table' => 'membership_permission_scopes',
                'columns' => ['membership_id', 'permission_code', 'scope_code'],
                'predicate' => null,
            ],
            [
                'name' => 'account_closure_pending_unique_organization_scope',
                'table' => 'account_closure_requests',
                'columns' => ['user_id', 'organization_id'],
                'predicate' => 'status = \'pending\' AND organization_id IS NOT NULL',
            ],
            [
                'name' => 'account_closure_pending_unique_global_scope',
                'table' => 'account_closure_requests',
                'columns' => ['user_id'],
                'predicate' => 'status = \'pending\' AND organization_id IS NULL',
            ],
            [
                'name' => 'staff_active_membership_link_unique_per_profile',
                'table' => 'staff_membership_links',
                'columns' => ['staff_profile_id'],
                'predicate' => 'unlinked_at IS NULL',
            ],
            [
                'name' => 'staff_active_membership_link_unique_per_membership',
                'table' => 'staff_membership_links',
                'columns' => ['organization_membership_id'],
                'predicate' => 'unlinked_at IS NULL',
            ],
            [
                'name' => 'staff_pesel_unique_per_organization_including_archived',
                'table' => 'staff_profiles',
                'columns' => ['organization_id', 'pesel_lookup_hash'],
                'predicate' => 'pesel_lookup_hash IS NOT NULL',
            ],
            [
                'name' => 'staff_document_current_unique',
                'table' => 'staff_documents',
                'columns' => ['organization_id', 'staff_profile_id', 'document_type'],
                'predicate' => 'superseded_at IS NULL',
            ],
            [
                'name' => 'vehicle_vin_unique_per_organization_including_archived',
                'table' => 'vehicles',
                'columns' => ['organization_id', 'vin_normalized'],
                'predicate' => 'vin_normalized IS NOT NULL',
            ],
            [
                'name' => 'vehicle_registration_unique_per_organization_current_fleet',
                'table' => 'vehicles',
                'columns' => ['organization_id', 'registration_number_normalized'],
                'predicate' => 'archived_at IS NULL',
            ],
            [
                'name' => 'vehicle_document_current_unique',
                'table' => 'vehicle_documents',
                'columns' => ['organization_id', 'vehicle_id', 'document_type'],
                'predicate' => 'superseded_at IS NULL',
            ],
            [
                'name' => 'student_pesel_unique_per_organization_including_archived',
                'table' => 'students',
                'columns' => ['organization_id', 'pesel_lookup_hash'],
                'predicate' => 'pesel_lookup_hash IS NOT NULL',
            ],
            [
                'name' => 'training_requirement_profile_current_unique',
                'table' => 'training_requirement_profiles',
                'columns' => ['organization_id', 'course_enrollment_id'],
                'predicate' => 'superseded_at IS NULL',
            ],
            [
                'name' => 'recognized_external_training_one_successor_per_source',
                'table' => 'recognized_external_training',
                'columns' => ['organization_id', 'supersedes_record_id'],
                'predicate' => 'supersedes_record_id IS NOT NULL',
            ],
            [
                'name' => 'recognized_external_training_current_course_form_projection_unique',
                'table' => 'recognized_external_training',
                'columns' => ['organization_id', 'course_enrollment_id', 'training_part'],
                'predicate' => 'record_role = \'course_form_projection\' AND superseded_at IS NULL AND revoked_at IS NULL',
            ],
            [
                'name' => 'training_hour_ledger_base_credit_unique',
                'table' => 'training_hour_ledger_entries',
                'columns' => ['organization_id', 'training_session_id'],
                'predicate' => 'entry_type = \'credit\'',
            ],
            [
                'name' => 'training_hour_ledger_opening_balance_unique',
                'table' => 'training_hour_ledger_entries',
                'columns' => ['organization_id', 'course_enrollment_id', 'training_part'],
                'predicate' => 'entry_type = \'opening_balance\'',
            ],
            [
                'name' => 'training_hour_ledger_reversal_unique',
                'table' => 'training_hour_ledger_entries',
                'columns' => ['organization_id', 'source_entry_id'],
                'predicate' => 'entry_type = \'reversal\'',
            ],
            [
                'name' => 'pkk_one_current_profile_per_course',
                'table' => 'pkk_profiles',
                'columns' => ['organization_id', 'course_enrollment_id'],
                'predicate' => 'superseded_at IS NULL',
            ],
            [
                'name' => 'pkk_one_active_operation_per_profile',
                'table' => 'pkk_operations',
                'columns' => ['organization_id', 'pkk_profile_id'],
                'predicate' => 'business_status IN (\'draft\', \'pending\', \'requires_signature\', \'submitted\')',
            ],
            [
                'name' => 'pkk_operation_course_sequence_unique',
                'table' => 'pkk_operations',
                'columns' => ['organization_id', 'course_enrollment_id', 'course_operation_sequence'],
                'predicate' => null,
            ],
            [
                'name' => 'pkk_provider_attempt_idempotency_unique',
                'table' => 'pkk_operation_attempts',
                'columns' => ['organization_id', 'command_idempotency_record_id'],
                'predicate' => 'command_idempotency_record_id IS NOT NULL',
            ],
            [
                'name' => 'pkk_operation_attempt_sequence_unique',
                'table' => 'pkk_operation_attempts',
                'columns' => ['organization_id', 'pkk_operation_id', 'attempt_no'],
                'predicate' => null,
            ],
            [
                'name' => 'pkk_one_replay_blocking_attempt_per_operation',
                'table' => 'pkk_operation_attempts',
                'columns' => ['organization_id', 'pkk_operation_id'],
                'predicate' => 'attempt_dispatch_status IN (\'prepared\', \'dispatching\', \'effect_unknown\')',
            ],
            [
                'name' => 'course_cost_charge_origin_unique_per_course',
                'table' => 'course_cost_charge_origins',
                'columns' => ['organization_id', 'course_enrollment_id'],
                'predicate' => null,
            ],
            [
                'name' => 'license_current_assignment_unique_per_inventory',
                'table' => 'license_assignments',
                'columns' => ['organization_id', 'license_inventory_entry_id'],
                'predicate' => 'status IN (\'assigned\', \'activated\') AND revoked_at IS NULL',
            ],
            [
                'name' => 'license_assignment_sequence_unique',
                'table' => 'license_assignments',
                'columns' => ['organization_id', 'student_learning_account_id', 'assignment_sequence'],
                'predicate' => null,
            ],
            [
                'name' => 'license_activation_unique_per_assignment',
                'table' => 'license_activations',
                'columns' => ['organization_id', 'license_assignment_id'],
                'predicate' => null,
            ],
            [
                'name' => 'internal_exam_inventory_ledger_sequence_unique',
                'table' => 'internal_exam_inventory_ledger_entries',
                'columns' => ['organization_id', 'internal_exam_inventory_entry_id', 'event_sequence'],
                'predicate' => null,
            ],
            [
                'name' => 'internal_exam_course_attempt_sequence_unique',
                'table' => 'internal_exam_attempts',
                'columns' => ['organization_id', 'course_enrollment_id', 'course_attempt_sequence'],
                'predicate' => null,
            ],
            [
                'name' => 'internal_exam_one_active_reservation_per_attempt',
                'table' => 'internal_exam_reservations',
                'columns' => ['organization_id', 'internal_exam_attempt_id'],
                'predicate' => 'status = \'reserved\'',
            ],
            [
                'name' => 'internal_exam_one_consumed_reservation_per_attempt',
                'table' => 'internal_exam_reservations',
                'columns' => ['organization_id', 'internal_exam_attempt_id'],
                'predicate' => 'status = \'consumed\'',
            ],
            [
                'name' => 'internal_exam_one_nonterminal_access_per_attempt',
                'table' => 'internal_exam_accesses',
                'columns' => ['organization_id', 'internal_exam_attempt_id'],
                'predicate' => 'status IN (\'draft\', \'ready\', \'delivered_or_assigned\', \'opened\', \'started\')',
            ],
            [
                'name' => 'internal_exam_one_started_access_per_attempt',
                'table' => 'internal_exam_accesses',
                'columns' => ['organization_id', 'internal_exam_attempt_id'],
                'predicate' => 'started_at IS NOT NULL',
            ],
            [
                'name' => 'internal_exam_one_active_station_session_per_attempt',
                'table' => 'internal_exam_station_sessions',
                'columns' => ['organization_id', 'internal_exam_attempt_id'],
                'predicate' => 'ended_at IS NULL',
            ],
            [
                'name' => 'internal_exam_one_active_station_session_per_station',
                'table' => 'internal_exam_station_sessions',
                'columns' => ['organization_id', 'exam_station_id'],
                'predicate' => 'ended_at IS NULL',
            ],
            [
                'name' => 'internal_exam_station_session_sequence_unique',
                'table' => 'internal_exam_station_sessions',
                'columns' => ['organization_id', 'internal_exam_attempt_id', 'session_sequence'],
                'predicate' => null,
            ],
            [
                'name' => 'commerce_order_sequence_unique_per_tenant',
                'table' => 'orders',
                'columns' => ['organization_id', 'order_sequence'],
                'predicate' => null,
            ],
            [
                'name' => 'license_inventory_source_order_item_ordinal_unique',
                'table' => 'license_inventory_entries',
                'columns' => ['organization_id', 'source_order_item_id', 'source_order_item_grant_ordinal'],
                'predicate' => 'source_order_item_id IS NOT NULL',
            ],
            [
                'name' => 'internal_exam_inventory_source_order_item_ordinal_unique',
                'table' => 'internal_exam_inventory_entries',
                'columns' => ['organization_id', 'source_order_item_id', 'source_order_item_grant_ordinal'],
                'predicate' => 'source_type = \'paid\' AND source_order_item_id IS NOT NULL',
            ],
            [
                'name' => 'payment_provider_event_unique',
                'table' => 'payment_events',
                'columns' => ['provider', 'provider_event_id'],
                'predicate' => null,
            ],
            [
                'name' => 'order_payment_settlement_unique_per_order',
                'table' => 'order_payment_settlements',
                'columns' => ['organization_id', 'order_id'],
                'predicate' => null,
            ],
            [
                'name' => 'order_fulfillment_unique_per_order',
                'table' => 'order_fulfillments',
                'columns' => ['organization_id', 'order_id'],
                'predicate' => null,
            ],
            [
                'name' => 'service_entitlement_purchase_ordinal_unique',
                'table' => 'service_entitlements',
                'columns' => ['organization_id', 'source_order_item_id', 'source_order_item_grant_ordinal'],
                'predicate' => 'source_order_item_id IS NOT NULL',
            ],
            [
                'name' => 'service_activation_unique_per_entitlement',
                'table' => 'service_activations',
                'columns' => ['organization_id', 'service_entitlement_id'],
                'predicate' => null,
            ],
            [
                'name' => 'outbox_domain_event_unique',
                'table' => 'outbox_messages',
                'columns' => ['domain_event_id'],
                'predicate' => null,
            ],
            [
                'name' => 'activity_projection_source_event_unique',
                'table' => 'organization_activity_events',
                'columns' => ['organization_id', 'source_event_id'],
                'predicate' => null,
            ],
            [
                'name' => 'notification_source_recipient_unique',
                'table' => 'notifications',
                'columns' => ['organization_id', 'source_event_id', 'organization_membership_id'],
                'predicate' => null,
            ],
            [
                'name' => 'event_projection_migration_case_unique',
                'table' => 'event_projection_migration_cases',
                'columns' => ['source_table', 'source_row_id', 'issue_code'],
                'predicate' => null,
            ],
        ];
        $expectedExclusions = [
            [
                'name' => 'calendar_claim_student_no_overlap',
                'table' => 'calendar_resource_claims',
                'columns' => ['organization_id', 'student_id', 'occupied_during'],
                'predicate' => 'student_id IS NOT NULL',
            ],
            [
                'name' => 'calendar_claim_instructor_no_overlap',
                'table' => 'calendar_resource_claims',
                'columns' => ['organization_id', 'instructor_id', 'occupied_during'],
                'predicate' => 'instructor_id IS NOT NULL',
            ],
            [
                'name' => 'calendar_claim_vehicle_no_overlap',
                'table' => 'calendar_resource_claims',
                'columns' => ['organization_id', 'vehicle_id', 'occupied_during'],
                'predicate' => 'vehicle_id IS NOT NULL',
            ],
            [
                'name' => 'calendar_claim_location_no_overlap',
                'table' => 'calendar_resource_claims',
                'columns' => ['organization_id', 'location_id', 'occupied_during'],
                'predicate' => 'location_id IS NOT NULL',
            ],
        ];

        $this->assertCount(52, $expectedIndexes);
        $this->assertCount(4, $expectedExclusions);
        $this->assertFalse(Schema::hasColumn('student_payments', 'idempotency_key'));

        $tables = array_values(array_unique(array_merge(
            array_map(static fn (array $definition): string => $definition['table'], $expectedIndexes),
            array_map(static fn (array $definition): string => $definition['table'], $expectedExclusions),
        )));

        $evidencePath = storage_path('framework/testing/stage4-indexes-write-fence-'.getmypid().'.jsonl');
        @unlink($evidencePath);
        config(['migration.evidence_path' => $evidencePath]);

        $beforeForeignAndCheckConstraints = $this->foreignAndCheckConstraintFingerprint();
        $beforeUserTriggers = $this->userTriggerFingerprint();
        $beforeRowCounts = $this->rowCounts($tables);

        DB::beginTransaction();

        try {
            $preflightExit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'preflight',
                '--force' => true,
            ]);
            $this->assertSame(0, $preflightExit, Artisan::output());

            $historicalWriteFenceSteps = array_slice($plan->phaseSteps('write_fence'), 0, 18);
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

            $this->assertSame(
                array_merge([
                    'MIG-CK-IDENTITY',
                    'MIG-CK-ASSETS_RESOURCES',
                    'MIG-CK-TRAINING',
                    'MIG-CK-FINANCE',
                    'MIG-CK-LICENSES',
                    'MIG-CK-EXAMS',
                    'MIG-CK-COMMERCE',
                    'MIG-CK-EVENTS',
                ], $indexNodes),
                array_column($historicalWriteFenceSteps, 'node_id'),
            );

            foreach ($expectedIndexes as $definition) {
                $actual = $this->uniqueIndex($definition['name']);
                $this->assertNotNull($actual, 'Missing unique index '.$definition['name'].'.');
                $this->assertSame($definition['table'], $actual['table'], 'Wrong table for '.$definition['name'].'.');
                $this->assertTrue($actual['unique'], 'Expected unique index '.$definition['name'].'.');
                $this->assertSame('btree', $actual['method'], 'Wrong access method for '.$definition['name'].'.');
                $this->assertSame($definition['columns'], $actual['columns'], 'Wrong columns for '.$definition['name'].'.');
                $this->assertSame(
                    $this->signature('unique_index', $definition),
                    $actual['signature'],
                    'Wrong definition signature for '.$definition['name'].'.',
                );
            }

            foreach ($expectedExclusions as $definition) {
                $actual = $this->exclusionConstraint($definition['table'], $definition['name']);
                $this->assertNotNull($actual, 'Missing exclusion constraint '.$definition['name'].'.');
                $this->assertSame('x', $actual['type'], 'Wrong constraint type for '.$definition['name'].'.');
                $this->assertSame($definition['columns'], $actual['columns'], 'Wrong columns for '.$definition['name'].'.');
                $this->assertSame(
                    $this->signature('exclusion_constraint', $definition),
                    $actual['signature'],
                    'Wrong definition signature for '.$definition['name'].'.',
                );
            }

            $this->assertFalse(Schema::hasColumn('student_payments', 'idempotency_key'));
            $this->assertNull($this->uniqueIndex('student_payment_idempotency_unique'));
            $this->assertNull($this->uniqueIndex('student_payment_idempotency_unique_legacy_compatibility_only'));

            $this->assertSame($beforeForeignAndCheckConstraints, $this->foreignAndCheckConstraintFingerprint());
            $this->assertSame($beforeUserTriggers, $this->userTriggerFingerprint());
            $this->assertSame($beforeRowCounts, $this->rowCounts($tables));
            $this->assertSame([], $plan->phaseSteps('backfill'));
            $this->assertSame([], $plan->phaseSteps('reconcile'));
            $this->assertSame([], $plan->phaseSteps('validate'));
            $this->assertSame([], $plan->phaseSteps('contract'));
        } finally {
            DB::rollBack();
            @unlink($evidencePath);
            config(['migration.evidence_path' => null]);
        }
    }

    /**
     * @return array{table: string, unique: bool, method: string, columns: list<string>, signature: string}|null
     */
    private function uniqueIndex(string $name): ?array
    {
        $row = DB::selectOne(
            "SELECT tbl.relname AS table_name,
                    CASE WHEN idx.indisunique THEN 1 ELSE 0 END AS is_unique,
                    am.amname AS access_method,
                    COALESCE(obj_description(index_cls.oid, 'pg_class'), '') AS signature,
                    COALESCE((
                        SELECT json_agg(att.attname ORDER BY key_column.ordinal_position)::text
                        FROM unnest(idx.indkey) WITH ORDINALITY AS key_column(attnum, ordinal_position)
                        JOIN pg_attribute att
                          ON att.attrelid = idx.indrelid
                         AND att.attnum = key_column.attnum
                        WHERE key_column.ordinal_position <= idx.indnkeyatts
                    ), '[]') AS columns_json
             FROM pg_class index_cls
             JOIN pg_namespace index_ns ON index_ns.oid = index_cls.relnamespace
             JOIN pg_index idx ON idx.indexrelid = index_cls.oid
             JOIN pg_class tbl ON tbl.oid = idx.indrelid
             JOIN pg_am am ON am.oid = index_cls.relam
             WHERE index_ns.nspname = current_schema()
               AND index_cls.relkind = 'i'
               AND index_cls.relname = ?
             LIMIT 1",
            [$name],
        );

        if ($row === null) {
            return null;
        }

        $columns = json_decode((string) ($row->columns_json ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($columns);

        return [
            'table' => (string) ($row->table_name ?? ''),
            'unique' => (int) ($row->is_unique ?? 0) === 1,
            'method' => (string) ($row->access_method ?? ''),
            'columns' => array_values(array_map(static fn ($column): string => (string) $column, $columns)),
            'signature' => (string) ($row->signature ?? ''),
        ];
    }

    /**
     * @return array{type: string, columns: list<string>, signature: string}|null
     */
    private function exclusionConstraint(string $table, string $name): ?array
    {
        $row = DB::selectOne(
            "SELECT con.contype AS constraint_type,
                    COALESCE(obj_description(con.oid, 'pg_constraint'), '') AS signature,
                    COALESCE((
                        SELECT json_agg(att.attname ORDER BY key_column.ordinal_position)::text
                        FROM unnest(con.conkey) WITH ORDINALITY AS key_column(attnum, ordinal_position)
                        JOIN pg_attribute att
                          ON att.attrelid = con.conrelid
                         AND att.attnum = key_column.attnum
                    ), '[]') AS columns_json
             FROM pg_constraint con
             JOIN pg_class cls ON cls.oid = con.conrelid
             JOIN pg_namespace ns ON ns.oid = cls.relnamespace
             WHERE ns.nspname = current_schema()
               AND cls.relname = ?
               AND con.conname = ?
             LIMIT 1",
            [$table, $name],
        );

        if ($row === null) {
            return null;
        }

        $columns = json_decode((string) ($row->columns_json ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($columns);

        return [
            'type' => (string) ($row->constraint_type ?? ''),
            'columns' => array_values(array_map(static fn ($column): string => (string) $column, $columns)),
            'signature' => (string) ($row->signature ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function signature(string $kind, array $definition): string
    {
        return 'prawkonaraz:index-write-fence:v1:'.hash(
            'sha256',
            json_encode(array_merge(['kind' => $kind], $definition), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return list<string>
     */
    private function foreignAndCheckConstraintFingerprint(): array
    {
        return DB::table('pg_constraint as con')
            ->join('pg_class as cls', 'cls.oid', '=', 'con.conrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 'cls.relnamespace')
            ->whereRaw('ns.nspname = current_schema()')
            ->whereIn('con.contype', ['f', 'c'])
            ->orderBy('cls.relname')
            ->orderBy('con.conname')
            ->get(['cls.relname', 'con.conname', 'con.contype'])
            ->map(static fn ($row): string => $row->relname.'|'.$row->conname.'|'.$row->contype)
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
