<?php

namespace Tests\Feature;

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4CandidateKeysWriteFenceTest extends TestCase
{
    public function test_eight_candidate_key_nodes_install_exact_unique_write_fences_after_full_preflight(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        $writeFenceNodes = [
            'MIG-CK-IDENTITY',
            'MIG-CK-ASSETS_RESOURCES',
            'MIG-CK-TRAINING',
            'MIG-CK-FINANCE',
            'MIG-CK-LICENSES',
            'MIG-CK-EXAMS',
            'MIG-CK-COMMERCE',
            'MIG-CK-EVENTS',
        ];

        $this->assertSame(170, $plan->nodeCount());
        $this->assertSame(170, $plan->implementedNodeCount());
        $this->assertSame(257, $plan->implementedStepCount());
        $this->assertSame('23e259f945fc619556180b7e047c36cdfbf098157b3774d1f647729169de1aa4', $plan->executionIdentity());
        $this->assertSame($writeFenceNodes, array_slice(array_column($plan->phaseSteps('write_fence'), 'node_id'), 0, count($writeFenceNodes)));
        $this->assertCount(52, $plan->phaseSteps('write_fence'));

        $expected = [
            'organization_membership_candidate_key_id_user' => [
                'organization_memberships',
                [
                    'id',
                    'user_id',
                ],
            ],
            'organization_membership_candidate_key_org_id' => [
                'organization_memberships',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'organization_membership_candidate_key_org_id_user' => [
                'organization_memberships',
                [
                    'organization_id',
                    'id',
                    'user_id',
                ],
            ],
            'auth_login_identifier_candidate_key_id_user' => [
                'auth_login_identifiers',
                [
                    'id',
                    'user_id',
                ],
            ],
            'file_asset_candidate_key_org_id' => [
                'file_assets',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'staff_profile_candidate_key_org_id' => [
                'staff_profiles',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'location_candidate_key_org_id' => [
                'locations',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'vehicle_candidate_key_org_id' => [
                'vehicles',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'student_candidate_key_org_id' => [
                'students',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'student_learning_account_candidate_key_org_id' => [
                'student_learning_accounts',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'student_learning_account_candidate_key_org_id_student' => [
                'student_learning_accounts',
                [
                    'organization_id',
                    'id',
                    'student_id',
                ],
            ],
            'course_enrollment_candidate_key_org_id' => [
                'course_enrollments',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'course_enrollment_candidate_key_org_id_student' => [
                'course_enrollments',
                [
                    'organization_id',
                    'id',
                    'student_id',
                ],
            ],
            'training_session_candidate_key_org_id' => [
                'training_sessions',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'student_charge_candidate_key_org_id_student_currency' => [
                'student_charges',
                [
                    'organization_id',
                    'id',
                    'student_id',
                    'currency',
                ],
            ],
            'license_inventory_entry_candidate_key_org_id' => [
                'license_inventory_entries',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'license_assignment_candidate_key_org_id' => [
                'license_assignments',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'internal_exam_inventory_entry_candidate_key_org_id' => [
                'internal_exam_inventory_entries',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'internal_exam_attempt_candidate_key_org_id' => [
                'internal_exam_attempts',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'internal_exam_access_candidate_key_org_id' => [
                'internal_exam_accesses',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'exam_station_candidate_key_org_id' => [
                'exam_stations',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'order_candidate_key_org_id' => [
                'orders',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'order_item_candidate_key_org_id' => [
                'order_items',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'order_item_candidate_key_org_id_product_kind' => [
                'order_items',
                [
                    'organization_id',
                    'id',
                    'product_kind',
                ],
            ],
            'order_item_candidate_key_org_id_license_product' => [
                'order_items',
                [
                    'organization_id',
                    'id',
                    'license_product_id',
                ],
            ],
            'order_item_candidate_key_org_id_catalog_item' => [
                'order_items',
                [
                    'organization_id',
                    'id',
                    'commerce_catalog_item_id',
                ],
            ],
            'audit_log_candidate_key_org_id' => [
                'audit_logs',
                [
                    'organization_id',
                    'id',
                ],
            ],
            'domain_event_candidate_key_org_id' => [
                'domain_events',
                [
                    'organization_id',
                    'id',
                ],
            ],
        ];

        $evidencePath = storage_path('framework/testing/stage4-candidate-keys-write-fence-'.getmypid().'.jsonl');
        @unlink($evidencePath);
        config(['migration.evidence_path' => $evidencePath]);

        $beforeNonUniqueConstraints = $this->nonUniqueConstraintFingerprint();
        $beforeUserTriggers = $this->userTriggerFingerprint();
        $beforeRowCounts = $this->rowCounts(array_values(array_unique(array_map(
            static fn (array $definition): string => $definition[0],
            $expected,
        ))));

        DB::beginTransaction();

        try {
            $preflightExit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'preflight',
                '--force' => true,
            ]);
            $this->assertSame(0, $preflightExit, Artisan::output());

            foreach (array_slice($plan->phaseSteps('write_fence'), 0, count($writeFenceNodes)) as $step) {
                ControlledMigrationContext::enter('write_fence', $step['node_id'], $plan->executionIdentity());

                try {
                    /** @var Migration $migration */
                    $migration = require base_path($step['migration_file']);
                    $migration->up();
                } finally {
                    ControlledMigrationContext::leave();
                }
            }

            foreach ($expected as $name => [$table, $columns]) {
                $actual = $this->uniqueConstraint($name);
                $this->assertNotNull($actual, "Missing candidate key {$name}.");
                $this->assertSame($table, $actual['table'], "Wrong table for {$name}.");
                $this->assertSame($columns, $actual['columns'], "Wrong columns for {$name}.");
            }

            $this->assertSame($beforeNonUniqueConstraints, $this->nonUniqueConstraintFingerprint());
            $this->assertSame($beforeUserTriggers, $this->userTriggerFingerprint());
            $this->assertSame($beforeRowCounts, $this->rowCounts(array_keys($beforeRowCounts)));
            $this->assertSame(
                [
                    'MIG-FK-PURCHASE_DOWNSTREAM',
                    'MIG-FK-EVENTS',
                    'MIG-TRG-EVENTS',
                    'MIG-PRJ-CALENDAR-RESOURCE-CLAIMS',
                    'MIG-PRJ-PURCHASE-HISTORY',
                    'MIG-PRJ-ORGANIZATION-ACTIVITY',
                    'MIG-PRJ-NOTIFICATIONS',
                ],
                array_column($plan->phaseSteps('backfill'), 'node_id'),
            );
            $this->assertCount(7, $plan->phaseSteps('reconcile'));
            $this->assertCount(34, $plan->phaseSteps('validate'));
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
     * @return list<string>
     */
    private function nonUniqueConstraintFingerprint(): array
    {
        return DB::table('pg_constraint as con')
            ->join('pg_class as cls', 'cls.oid', '=', 'con.conrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 'cls.relnamespace')
            ->whereRaw('ns.nspname = current_schema()')
            ->whereIn('con.contype', ['f', 'c', 'x'])
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
