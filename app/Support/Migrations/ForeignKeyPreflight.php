<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final class ForeignKeyPreflight
{
    /**
     * @param list<array{
     *   name: string,
     *   source_table: string,
     *   source_columns: list<string>,
     *   target_table: string,
     *   target_columns: list<string>,
     *   where?: string,
     *   allow_missing_source_columns?: bool,
     *   allow_missing_target_columns?: bool
     * }> $relations
     */
    public static function assertRelations(string $nodeId, array $relations): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException($nodeId.' preflight requires PostgreSQL.');
        }

        foreach ($relations as $relation) {
            self::assertRelation($nodeId, $relation);
        }
    }

    /**
     * @param array{
     *   name: string,
     *   source_table: string,
     *   source_columns: list<string>,
     *   target_table: string,
     *   target_columns: list<string>,
     *   where?: string,
     *   allow_missing_source_columns?: bool,
     *   allow_missing_target_columns?: bool
     * } $relation
     */
    private static function assertRelation(string $nodeId, array $relation): void
    {
        $sourceTable = $relation['source_table'];
        $targetTable = $relation['target_table'];
        $sourceColumns = $relation['source_columns'];
        $targetColumns = $relation['target_columns'];

        if (count($sourceColumns) !== count($targetColumns) || $sourceColumns === []) {
            throw new LogicException($nodeId.' invalid FK preflight definition for '.$relation['name'].'.');
        }

        if (! Schema::hasTable($sourceTable)) {
            throw new LogicException($nodeId.' preflight missing source table '.$sourceTable.' for '.$relation['name'].'.');
        }
        if (! Schema::hasTable($targetTable)) {
            throw new LogicException($nodeId.' preflight missing target table '.$targetTable.' for '.$relation['name'].'.');
        }

        foreach ($sourceColumns as $column) {
            if (! Schema::hasColumn($sourceTable, $column)) {
                if ($relation['allow_missing_source_columns'] ?? false) {
                    return;
                }
                throw new LogicException($nodeId.' preflight missing '.$sourceTable.'.'.$column.' for '.$relation['name'].'.');
            }
        }
        foreach ($targetColumns as $column) {
            if (! Schema::hasColumn($targetTable, $column)) {
                if ($relation['allow_missing_target_columns'] ?? false) {
                    return;
                }
                throw new LogicException($nodeId.' preflight missing '.$targetTable.'.'.$column.' for '.$relation['name'].'.');
            }
        }

        $grammar = DB::connection()->getQueryGrammar();
        $source = $grammar->wrapTable($sourceTable);
        $target = $grammar->wrapTable($targetTable);

        $participation = [];
        $joins = [];
        foreach ($sourceColumns as $index => $sourceColumn) {
            $wrappedSource = $grammar->wrap('src.'.$sourceColumn);
            $wrappedTarget = $grammar->wrap('tgt.'.$targetColumns[$index]);
            $participation[] = $wrappedSource.' IS NOT NULL';
            $joins[] = $wrappedTarget.' = '.$wrappedSource;
        }

        $where = implode(' AND ', $participation);
        if (isset($relation['where']) && trim($relation['where']) !== '') {
            $where .= ' AND ('.$relation['where'].')';
        }

        $orphan = DB::selectOne(
            'SELECT 1 AS orphan_found FROM '.$source.' AS src'
            .' WHERE '.$where
            .' AND NOT EXISTS (SELECT 1 FROM '.$target.' AS tgt WHERE '.implode(' AND ', $joins).')'
            .' LIMIT 1',
        );

        if ($orphan !== null) {
            throw new LogicException(
                $nodeId.' preflight found orphan or tenant/context mismatch for '.$relation['name']
                .'; reviewed remediation is required before write-fence.',
            );
        }
    }
}
