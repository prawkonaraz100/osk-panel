<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\ForeignKeyWriteFence;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-FK-PURCHASE_DOWNSTREAM');

        ForeignKeyWriteFence::install('MIG-FK-PURCHASE_DOWNSTREAM', [
            [
                'name' => 'license_inventory_purchase_source',
                'source_table' => 'license_inventory_entries',
                'source_columns' => ['organization_id', 'source_order_item_id'],
                'target_table' => 'order_items',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'exam_inventory_purchase_source',
                'source_table' => 'internal_exam_inventory_entries',
                'source_columns' => ['organization_id', 'source_order_item_id'],
                'target_table' => 'order_items',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'service_entitlement_purchase_source',
                'source_table' => 'service_entitlements',
                'source_columns' => ['organization_id', 'source_order_item_id'],
                'target_table' => 'order_items',
                'target_columns' => ['organization_id', 'id'],
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
