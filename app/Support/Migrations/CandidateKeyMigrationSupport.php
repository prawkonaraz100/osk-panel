<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final class CandidateKeyMigrationSupport
{
    /**
     * @param  list<array{name:string,table:string,columns:list<string>,required_not_null?:list<string>}>  $definitions
     */
    public static function preflight(string $nodeId, array $definitions): void
    {
        self::assertPostgres($nodeId);

        foreach ($definitions as $definition) {
            $table = $definition['table'];
            $columns = $definition['columns'];

            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                throw new LogicException("{$nodeId} preflight missing candidate-key target {$table}(".implode(',', $columns).').');
            }

            foreach ($definition['required_not_null'] ?? [] as $column) {
                $nulls = DB::table($table)->whereNull($column)->count();
                if ($nulls !== 0) {
                    throw new LogicException("{$nodeId} preflight found {$nulls} NULL rows in required key {$table}.{$column}.");
                }
            }

            $quoted = array_map([self::class, 'quoteIdentifier'], $columns);
            $columnList = implode(', ', $quoted);
            $duplicates = DB::selectOne(
                'SELECT COUNT(*) AS conflict_groups FROM ('
                ."SELECT {$columnList} FROM ".self::quoteIdentifier($table)
                ." GROUP BY {$columnList} HAVING COUNT(*) > 1"
                .') conflicts',
            );
            if ((int) ($duplicates->conflict_groups ?? 0) !== 0) {
                throw new LogicException("{$nodeId} preflight found duplicate candidate-key rows for {$definition['name']}.");
            }

            self::assertExistingConstraintCompatible($definition);
        }
    }

    /**
     * @param  list<array{name:string,table:string,columns:list<string>,required_not_null?:list<string>}>  $definitions
     */
    public static function install(string $nodeId, array $definitions): void
    {
        self::assertPostgres($nodeId);
        self::preflight($nodeId, $definitions);

        foreach ($definitions as $definition) {
            if (self::constraintColumns($definition['table'], $definition['name']) !== null) {
                continue;
            }

            $columns = implode(', ', array_map([self::class, 'quoteIdentifier'], $definition['columns']));
            DB::statement(
                'ALTER TABLE '.self::quoteIdentifier($definition['table'])
                .' ADD CONSTRAINT '.self::quoteIdentifier($definition['name'])
                ." UNIQUE ({$columns})",
            );

            self::assertExistingConstraintCompatible($definition);
        }
    }

    /**
     * @param  array{name:string,table:string,columns:list<string>}  $definition
     */
    private static function assertExistingConstraintCompatible(array $definition): void
    {
        $actual = self::constraintColumns($definition['table'], $definition['name']);
        if ($actual !== null && $actual !== $definition['columns']) {
            throw new LogicException(
                "Candidate-key constraint {$definition['name']} exists with unexpected columns: ".implode(',', $actual),
            );
        }
    }

    /** @return list<string>|null */
    private static function constraintColumns(string $table, string $constraint): ?array
    {
        $rows = DB::select(
            <<<'SQL'
SELECT att.attname AS column_name
FROM pg_constraint con
JOIN pg_class cls ON cls.oid = con.conrelid
JOIN pg_namespace ns ON ns.oid = cls.relnamespace
JOIN unnest(con.conkey) WITH ORDINALITY AS key(attnum, ordinality) ON true
JOIN pg_attribute att ON att.attrelid = cls.oid AND att.attnum = key.attnum
WHERE ns.nspname = current_schema()
  AND cls.relname = ?
  AND con.conname = ?
  AND con.contype = 'u'
ORDER BY key.ordinality
SQL,
            [$table, $constraint],
        );

        if ($rows === []) {
            $exists = DB::selectOne(
                <<<'SQL'
SELECT con.contype
FROM pg_constraint con
JOIN pg_class cls ON cls.oid = con.conrelid
JOIN pg_namespace ns ON ns.oid = cls.relnamespace
WHERE ns.nspname = current_schema()
  AND cls.relname = ?
  AND con.conname = ?
SQL,
                [$table, $constraint],
            );
            if ($exists !== null) {
                throw new LogicException("Constraint {$constraint} exists but is not a UNIQUE candidate key.");
            }

            return null;
        }

        return array_values(array_map(
            static fn (object $row): string => (string) $row->column_name,
            $rows,
        ));
    }

    private static function assertPostgres(string $nodeId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException("{$nodeId} requires PostgreSQL.");
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $identifier) !== 1) {
            throw new LogicException("Unsafe SQL identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
}
