<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-COMMERCE');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-CK-COMMERCE write-fence requires PostgreSQL.');
        }

        if (! Schema::hasColumn('order_items', 'license_product_id')) {
            DB::statement('ALTER TABLE order_items ADD COLUMN license_product_id uuid NULL');
        }

        $this->addUniqueConstraint('orders', 'order_candidate_key_org_id', ['organization_id', 'id']);
        $this->addUniqueConstraint('order_items', 'order_item_candidate_key_org_id', ['organization_id', 'id']);
        $this->addUniqueConstraint('order_items', 'order_item_candidate_key_org_id_product_kind', ['organization_id', 'id', 'product_kind']);
        $this->addUniqueConstraint('order_items', 'order_item_candidate_key_org_id_license_product', ['organization_id', 'id', 'license_product_id']);
        $this->addUniqueConstraint('order_items', 'order_item_candidate_key_org_id_catalog_item', ['organization_id', 'id', 'commerce_catalog_item_id']);
    }

    /**
     * @param list<string> $columns
     */
    private function addUniqueConstraint(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
            throw new LogicException("MIG-CK-COMMERCE write-fence prerequisite is missing for {$name}.");
        }

        $existing = DB::selectOne(
            <<<'SQL'
SELECT 1 AS present
FROM pg_constraint con
JOIN pg_class cls ON cls.oid = con.conrelid
JOIN pg_namespace ns ON ns.oid = cls.relnamespace
WHERE ns.nspname = current_schema()
  AND cls.relname = ?
  AND con.conname = ?
  AND con.contype = 'u'
LIMIT 1
SQL,
            [$table, $name],
        );
        if ($existing !== null) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedColumns = implode(', ', array_map(
            static fn (string $column): string => $grammar->wrap($column),
            $columns,
        ));
        $wrappedName = $grammar->wrap($name);

        DB::statement("ALTER TABLE {$wrappedTable} ADD CONSTRAINT {$wrappedName} UNIQUE ({$wrappedColumns})");

        $postcondition = DB::selectOne(
            <<<'SQL'
SELECT 1 AS present
FROM pg_constraint con
JOIN pg_class cls ON cls.oid = con.conrelid
JOIN pg_namespace ns ON ns.oid = cls.relnamespace
WHERE ns.nspname = current_schema()
  AND cls.relname = ?
  AND con.conname = ?
  AND con.contype = 'u'
LIMIT 1
SQL,
            [$table, $name],
        );
        if ($postcondition === null) {
            throw new LogicException("MIG-CK-COMMERCE write-fence postcondition failed for {$name}.");
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
