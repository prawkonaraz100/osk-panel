<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final class ConstraintWriteFence
{
    /**
     * @param list<array{
     *   name: string,
     *   table: string,
     *   columns: list<string>,
     *   predicate: string
     * }> $checks
     */
    public static function install(string $nodeId, array $checks): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException($nodeId.' write-fence requires PostgreSQL.');
        }

        ConstraintPreflight::assertChecks($nodeId, $checks);

        foreach ($checks as $check) {
            self::installCheck($nodeId, $check);
        }
    }

    /**
     * @param array{
     *   name: string,
     *   table: string,
     *   columns: list<string>,
     *   predicate: string
     * } $check
     */
    private static function installCheck(string $nodeId, array $check): void
    {
        if (strlen($check['name']) > 63) {
            throw new LogicException($nodeId.' write-fence CHECK name exceeds PostgreSQL identifier limit: '.$check['name'].'.');
        }

        if (! Schema::hasTable($check['table'])) {
            throw new LogicException($nodeId.' write-fence missing table '.$check['table'].' for '.$check['name'].'.');
        }

        foreach ($check['columns'] as $column) {
            if (! Schema::hasColumn($check['table'], $column)) {
                throw new LogicException($nodeId.' write-fence missing '.$check['table'].'.'.$column.' for '.$check['name'].'.');
            }
        }

        $existing = self::constraintMetadata($check['table'], $check['name']);
        if ($existing !== null) {
            self::assertExactDefinition($nodeId, $check, $existing);

            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable($check['table']);
        $constraint = $grammar->wrap($check['name']);
        $predicate = str_replace('src.', '', $check['predicate']);

        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$predicate}) NOT VALID",
        );

        DB::statement(
            "COMMENT ON CONSTRAINT {$constraint} ON {$table} IS ".self::quoteLiteral(self::signature($check)),
        );

        $created = self::constraintMetadata($check['table'], $check['name']);
        if ($created === null) {
            throw new LogicException($nodeId.' write-fence failed to create '.$check['name'].'.');
        }

        self::assertExactDefinition($nodeId, $check, $created);
    }

    /**
     * @return array{table: string, type: string, validated: bool, signature: string}|null
     */
    private static function constraintMetadata(string $table, string $name): ?array
    {
        $row = DB::selectOne(
            "SELECT cls.relname AS table_name,
                    con.contype AS constraint_type,
                    CASE WHEN con.convalidated THEN 1 ELSE 0 END AS is_validated,
                    COALESCE(obj_description(con.oid, 'pg_constraint'), '') AS signature
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
            'validated' => (int) ($row->is_validated ?? 0) === 1,
            'signature' => (string) ($row->signature ?? ''),
        ];
    }

    /**
     * @param  array{name: string, table: string, columns: list<string>, predicate: string}  $expected
     * @param  array{table: string, type: string, validated: bool, signature: string}  $actual
     */
    private static function assertExactDefinition(string $nodeId, array $expected, array $actual): void
    {
        if ($actual['table'] !== $expected['table']
            || $actual['type'] !== 'c'
            || $actual['validated']
            || $actual['signature'] !== self::signature($expected)) {
            throw new LogicException(
                $nodeId.' write-fence found conflicting existing CHECK definition for '.$expected['name'].'.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $check
     */
    private static function signature(array $check): string
    {
        return 'prawkonaraz:constraint-write-fence:v1:'.hash(
            'sha256',
            json_encode($check, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    private static function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
