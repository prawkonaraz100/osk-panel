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
        ControlledMigrationContext::assertActive('preflight', 'MIG-CK-COMMERCE');

        CandidateKeyMigrationSupport::preflight('MIG-CK-COMMERCE', [
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
                'name' => 'ck_order_items_org_id_catalog_item',
                'table' => 'order_items',
                'columns' => ['organization_id', 'id', 'commerce_catalog_item_id'],
                'required_not_null' => ['organization_id', 'id', 'commerce_catalog_item_id'],
            ],
        ]);

        if (! Schema::hasColumns('commerce_catalog_items', ['id', 'product_kind', 'license_product_id'])) {
            throw new LogicException('MIG-CK-COMMERCE requires exact commerce catalog product identity columns.');
        }

        $conflicts = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS conflict_count
FROM order_items oi
LEFT JOIN commerce_catalog_items ci ON ci.id = oi.commerce_catalog_item_id
WHERE ci.id IS NULL
   OR ci.product_kind <> oi.product_kind
   OR (oi.product_kind = 'license' AND ci.license_product_id IS NULL)
   OR (oi.product_kind <> 'license' AND ci.license_product_id IS NOT NULL)
SQL);

        if ((int) ($conflicts->conflict_count ?? 0) !== 0) {
            throw new LogicException('MIG-CK-COMMERCE preflight found OrderItem rows without exact catalog/product-kind/license-product evidence.');
        }

        if (Schema::hasColumn('order_items', 'license_product_id')) {
            CandidateKeyMigrationSupport::preflight('MIG-CK-COMMERCE', [[
                'name' => 'ck_order_items_org_id_license_product',
                'table' => 'order_items',
                'columns' => ['organization_id', 'id', 'license_product_id'],
                'required_not_null' => ['organization_id', 'id'],
            ]]);
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for Stage-4 candidate-key preflight.');
    }
};
