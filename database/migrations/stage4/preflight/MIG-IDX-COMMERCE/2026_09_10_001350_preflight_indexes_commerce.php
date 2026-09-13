<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-IDX-COMMERCE');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-IDX-COMMERCE preflight requires PostgreSQL.');
        }

        $this->assertNoDuplicate('orders', 'commerce_order_sequence_unique_per_tenant', ['organization_id', 'order_sequence']);
        $this->assertNoDuplicateIfColumnsPresent('license_inventory_entries', 'license_inventory_source_order_item_ordinal_unique', ['organization_id', 'source_order_item_id', 'source_order_item_grant_ordinal'], 'source_order_item_id IS NOT NULL');
        $this->assertNoDuplicateIfColumnsPresent('internal_exam_inventory_entries', 'internal_exam_inventory_source_order_item_ordinal_unique', ['organization_id', 'source_order_item_id', 'source_order_item_grant_ordinal'], "source_type = 'paid' AND source_order_item_id IS NOT NULL");
        $this->assertNoDuplicate('payment_events', 'payment_provider_event_unique', ['provider', 'provider_event_id']);
        $this->assertNoDuplicate('order_payment_settlements', 'order_payment_settlement_unique_per_order', ['organization_id', 'order_id']);
        $this->assertNoDuplicate('order_fulfillments', 'order_fulfillment_unique_per_order', ['organization_id', 'order_id']);
        $this->assertNoDuplicate('service_entitlements', 'service_entitlement_purchase_ordinal_unique', ['organization_id', 'source_order_item_id', 'source_order_item_grant_ordinal'], 'source_order_item_id IS NOT NULL');
        $this->assertNoDuplicate('service_activations', 'service_activation_unique_per_entitlement', ['organization_id', 'service_entitlement_id']);
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertNoDuplicate(string $table, string $name, array $columns, ?string $where = null): void
    {
        if (! Schema::hasTable($table)) {
            throw new LogicException("MIG-IDX-COMMERCE preflight missing table {$table}.");
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new LogicException("MIG-IDX-COMMERCE preflight missing {$table}.{$column} for {$name}.");
            }
        }

        $grammar = DB::connection()->getQueryGrammar();
        $wrappedColumns = array_map(
            static fn (string $column): string => $grammar->wrap($column),
            $columns,
        );
        $group = implode(', ', $wrappedColumns);
        $wrappedTable = $grammar->wrapTable($table);
        $predicate = $where ?? 'TRUE';

        $duplicate = DB::selectOne(
            "SELECT 1 AS duplicate_found FROM {$wrappedTable} WHERE {$predicate} GROUP BY {$group} HAVING COUNT(*) > 1 LIMIT 1",
        );
        if ($duplicate !== null) {
            throw new LogicException("MIG-IDX-COMMERCE preflight found duplicate rows for {$name}; reviewed remediation is required before write-fence.");
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertNoDuplicateIfColumnsPresent(string $table, string $name, array $columns, string $where): void
    {
        if (! Schema::hasTable($table)) {
            throw new LogicException("MIG-IDX-COMMERCE preflight missing table {$table}.");
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        $this->assertNoDuplicate($table, $name, $columns, $where);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
