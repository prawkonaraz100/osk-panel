<?php

use App\Support\Migrations\ConstraintWriteFence;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CON-COMMERCE');

        ConstraintWriteFence::install('MIG-CON-COMMERCE', [
            [
                'name' => 'order_numeric_and_time_bounds',
                'table' => 'orders',
                'columns' => ['order_sequence', 'ordered_at', 'booked_at', 'zero_total_settled_at', 'total_amount_minor'],
                'predicate' => 'src.order_sequence >= 1 AND src.total_amount_minor >= 0 AND (src.booked_at IS NULL OR src.booked_at >= src.ordered_at) AND (src.zero_total_settled_at IS NULL OR (src.total_amount_minor = 0 AND src.zero_total_settled_at >= src.ordered_at))',
            ],
            [
                'name' => 'order_item_numeric_bounds',
                'table' => 'order_items',
                'columns' => ['quantity', 'list_unit_amount_minor', 'unit_amount_minor', 'unit_discount_amount_minor', 'vat_rate_basis_points', 'total_amount_minor'],
                'predicate' => 'src.quantity > 0 AND src.list_unit_amount_minor >= 0 AND src.unit_amount_minor >= 0 AND src.unit_discount_amount_minor >= 0 AND src.unit_discount_amount_minor <= src.list_unit_amount_minor AND src.vat_rate_basis_points BETWEEN 0 AND 10000 AND src.total_amount_minor >= 0',
            ],
            [
                'name' => 'payment_closed_state',
                'table' => 'payments',
                'columns' => ['status', 'amount_minor', 'confirmed_at', 'failed_at'],
                'predicate' => 'src.amount_minor >= 0 AND ((src.status = \'pending\' AND src.confirmed_at IS NULL AND src.failed_at IS NULL) OR (src.status = \'confirmed\' AND src.confirmed_at IS NOT NULL AND src.failed_at IS NULL) OR (src.status = \'failed\' AND src.confirmed_at IS NULL AND src.failed_at IS NOT NULL))',
            ],
            [
                'name' => 'fulfillment_closed_state',
                'table' => 'order_fulfillments',
                'columns' => ['state', 'fulfilled_at', 'requires_reconciliation_at'],
                'predicate' => '(src.state = \'pending\' AND src.fulfilled_at IS NULL AND src.requires_reconciliation_at IS NULL) OR (src.state = \'fulfilled\' AND src.fulfilled_at IS NOT NULL AND src.requires_reconciliation_at IS NULL) OR (src.state = \'requires_reconciliation\' AND src.fulfilled_at IS NULL AND src.requires_reconciliation_at IS NOT NULL)',
            ],
            [
                'name' => 'service_entitlement_source_xor',
                'table' => 'service_entitlements',
                'columns' => ['source_order_item_id', 'source_order_item_grant_ordinal', 'source_grant_reference', 'activation_mode'],
                'predicate' => 'src.activation_mode IN (\'explicit\',\'immediate\') AND ((src.source_order_item_id IS NOT NULL AND src.source_order_item_grant_ordinal IS NOT NULL AND src.source_order_item_grant_ordinal >= 1 AND src.source_grant_reference IS NULL) OR (src.source_order_item_id IS NULL AND src.source_order_item_grant_ordinal IS NULL AND NULLIF(BTRIM(src.source_grant_reference),\'\') IS NOT NULL))',
            ],
            [
                'name' => 'service_activation_period',
                'table' => 'service_activations',
                'columns' => ['effective_from', 'effective_to'],
                'predicate' => 'src.effective_to IS NULL OR src.effective_to > src.effective_from',
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
