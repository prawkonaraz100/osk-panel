<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\IndexWriteFence;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-IDX-COMMERCE');

        IndexWriteFence::installUniqueIndexes('MIG-IDX-COMMERCE', [
            [
                'name' => 'commerce_order_sequence_unique_per_tenant',
                'table' => 'orders',
                'columns' => ['organization_id', 'order_sequence'],
                'predicate' => null,
            ],
            [
                'name' => 'license_inventory_source_order_item_ordinal_unique',
                'table' => 'license_inventory_entries',
                'columns' => ['organization_id', 'source_order_item_id', 'source_order_item_grant_ordinal'],
                'predicate' => 'source_order_item_id IS NOT NULL',
            ],
            [
                'name' => 'internal_exam_inventory_source_order_item_ordinal_unique',
                'table' => 'internal_exam_inventory_entries',
                'columns' => ['organization_id', 'source_order_item_id', 'source_order_item_grant_ordinal'],
                'predicate' => 'source_type = \'paid\' AND source_order_item_id IS NOT NULL',
            ],
            [
                'name' => 'payment_provider_event_unique',
                'table' => 'payment_events',
                'columns' => ['provider', 'provider_event_id'],
                'predicate' => null,
            ],
            [
                'name' => 'order_payment_settlement_unique_per_order',
                'table' => 'order_payment_settlements',
                'columns' => ['organization_id', 'order_id'],
                'predicate' => null,
            ],
            [
                'name' => 'order_fulfillment_unique_per_order',
                'table' => 'order_fulfillments',
                'columns' => ['organization_id', 'order_id'],
                'predicate' => null,
            ],
            [
                'name' => 'service_entitlement_purchase_ordinal_unique',
                'table' => 'service_entitlements',
                'columns' => ['organization_id', 'source_order_item_id', 'source_order_item_grant_ordinal'],
                'predicate' => 'source_order_item_id IS NOT NULL',
            ],
            [
                'name' => 'service_activation_unique_per_entitlement',
                'table' => 'service_activations',
                'columns' => ['organization_id', 'service_entitlement_id'],
                'predicate' => null,
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
