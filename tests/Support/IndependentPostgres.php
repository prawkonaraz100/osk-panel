<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

final class IndependentPostgres
{
    public static function connection(): PDO
    {
        $config = config('database.connections.pgsql');
        if (! is_array($config) || ($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('S5-TST-001 requires the PostgreSQL connection configuration.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'],
            $config['database'],
        );

        return new PDO($dsn, (string) $config['username'], (string) $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** @return array{0: PDO, 1: PDO} */
    public static function pair(): array
    {
        return [self::connection(), self::connection()];
    }
}
