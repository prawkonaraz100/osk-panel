<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-CK-COMMERCE');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-CK-COMMERCE preflight requires PostgreSQL.');
        }

        $this->assertCandidateKey('orders', 'order_candidate_key_org_id', ['organization_id', 'id'], false);
        $this->assertCandidateKey('order_items', 'order_item_candidate_key_org_id', ['organization_id', 'id'], false);
        $this->assertCandidateKey('order_items', 'order_item_candidate_key_org_id_product_kind', ['organization_id', 'id', 'product_kind'], false);
        $this->assertCandidateKey('order_items', 'order_item_candidate_key_org_id_license_product', ['organization_id', 'id', 'license_product_id'], false);
        $this->assertCandidateKey('order_items', 'order_item_candidate_key_org_id_catalog_item', ['organization_id', 'id', 'commerce_catalog_item_id'], false);
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertCandidateKey(string $table, string $name, array $columns, bool $allowKnownMissingLicenseProductColumn = false): void
    {
        if (! Schema::hasTable($table)) {
            throw new LogicException("MIG-CK-COMMERCE preflight missing table {$table}.");
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                if ($allowKnownMissingLicenseProductColumn && $table === 'order_items' && $column === 'license_product_id') {
                    return;
                }

                throw new LogicException("MIG-CK-COMMERCE preflight missing {$table}.{$column} for {$name}.");
            }
        }

        $grammar = DB::connection()->getQueryGrammar();
        $wrappedColumns = array_map(
            static fn (string $column): string => $grammar->wrap($column),
            $columns,
        );
        $group = implode(', ', $wrappedColumns);
        $nonnullPredicate = implode(' AND ', array_map(
            static fn (string $column): string => "{$column} IS NOT NULL",
            $wrappedColumns,
        ));
        $wrappedTable = $grammar->wrapTable($table);

        $duplicate = DB::selectOne(
            "SELECT 1 AS duplicate_found FROM {$wrappedTable} WHERE {$nonnullPredicate} GROUP BY {$group} HAVING COUNT(*) > 1 LIMIT 1",
        );
        if ($duplicate !== null) {
            throw new LogicException("MIG-CK-COMMERCE preflight found duplicate rows for {$name}; reviewed remediation is required before write-fence.");
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
