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
        $this->assertSame(170, $plan->implementedNodeCount());
        $this->assertSame(223, $plan->implementedStepCount());
        $this->assertSame('2b8d8001da433ec87402155ef9c3e0149d36018eea9b5a03a63b7a97a54c0f76', $plan->executionIdentity());
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
        ], array_slice(array_column($plan->phaseSteps('write_fence'), 'node_id'), 0, 39));
    }
}
