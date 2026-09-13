<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\ForeignKeyPreflight;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-FK-COMMERCE');

        ForeignKeyPreflight::assertRelations('MIG-FK-COMMERCE', [
            [
                'name' => 'order_item_order',
                'source_table' => 'order_items',
                'source_columns' => ['organization_id', 'order_id'],
                'target_table' => 'orders',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'order_item_catalog_item',
                'source_table' => 'order_items',
                'source_columns' => ['commerce_catalog_item_id'],
                'target_table' => 'commerce_catalog_items',
                'target_columns' => ['id'],
            ],
            [
                'name' => 'payment_order',
                'source_table' => 'payments',
                'source_columns' => ['organization_id', 'order_id'],
                'target_table' => 'orders',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'payment_event_exact_payment',
                'source_table' => 'payment_events',
                'source_columns' => ['organization_id', 'payment_id', 'provider', 'provider_payment_id'],
                'target_table' => 'payments',
                'target_columns' => ['organization_id', 'id', 'provider', 'provider_payment_id'],
            ],
            [
                'name' => 'settlement_order',
                'source_table' => 'order_payment_settlements',
                'source_columns' => ['organization_id', 'order_id'],
                'target_table' => 'orders',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'settlement_exact_payment_order',
                'source_table' => 'order_payment_settlements',
                'source_columns' => ['organization_id', 'payment_id', 'order_id'],
                'target_table' => 'payments',
                'target_columns' => ['organization_id', 'id', 'order_id'],
            ],
            [
                'name' => 'settlement_source_event',
                'source_table' => 'order_payment_settlements',
                'source_columns' => ['organization_id', 'source_payment_event_id', 'payment_id'],
                'target_table' => 'payment_events',
                'target_columns' => ['organization_id', 'id', 'payment_id'],
            ],
            [
                'name' => 'fulfillment_order',
                'source_table' => 'order_fulfillments',
                'source_columns' => ['organization_id', 'order_id'],
                'target_table' => 'orders',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'fulfillment_settlement',
                'source_table' => 'order_fulfillments',
                'source_columns' => ['organization_id', 'order_id', 'settlement_payment_id'],
                'target_table' => 'order_payment_settlements',
                'target_columns' => ['organization_id', 'order_id', 'payment_id'],
            ],
            [
                'name' => 'service_entitlement_source_item',
                'source_table' => 'service_entitlements',
                'source_columns' => ['organization_id', 'source_order_item_id'],
                'target_table' => 'order_items',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'service_activation_entitlement',
                'source_table' => 'service_activations',
                'source_columns' => ['organization_id', 'service_entitlement_id'],
                'target_table' => 'service_entitlements',
                'target_columns' => ['organization_id', 'id'],
            ]
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
