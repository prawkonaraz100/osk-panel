<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class ProductionHealthProbe
{
    /** @return array{status:string,dependencies:array{database:string,redis:string}} */
    public function readiness(): array
    {
        $database = $this->databaseReady();
        $redis = $this->redisReady();

        return [
            'status' => $database && $redis ? 'ok' : 'unavailable',
            'dependencies' => [
                'database' => $database ? 'ok' : 'unavailable',
                'redis' => $redis ? 'ok' : 'unavailable',
            ],
        ];
    }

    private function databaseReady(): bool
    {
        try {
            return DB::selectOne('select 1 as ready') !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function redisReady(): bool
    {
        try {
            return Redis::connection()->command('ping') !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
