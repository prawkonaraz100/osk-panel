<?php

namespace Tests\Feature;

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
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
        $this->assertSame(175, $plan->implementedStepCount());
        $this->assertSame('411d0c2c1493fb2ea052e89b5c919f2b7e3ae0f746301107b0a7c08610486f71', $plan->executionIdentity());

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
        $this->assertSame([
            'MIG-CK-IDENTITY',
            'MIG-CK-ASSETS_RESOURCES',
            'MIG-CK-TRAINING',
            'MIG-CK-FINANCE',
            'MIG-CK-LICENSES',
            'MIG-CK-EXAMS',
            'MIG-CK-COMMERCE',
            'MIG-CK-EVENTS',
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
        ], array_column($plan->phaseSteps('write_fence'), 'node_id'));

        $this->assertFalse(Schema::hasColumn('student_payments', 'idempotency_key'));
        $this->assertTrue(Schema::hasColumn('internal_exam_inventory_entries', 'source_order_item_grant_ordinal'));

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
        $this->assertTrue(Schema::hasColumn('internal_exam_inventory_entries', 'source_order_item_grant_ordinal'));
        $this->assertSame([
            'MIG-CK-IDENTITY',
            'MIG-CK-ASSETS_RESOURCES',
            'MIG-CK-TRAINING',
            'MIG-CK-FINANCE',
            'MIG-CK-LICENSES',
            'MIG-CK-EXAMS',
            'MIG-CK-COMMERCE',
            'MIG-CK-EVENTS',
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
        ], array_column($plan->phaseSteps('write_fence'), 'node_id'));
    }

    public function test_commerce_index_preflight_fails_closed_without_internal_exam_purchase_ordinal(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        DB::beginTransaction();

        try {
            DB::statement('ALTER TABLE internal_exam_inventory_entries DROP COLUMN source_order_item_grant_ordinal');
            $this->assertFalse(Schema::hasColumn('internal_exam_inventory_entries', 'source_order_item_grant_ordinal'));

            ControlledMigrationContext::enter('preflight', 'MIG-IDX-COMMERCE', $plan->executionIdentity());

            try {
                /** @var Migration $migration */
                $migration = require base_path('database/migrations/stage4/preflight/MIG-IDX-COMMERCE/2026_09_10_001350_preflight_indexes_commerce.php');

                try {
                    $migration->up();
                    $this->fail('MIG-IDX-COMMERCE must fail closed when internal exam purchase ordinal is absent.');
                } catch (LogicException $exception) {
                    $this->assertStringContainsString(
                        'missing internal_exam_inventory_entries.source_order_item_grant_ordinal',
                        $exception->getMessage(),
                    );
                }
            } finally {
                ControlledMigrationContext::leave();
            }
        } finally {
            DB::rollBack();
        }
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
