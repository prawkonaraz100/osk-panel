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

        DB::statement(<<<'SQL'
UPDATE order_items oi
SET license_product_id = ci.license_product_id
FROM commerce_catalog_items ci
WHERE ci.id = oi.commerce_catalog_item_id
  AND oi.product_kind = 'license'
  AND ci.product_kind = 'license'
  AND ci.license_product_id IS NOT NULL
  AND oi.license_product_id IS NULL
SQL);

        $remainingConflicts = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS conflict_count
FROM order_items oi
LEFT JOIN commerce_catalog_items ci ON ci.id = oi.commerce_catalog_item_id
WHERE (oi.product_kind = 'license'
       AND (
            ci.id IS NULL
            OR ci.product_kind <> 'license'
            OR ci.license_product_id IS NULL
            OR oi.license_product_id IS DISTINCT FROM ci.license_product_id
       ))
   OR (oi.product_kind <> 'license' AND oi.license_product_id IS NOT NULL)
SQL);

        if ((int) ($remainingConflicts->conflict_count ?? 0) !== 0) {
            throw new LogicException('MIG-CK-COMMERCE write fence could not materialize exact OrderItem license-product snapshots.');
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
