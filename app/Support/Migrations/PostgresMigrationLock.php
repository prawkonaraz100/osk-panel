<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use LogicException;

final class PostgresMigrationLock
{
    public const KEY_NAMESPACE = 519662;
    public const KEY_PLAN = 5001;

    private bool $acquired = false;

    public function acquire(): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Controlled migrations require PostgreSQL.');
        }

        $row = DB::selectOne('select pg_try_advisory_lock(?, ?) as acquired', [self::KEY_NAMESPACE, self::KEY_PLAN]);
        $this->acquired = filter_var($row->acquired ?? false, FILTER_VALIDATE_BOOL);

        return $this->acquired;
    }

    public function release(): void
    {
        if (! $this->acquired) {
            return;
        }

        DB::selectOne('select pg_advisory_unlock(?, ?)', [self::KEY_NAMESPACE, self::KEY_PLAN]);
        $this->acquired = false;
    }
}
