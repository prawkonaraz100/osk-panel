<?php

namespace Tests\Feature;

use App\Support\Migrations\MigrationPlan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4ForeignKeysPreflightTest extends TestCase
{
    public function test_eleven_foreign_key_nodes_execute_as_read_only_preflights_without_write_fence(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        $this->assertSame(170, $plan->nodeCount());
        $this->assertSame(157, $plan->implementedNodeCount());
        $this->assertSame(157, $plan->implementedStepCount());
        $this->assertSame('d2da7eb3ccb082ca6b0106fbe4b1e543815c9236cc1a8bd62cf470c93459b660', $plan->executionIdentity());

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
        $preflightNodes = array_column($plan->phaseSteps('preflight'), 'node_id');
        $this->assertSame($foreignKeyNodes, array_slice($preflightNodes, 18, count($foreignKeyNodes)));
        $this->assertCount(39, $preflightNodes);
        $this->assertSame([], $plan->phaseSteps('write_fence'));

        $before = $this->schemaBoundarySignature();

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

        $this->assertSame($before, $this->schemaBoundarySignature());
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

        return [
            'indexes' => array_values($indexes),
            'constraints' => array_values($constraints),
            'triggers' => array_values($triggers),
        ];
    }
}
