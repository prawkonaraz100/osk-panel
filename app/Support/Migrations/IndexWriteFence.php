<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final class IndexWriteFence
{
    /**
     * @param  list<array{name: string, table: string, columns: list<string>, predicate: string|null}>  $indexes
     */
    public static function installUniqueIndexes(string $nodeId, array $indexes): void
    {
        self::assertPostgres($nodeId);

        foreach ($indexes as $index) {
            self::installUniqueIndex($nodeId, $index);
        }
    }

    /**
     * @param  list<array{name: string, table: string, columns: list<string>, predicate: string}>  $constraints
     */
    public static function installExclusionConstraints(string $nodeId, array $constraints): void
    {
        self::assertPostgres($nodeId);

        foreach ($constraints as $constraint) {
            self::installExclusionConstraint($nodeId, $constraint);
        }
    }

    /**
     * @param  array{name: string, table: string, columns: list<string>, predicate: string|null}  $index
     */
    private static function installUniqueIndex(string $nodeId, array $index): void
    {
        self::assertTableColumns($nodeId, $index['table'], $index['name'], $index['columns']);

        $existing = self::indexMetadata($index['name']);
        if ($existing !== null) {
            self::assertExactIndexDefinition($nodeId, $index, $existing);

            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $name = $grammar->wrap($index['name']);
        $table = $grammar->wrapTable($index['table']);
        $columns = implode(', ', array_map(
            static fn (string $column): string => $grammar->wrap($column),
            $index['columns'],
        ));

        $sql = "CREATE UNIQUE INDEX {$name} ON {$table} ({$columns})";
        if ($index['predicate'] !== null) {
            $sql .= ' WHERE '.$index['predicate'];
        }

        DB::statement($sql);

        $signature = self::signature('unique_index', $index);
        DB::statement(
            "COMMENT ON INDEX {$name} IS ".self::quoteLiteral($signature),
        );

        $created = self::indexMetadata($index['name']);
        if ($created === null) {
            throw new LogicException($nodeId.' write-fence failed to create '.$index['name'].'.');
        }

        self::assertExactIndexDefinition($nodeId, $index, $created);
    }

    /**
     * @param  array{name: string, table: string, columns: list<string>, predicate: string}  $constraint
     */
    private static function installExclusionConstraint(string $nodeId, array $constraint): void
    {
        self::assertTableColumns($nodeId, $constraint['table'], $constraint['name'], $constraint['columns']);

        if (count($constraint['columns']) !== 3 || $constraint['columns'][0] !== 'organization_id' || $constraint['columns'][2] !== 'occupied_during') {
            throw new LogicException($nodeId.' write-fence received an unsupported exclusion definition for '.$constraint['name'].'.');
        }

        $existing = self::exclusionMetadata($constraint['table'], $constraint['name']);
        if ($existing !== null) {
            self::assertExactExclusionDefinition($nodeId, $constraint, $existing);

            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable($constraint['table']);
        $name = $grammar->wrap($constraint['name']);
        $organization = $grammar->wrap($constraint['columns'][0]);
        $resource = $grammar->wrap($constraint['columns'][1]);
        $occupiedDuring = $grammar->wrap($constraint['columns'][2]);

        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$name} EXCLUDE USING gist "
            ."({$organization} WITH =, {$resource} WITH =, {$occupiedDuring} WITH &&) "
            .'WHERE ('.$constraint['predicate'].')',
        );

        $signature = self::signature('exclusion_constraint', $constraint);
        DB::statement(
            "COMMENT ON CONSTRAINT {$name} ON {$table} IS ".self::quoteLiteral($signature),
        );

        $created = self::exclusionMetadata($constraint['table'], $constraint['name']);
        if ($created === null) {
            throw new LogicException($nodeId.' write-fence failed to create '.$constraint['name'].'.');
        }

        self::assertExactExclusionDefinition($nodeId, $constraint, $created);
    }

    /**
     * @param  list<string>  $columns
     */
    private static function assertTableColumns(string $nodeId, string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            throw new LogicException($nodeId.' write-fence missing table '.$table.' for '.$name.'.');
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new LogicException($nodeId.' write-fence missing '.$table.'.'.$column.' for '.$name.'.');
            }
        }
    }

    private static function assertPostgres(string $nodeId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException($nodeId.' write-fence requires PostgreSQL.');
        }
    }

    /**
     * @return array{table: string, unique: bool, method: string, columns: list<string>, signature: string}|null
     */
    private static function indexMetadata(string $name): ?array
    {
        $row = DB::selectOne(
            "SELECT tbl.relname AS table_name,
                    CASE WHEN idx.indisunique THEN 1 ELSE 0 END AS is_unique,
                    am.amname AS access_method,
                    COALESCE(obj_description(index_cls.oid, 'pg_class'), '') AS signature,
                    COALESCE((
                        SELECT json_agg(att.attname ORDER BY key_column.ordinal_position)::text
                        FROM unnest(idx.indkey) WITH ORDINALITY AS key_column(attnum, ordinal_position)
                        JOIN pg_attribute att
                          ON att.attrelid = idx.indrelid
                         AND att.attnum = key_column.attnum
                        WHERE key_column.ordinal_position <= idx.indnkeyatts
                    ), '[]') AS columns_json
             FROM pg_class index_cls
             JOIN pg_namespace index_ns ON index_ns.oid = index_cls.relnamespace
             JOIN pg_index idx ON idx.indexrelid = index_cls.oid
             JOIN pg_class tbl ON tbl.oid = idx.indrelid
             JOIN pg_am am ON am.oid = index_cls.relam
             WHERE index_ns.nspname = current_schema()
               AND index_cls.relkind = 'i'
               AND index_cls.relname = ?
             LIMIT 1",
            [$name],
        );

        if ($row === null) {
            return null;
        }

        $columns = self::decodeColumns((string) ($row->columns_json ?? '[]'), $name);

        return [
            'table' => (string) ($row->table_name ?? ''),
            'unique' => (int) ($row->is_unique ?? 0) === 1,
            'method' => (string) ($row->access_method ?? ''),
            'columns' => $columns,
            'signature' => (string) ($row->signature ?? ''),
        ];
    }

    /**
     * @return array{table: string, type: string, columns: list<string>, signature: string}|null
     */
    private static function exclusionMetadata(string $table, string $name): ?array
    {
        $row = DB::selectOne(
            "SELECT cls.relname AS table_name,
                    con.contype AS constraint_type,
                    COALESCE(obj_description(con.oid, 'pg_constraint'), '') AS signature,
                    COALESCE((
                        SELECT json_agg(att.attname ORDER BY key_column.ordinal_position)::text
                        FROM unnest(con.conkey) WITH ORDINALITY AS key_column(attnum, ordinal_position)
                        JOIN pg_attribute att
                          ON att.attrelid = con.conrelid
                         AND att.attnum = key_column.attnum
                    ), '[]') AS columns_json
             FROM pg_constraint con
             JOIN pg_class cls ON cls.oid = con.conrelid
             JOIN pg_namespace ns ON ns.oid = cls.relnamespace
             WHERE ns.nspname = current_schema()
               AND cls.relname = ?
               AND con.conname = ?
             LIMIT 1",
            [$table, $name],
        );

        if ($row === null) {
            return null;
        }

        return [
            'table' => (string) ($row->table_name ?? ''),
            'type' => (string) ($row->constraint_type ?? ''),
            'columns' => self::decodeColumns((string) ($row->columns_json ?? '[]'), $name),
            'signature' => (string) ($row->signature ?? ''),
        ];
    }

    /**
     * @param  array{name: string, table: string, columns: list<string>, predicate: string|null}  $expected
     * @param  array{table: string, unique: bool, method: string, columns: list<string>, signature: string}  $actual
     */
    private static function assertExactIndexDefinition(string $nodeId, array $expected, array $actual): void
    {
        if ($actual['table'] !== $expected['table']
            || ! $actual['unique']
            || $actual['method'] !== 'btree'
            || $actual['columns'] !== $expected['columns']
            || $actual['signature'] !== self::signature('unique_index', $expected)) {
            throw new LogicException(
                $nodeId.' write-fence found conflicting existing definition for '.$expected['name'].'.',
            );
        }
    }

    /**
     * @param  array{name: string, table: string, columns: list<string>, predicate: string}  $expected
     * @param  array{table: string, type: string, columns: list<string>, signature: string}  $actual
     */
    private static function assertExactExclusionDefinition(string $nodeId, array $expected, array $actual): void
    {
        if ($actual['table'] !== $expected['table']
            || $actual['type'] !== 'x'
            || $actual['columns'] !== $expected['columns']
            || $actual['signature'] !== self::signature('exclusion_constraint', $expected)) {
            throw new LogicException(
                $nodeId.' write-fence found conflicting existing definition for '.$expected['name'].'.',
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function decodeColumns(string $json, string $name): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new LogicException('Invalid PostgreSQL index metadata for '.$name.'.');
        }

        $columns = [];
        foreach ($decoded as $column) {
            if (! is_string($column)) {
                throw new LogicException('Invalid PostgreSQL index column metadata for '.$name.'.');
            }

            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function signature(string $kind, array $definition): string
    {
        return 'prawkonaraz:index-write-fence:v1:'.hash(
            'sha256',
            json_encode(array_merge(['kind' => $kind], $definition), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    private static function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
