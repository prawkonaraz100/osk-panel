<?php

use App\Support\Migrations\CandidateKeyMigrationSupport;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-COMMERCE');

        if (! Schema::hasColumn('order_items', 'license_product_id')) {
            DB::statement('ALTER TABLE order_items ADD COLUMN license_product_id uuid NULL');
        }

        CandidateKeyMigrationSupport::install('MIG-CK-COMMERCE', [
            [
                'name' => 'ck_orders_org_id',
                'table' => 'orders',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['organization_id', 'id'],
            ],
            [
                'name' => 'ck_order_items_org_id',
                'table' => 'order_items',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['organization_id', 'id'],
            ],
            [
                'name' => 'ck_order_items_org_id_kind',
                'table' => 'order_items',
                'columns' => ['organization_id', 'id', 'product_kind'],
                'required_not_null' => ['organization_id', 'id', 'product_kind'],
            ],
            [
                'name' => 'ck_order_items_org_id_license_product',
                'table' => 'order_items',
                'columns' => ['organization_id', 'id', 'license_product_id'],
                'required_not_null' => ['organization_id', 'id'],
            ],
            [
                'name' => 'ck_order_items_org_id_catalog_item',
                'table' => 'order_items',
                'columns' => ['organization_id', 'id', 'commerce_catalog_item_id'],
                'required_not_null' => ['organization_id', 'id', 'commerce_catalog_item_id'],
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for Stage-4 candidate-key write fences.');
    }
};
