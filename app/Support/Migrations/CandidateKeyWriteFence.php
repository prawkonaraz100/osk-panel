<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final class CandidateKeyWriteFence
{
    /**
     * @param  list<array{name: string, table: string, columns: list<string>}>  $keys
     */
    public static function install(string $nodeId, array $keys): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException($nodeId.' write-fence requires PostgreSQL.');
        }

        foreach ($keys as $key) {
            self::installKey($nodeId, $key);
        }
    }

    /**
     * @param  array{name: string, table: string, columns: list<string>}  $key
     */
    private static function installKey(string $nodeId, array $key): void
    {
        if (! Schema::hasTable($key['table'])) {
            throw new LogicException($nodeId.' write-fence missing table '.$key['table'].' for '.$key['name'].'.');
        }

        foreach ($key['columns'] as $column) {
            if (! Schema::hasColumn($key['table'], $column)) {
                throw new LogicException($nodeId.' write-fence missing '.$key['table'].'.'.$column.' for '.$key['name'].'.');
            }
        }

        $existing = self::constraintMetadata($key['name']);
        if ($existing !== null) {
            self::assertExactDefinition($nodeId, $key, $existing);

            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable($key['table']);
        $constraint = $grammar->wrap($key['name']);
        $columns = implode(', ', array_map(
            static fn (string $column): string => $grammar->wrap($column),
            $key['columns'],
        ));

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} UNIQUE ({$columns})");

        $created = self::constraintMetadata($key['name']);
        if ($created === null) {
            throw new LogicException($nodeId.' write-fence failed to create '.$key['name'].'.');
        }

        self::assertExactDefinition($nodeId, $key, $created);
    }

    /**
     * @return array{table: string, type: string, columns: list<string>}|null
     */
    private static function constraintMetadata(string $name): ?array
    {
        $row = DB::selectOne(
            "SELECT cls.relname AS table_name,
                    con.contype AS constraint_type,
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
               AND con.conname = ?
             LIMIT 1",
            [$name],
        );

        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) ($row->columns_json ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new LogicException("Invalid PostgreSQL candidate-key metadata for {$name}.");
        }

        $columns = [];
        foreach ($decoded as $column) {
            if (! is_string($column)) {
                throw new LogicException("Invalid PostgreSQL candidate-key column metadata for {$name}.");
            }

            $columns[] = $column;
        }

        return [
            'table' => (string) ($row->table_name ?? ''),
            'type' => (string) ($row->constraint_type ?? ''),
            'columns' => $columns,
        ];
    }

    /**
     * @param  array{name: string, table: string, columns: list<string>}  $expected
     * @param  array{table: string, type: string, columns: list<string>}  $actual
     */
    private static function assertExactDefinition(string $nodeId, array $expected, array $actual): void
    {
        if ($actual['table'] !== $expected['table']
            || $actual['type'] !== 'u'
            || $actual['columns'] !== $expected['columns']) {
            throw new LogicException(
                $nodeId.' write-fence found conflicting existing definition for '.$expected['name'].'.',
            );
        }
    }
}
