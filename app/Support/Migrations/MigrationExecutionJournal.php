<?php

namespace App\Support\Migrations;

use LogicException;

final class MigrationExecutionJournal
{
    public function append(array $event): void
    {
        $path = config('migration.evidence_path');
        if (app()->environment('production') && (! is_string($path) || $path === '')) {
            throw new LogicException('Production controlled migration requires MIGRATION_EVIDENCE_PATH backed by durable deployment evidence storage.');
        }

        $path = is_string($path) && $path !== '' ? $path : storage_path('logs/migration-execution.jsonl');
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new LogicException("Cannot create migration evidence directory: {$directory}");
        }

        $record = $event + ['recorded_at' => now()->toIso8601String()];
        if (file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new LogicException('Cannot append migration execution evidence.');
        }
    }
}
