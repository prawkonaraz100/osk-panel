<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-LICENSES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-CK-LICENSES write-fence requires PostgreSQL.');
        }

        $this->addUniqueConstraint('license_inventory_entries', 'license_inventory_entry_candidate_key_org_id', ['organization_id', 'id']);
        $this->addUniqueConstraint('license_assignments', 'license_assignment_candidate_key_org_id', ['organization_id', 'id']);
    }

    /**
     * @param list<string> $columns
     */
    private function addUniqueConstraint(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
            throw new LogicException("MIG-CK-LICENSES write-fence prerequisite is missing for {$name}.");
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
            throw new LogicException("MIG-CK-LICENSES write-fence postcondition failed for {$name}.");
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
