<?php

namespace Tests\Feature;

use App\Support\Migrations\MigrationPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4CommerceLineagePrerequisiteRecoveryTest extends TestCase
{
    public function test_order_items_contains_exact_license_product_lineage_column_before_write_fence(): void
    {
        FoundationSchema::ensureMigrated();

        $this->assertTrue(Schema::hasTable('order_items'));
        $this->assertTrue(
            Schema::hasColumn('order_items', 'license_product_id'),
            'DB-COM-004 exact purchase lineage requires order_items.license_product_id before MIG-CK-COMMERCE write-fence.',
        );

        $column = DB::selectOne(
            "SELECT is_nullable, data_type
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = 'order_items'
               AND column_name = 'license_product_id'",
        );

        $this->assertNotNull($column);
        $this->assertSame('YES', $column->is_nullable);
        $this->assertSame('uuid', $column->data_type);

        $candidateConstraints = DB::select(
            "SELECT con.conname
             FROM pg_constraint con
             JOIN pg_class cls ON cls.oid = con.conrelid
             JOIN pg_namespace ns ON ns.oid = cls.relnamespace
             WHERE ns.nspname = current_schema()
               AND con.contype = 'u'
               AND con.conname IN (
                   'order_candidate_key_org_id',
                   'order_item_candidate_key_org_id',
                   'order_item_candidate_key_org_id_product_kind',
                   'order_item_candidate_key_org_id_license_product',
                   'order_item_candidate_key_org_id_catalog_item'
               )",
        );

        $this->assertSame(
            [],
            $candidateConstraints,
            'Commerce lineage prerequisite must not prematurely materialize MIG-CK-COMMERCE write-fence.',
        );

        $plan = app(MigrationPlan::class);
        $plan->validate();
        $this->assertSame(170, $plan->nodeCount());
        $this->assertSame(157, $plan->implementedNodeCount());
        $this->assertSame(157, $plan->implementedStepCount());
        $this->assertSame('7cdea7410b15a1e1ef884e50d4e2a9ac519eb568569d140f82b19cc5deaa142e', $plan->executionIdentity());
        $this->assertSame([], $plan->phaseSteps('write_fence'));
    }
}
