<?php

namespace Tests\Feature;

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4ForeignKeysWriteFenceTest extends TestCase
{
    public function test_eleven_foreign_key_nodes_install_exact_not_valid_foreign_keys_with_required_target_keys(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        $foreignKeyNodes = [
            'MIG-FK-IDENTITY',
            'MIG-FK-RESOURCES',
            'MIG-FK-TRAINING',
            'MIG-FK-CALENDAR',
            'MIG-FK-PKK',
            'MIG-FK-FINANCE',
            'MIG-FK-LICENSES',
            'MIG-FK-EXAMS',
            'MIG-FK-COMMERCE',
            'MIG-FK-PURCHASE_DOWNSTREAM',
            'MIG-FK-EVENTS',
        ];

        $this->assertSame(170, $plan->nodeCount());
        $this->assertSame(170, $plan->implementedNodeCount());
        $this->assertSame(209, $plan->implementedStepCount());
        $this->assertSame('b0ea9422e89ef355be12c21a10580c202c8c4689f7fdf05908d59c94e81b6ae4', $plan->executionIdentity());
        $this->assertCount(52, $plan->phaseSteps('write_fence'));
        $this->assertSame(
            $foreignKeyNodes,
            array_slice(array_column($plan->phaseSteps('write_fence'), 'node_id'), 18, count($foreignKeyNodes)),
        );

        $supportingTargetKeys = [
            'training_session_candidate_key_org_id_course' => ['training_sessions', ['organization_id', 'id', 'course_enrollment_id']],
            'training_hour_ledger_candidate_key_org_id_course_part' => ['training_hour_ledger_entries', ['organization_id', 'id', 'course_enrollment_id', 'training_part']],
            'pkk_profile_candidate_key_org_id_course' => ['pkk_profiles', ['organization_id', 'id', 'course_enrollment_id']],
            'idempotency_record_candidate_key_org_id' => ['idempotency_records', ['organization_id', 'id']],
            'pkk_operation_candidate_key_org_id_context' => ['pkk_operations', ['organization_id', 'id', 'course_enrollment_id', 'pkk_profile_id']],
            'pkk_configuration_revision_candidate_key_org_revision' => ['pkk_integration_configuration_revisions', ['organization_id', 'execution_configuration_revision']],
            'pkk_signature_handoff_candidate_key_org_id_context' => ['pkk_signature_handoffs', ['organization_id', 'id', 'pkk_operation_id', 'course_enrollment_id', 'pkk_profile_id']],
            'pkk_operation_attempt_candidate_key_org_id_context' => ['pkk_operation_attempts', ['organization_id', 'id', 'pkk_operation_id', 'course_enrollment_id', 'pkk_profile_id']],
            'pkk_signature_handoff_candidate_key_org_id' => ['pkk_signature_handoffs', ['organization_id', 'id']],
            'license_product_language_capability_candidate_key_id_language' => ['license_product_language_capabilities', ['id', 'language_code']],
            'internal_exam_access_candidate_key_org_id_attempt' => ['internal_exam_accesses', ['organization_id', 'id', 'internal_exam_attempt_id']],
            'internal_exam_station_session_candidate_key_org_id_attempt' => ['internal_exam_station_sessions', ['organization_id', 'id', 'internal_exam_attempt_id']],
            'payment_candidate_key_org_id_provider_reference' => ['payments', ['organization_id', 'id', 'provider', 'provider_payment_id']],
            'payment_candidate_key_org_id_order' => ['payments', ['organization_id', 'id', 'order_id']],
            'payment_event_candidate_key_org_id_payment' => ['payment_events', ['organization_id', 'id', 'payment_id']],
            'order_payment_settlement_candidate_key_org_order_payment' => ['order_payment_settlements', ['organization_id', 'order_id', 'payment_id']],
            'service_entitlement_candidate_key_org_id' => ['service_entitlements', ['organization_id', 'id']],
            'activity_projection_policy_revision_candidate_key_event_version' => ['activity_projection_policy_revisions', ['event_type', 'policy_version']],
        ];
        $this->assertCount(18, $supportingTargetKeys);

        $targetTables = array_values(array_unique(array_map(
            static fn (array $definition): string => $definition[0],
            $supportingTargetKeys,
        )));

        $evidencePath = storage_path('framework/testing/stage4-foreign-keys-write-fence-'.getmypid().'.jsonl');
        @unlink($evidencePath);
        config(['migration.evidence_path' => $evidencePath]);

        $beforeChecks = $this->checkConstraintFingerprint();
        $beforeUserTriggers = $this->userTriggerFingerprint();
        $beforeRowCounts = $this->rowCounts($targetTables);

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

            $this->assertSame($foreignKeyNodes, array_slice(array_column($historicalWriteFenceSteps, 'node_id'), 18, 11));

            foreach ($supportingTargetKeys as $name => [$table, $columns]) {
                $actual = $this->uniqueConstraint($name);
                $this->assertNotNull($actual, 'Missing supporting FK target key '.$name.'.');
                $this->assertSame($table, $actual['table'], 'Wrong table for supporting target key '.$name.'.');
                $this->assertSame($columns, $actual['columns'], 'Wrong columns for supporting target key '.$name.'.');
            }

            $foreignKeys = $this->foreignKeyWriteFenceConstraints();
            $this->assertCount(125, $foreignKeys);
            $this->assertCount(125, array_unique(array_column($foreignKeys, 'name')));

            foreach ($foreignKeys as $foreignKey) {
                $this->assertStringStartsWith('fk_', $foreignKey['name']);
                $this->assertSame('f', $foreignKey['type']);
                $this->assertFalse($foreignKey['validated'], 'Write-fence FK must remain NOT VALID until validate phase: '.$foreignKey['name']);
                $this->assertSame('r', $foreignKey['update_action']);
                $this->assertSame('r', $foreignKey['delete_action']);
                $this->assertSame('s', $foreignKey['match_type']);
                $this->assertStringStartsWith('prawkonaraz:foreign-key-write-fence:v1:', $foreignKey['signature']);
            }

            $this->assertSame($beforeChecks, $this->checkConstraintFingerprint());
            $this->assertSame($beforeUserTriggers, $this->userTriggerFingerprint());
            $this->assertSame($beforeRowCounts, $this->rowCounts($targetTables));
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
     * @return array{table: string, columns: list<string>}|null
     */
    private function uniqueConstraint(string $name): ?array
    {
        $row = DB::selectOne(
            "SELECT cls.relname AS table_name,
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
               AND con.contype = 'u'
               AND con.conname = ?
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
            'columns' => array_values(array_map(static fn ($column): string => (string) $column, $columns)),
        ];
    }

    /**
     * @return list<array{
     *   name: string,
     *   type: string,
     *   validated: bool,
     *   update_action: string,
     *   delete_action: string,
     *   match_type: string,
     *   signature: string
     * }>
     */
    private function foreignKeyWriteFenceConstraints(): array
    {
        return DB::table('pg_constraint as con')
            ->join('pg_class as cls', 'cls.oid', '=', 'con.conrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 'cls.relnamespace')
            ->whereRaw('ns.nspname = current_schema()')
            ->where('con.contype', 'f')
            ->whereRaw("COALESCE(obj_description(con.oid, 'pg_constraint'), '') LIKE 'prawkonaraz:foreign-key-write-fence:v1:%'")
            ->orderBy('con.conname')
            ->get([
                'con.conname',
                'con.contype',
                'con.convalidated',
                'con.confupdtype',
                'con.confdeltype',
                'con.confmatchtype',
                DB::raw("COALESCE(obj_description(con.oid, 'pg_constraint'), '') AS signature"),
            ])
            ->map(static fn ($row): array => [
                'name' => (string) $row->conname,
                'type' => (string) $row->contype,
                'validated' => (bool) $row->convalidated,
                'update_action' => (string) $row->confupdtype,
                'delete_action' => (string) $row->confdeltype,
                'match_type' => (string) $row->confmatchtype,
                'signature' => (string) $row->signature,
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function checkConstraintFingerprint(): array
    {
        return DB::table('pg_constraint as con')
            ->join('pg_class as cls', 'cls.oid', '=', 'con.conrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 'cls.relnamespace')
            ->whereRaw('ns.nspname = current_schema()')
            ->where('con.contype', 'c')
            ->orderBy('cls.relname')
            ->orderBy('con.conname')
            ->get(['cls.relname', 'con.conname'])
            ->map(static fn ($row): string => $row->relname.'|'.$row->conname)
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
