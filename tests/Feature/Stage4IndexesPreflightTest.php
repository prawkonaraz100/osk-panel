<?php

namespace Tests\Feature;

use App\Support\Migrations\MigrationPlan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4IndexesPreflightTest extends TestCase
{
    public function test_ten_index_nodes_execute_as_read_only_preflights_without_write_fence(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        $this->assertSame(170, $plan->nodeCount());
        $this->assertSame(157, $plan->implementedNodeCount());
        $this->assertSame(157, $plan->implementedStepCount());
        $this->assertSame('b1510cc6adc4a551a72f6ca338fb24db5d642c7a96724f4cb196e780e82f0ecd', $plan->executionIdentity());

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
        $preflightNodes = array_column($plan->phaseSteps('preflight'), 'node_id');
        $this->assertSame($indexNodes, array_slice($preflightNodes, 8, count($indexNodes)));
        $this->assertCount(39, $preflightNodes);
        $this->assertSame([], $plan->phaseSteps('write_fence'));

        $this->assertFalse(Schema::hasColumn('student_payments', 'idempotency_key'));
        $this->assertFalse(Schema::hasColumn('internal_exam_inventory_entries', 'source_order_item_grant_ordinal'));

        $before = $this->schemaBoundarySignature();

        $exit = Artisan::call('migration:controlled', [
            '--plan' => $plan->identity(),
            '--execution' => $plan->executionIdentity(),
            '--phase' => 'preflight',
            '--force' => true,
        ]);
        $this->assertSame(0, $exit, Artisan::output());

        $applied = DB::table('migrations')
            ->whereIn('migration', array_column($plan->phaseSteps('preflight'), 'migration_name'))
            ->pluck('migration')
            ->all();
        sort($applied);

        $expectedApplied = array_column($plan->phaseSteps('preflight'), 'migration_name');
        sort($expectedApplied);
        $this->assertSame($expectedApplied, array_values($applied));

        $this->assertSame($before, $this->schemaBoundarySignature());
        $this->assertFalse(Schema::hasColumn('student_payments', 'idempotency_key'));
        $this->assertFalse(Schema::hasColumn('internal_exam_inventory_entries', 'source_order_item_grant_ordinal'));
        $this->assertSame([], $plan->phaseSteps('write_fence'));
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

        return ['indexes' => array_values($indexes), 'constraints' => array_values($constraints), 'triggers' => array_values($triggers)];
    }
}
