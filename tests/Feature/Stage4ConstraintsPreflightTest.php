<?php

namespace Tests\Feature;

use App\Support\Migrations\MigrationPlan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4ConstraintsPreflightTest extends TestCase
{
    public function test_ten_constraint_nodes_complete_global_preflight_without_entering_write_fence(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        $this->assertSame(170, $plan->nodeCount());
        $this->assertSame(157, $plan->implementedNodeCount());
        $this->assertSame(157, $plan->implementedStepCount());
        $this->assertSame('7cdea7410b15a1e1ef884e50d4e2a9ac519eb568569d140f82b19cc5deaa142e', $plan->executionIdentity());

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
        $preflightNodes = array_column($plan->phaseSteps('preflight'), 'node_id');
        $this->assertCount(39, $preflightNodes);
        $this->assertSame($constraintNodes, array_slice($preflightNodes, 29, 10));
        $this->assertSame([], $plan->phaseSteps('write_fence'));

        $beforeSchema = $this->schemaBoundarySignature();
        $beforeRows = $this->targetRowCounts();

        $exit = Artisan::call('migration:controlled', [
            '--plan' => $plan->identity(),
            '--execution' => $plan->executionIdentity(),
            '--phase' => 'preflight',
            '--force' => true,
        ]);
        $this->assertSame(0, $exit, Artisan::output());

        $expectedApplied = array_column($plan->phaseSteps('preflight'), 'migration_name');
        $applied = DB::table('migrations')
            ->whereIn('migration', $expectedApplied)
            ->pluck('migration')
            ->all();
        sort($expectedApplied);
        sort($applied);
        $this->assertSame($expectedApplied, array_values($applied));

        $this->assertSame($beforeSchema, $this->schemaBoundarySignature());
        $this->assertSame($beforeRows, $this->targetRowCounts());
        $this->assertSame([], $plan->phaseSteps('write_fence'));
    }

    /**
     * @return array<string, int>
     */
    private function targetRowCounts(): array
    {
        $tables = [
            'organization_memberships',
            'staff_profiles',
            'students',
            'training_sessions',
            'calendar_events',
            'calendar_resource_claims',
            'pkk_operations',
            'student_payments',
            'license_assignments',
            'internal_exam_attempts',
            'orders',
            'payments',
            'outbox_messages',
            'notifications',
        ];

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @return array{indexes: list<string>, constraints: list<string>, triggers: list<string>}
     */
    private function schemaBoundarySignature(): array
    {
        $indexes = DB::table('pg_indexes')
            ->whereRaw('schemaname = current_schema()')
            ->orderBy('tablename')
            ->orderBy('indexname')
            ->get(['tablename', 'indexname', 'indexdef'])
            ->map(static fn ($row): string => implode('|', [(string) $row->tablename, (string) $row->indexname, (string) $row->indexdef]))
            ->all();

        $constraints = DB::table('pg_constraint as con')
            ->join('pg_class as cls', 'cls.oid', '=', 'con.conrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 'cls.relnamespace')
            ->whereRaw('ns.nspname = current_schema()')
            ->orderBy('cls.relname')
            ->orderBy('con.conname')
            ->get(['cls.relname as table_name', 'con.conname', 'con.contype'])
            ->map(static fn ($row): string => implode('|', [(string) $row->table_name, (string) $row->conname, (string) $row->contype]))
            ->all();

        $triggers = DB::table('pg_trigger as trg')
            ->join('pg_class as cls', 'cls.oid', '=', 'trg.tgrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 'cls.relnamespace')
            ->whereRaw('ns.nspname = current_schema()')
            ->where('trg.tgisinternal', false)
            ->orderBy('cls.relname')
            ->orderBy('trg.tgname')
            ->get(['cls.relname as table_name', 'trg.tgname'])
            ->map(static fn ($row): string => implode('|', [(string) $row->table_name, (string) $row->tgname]))
            ->all();

        return [
            'indexes' => array_values($indexes),
            'constraints' => array_values($constraints),
            'triggers' => array_values($triggers),
        ];
    }
}
