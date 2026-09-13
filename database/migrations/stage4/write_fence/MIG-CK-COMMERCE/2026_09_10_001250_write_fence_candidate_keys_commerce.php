<?php

use App\Support\Migrations\CandidateKeyWriteFence;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-COMMERCE');

        CandidateKeyWriteFence::install('MIG-CK-COMMERCE', [
            [
                'name' => 'order_candidate_key_org_id',
                'table' => 'orders',
                'columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'order_item_candidate_key_org_id',
                'table' => 'order_items',
                'columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'order_item_candidate_key_org_id_product_kind',
                'table' => 'order_items',
                'columns' => ['organization_id', 'id', 'product_kind'],
            ],
            [
                'name' => 'order_item_candidate_key_org_id_license_product',
                'table' => 'order_items',
                'columns' => ['organization_id', 'id', 'license_product_id'],
            ],
            [
                'name' => 'order_item_candidate_key_org_id_catalog_item',
                'table' => 'order_items',
                'columns' => ['organization_id', 'id', 'commerce_catalog_item_id'],
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
