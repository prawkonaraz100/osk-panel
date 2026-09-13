<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final class ConstraintPreflight
{
    /**
     * @param list<array{
     *   name: string,
     *   table: string,
     *   columns: list<string>,
     *   predicate: string
     * }> $checks
     */
    public static function assertChecks(string $nodeId, array $checks): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException($nodeId.' preflight requires PostgreSQL.');
        }

        foreach ($checks as $check) {
            self::assertCheck($nodeId, $check);
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
    private static function assertCheck(string $nodeId, array $check): void
    {
        if (! Schema::hasTable($check['table'])) {
            throw new LogicException($nodeId.' preflight missing table '.$check['table'].' for '.$check['name'].'.');
        }

        foreach ($check['columns'] as $column) {
            if (! Schema::hasColumn($check['table'], $column)) {
                throw new LogicException($nodeId.' preflight missing '.$check['table'].'.'.$column.' for '.$check['name'].'.');
            }
        }

        $table = DB::connection()->getQueryGrammar()->wrapTable($check['table']);
        $violation = DB::selectOne(
            'SELECT 1 AS violation_found FROM '.$table.' AS src WHERE NOT ('.$check['predicate'].') LIMIT 1',
        );

        if ($violation !== null) {
            throw new LogicException(
                $nodeId.' preflight found rows violating '.$check['name']
                .'; reviewed remediation is required before write-fence.',
            );
        }
    }
}
