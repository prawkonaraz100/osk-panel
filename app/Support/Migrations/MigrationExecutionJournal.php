<?php

namespace App\Support\Migrations;

use LogicException;

final class MigrationExecutionJournal
{
    public function append(array $event): void
    {
        $path = $this->path();
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new LogicException("Cannot create migration evidence directory: {$directory}");
        }

        $record = $event + ['recorded_at' => now()->toIso8601String()];
        if (file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new LogicException('Cannot append migration execution evidence.');
        }
    }

    public function requiresReviewedResume(string $executionIdentity, string $nodeId): bool
    {
        $path = $this->path();
        if (! is_file($path)) {
            return false;
        }

        $lastAttemptState = null;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new LogicException('Cannot read migration execution evidence.');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $record = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
                if (($record['execution_identity'] ?? null) !== $executionIdentity || ($record['node_id'] ?? null) !== $nodeId) {
                    continue;
                }

                $event = $record['event'] ?? null;
                if ($event === 'migration_node_started') {
                    $lastAttemptState = 'started';
                } elseif ($event === 'migration_node_succeeded') {
                    $lastAttemptState = 'succeeded';
                } elseif ($event === 'migration_node_failed') {
                    $lastAttemptState = 'failed';
                }
            }
        } finally {
            fclose($handle);
        }

        return in_array($lastAttemptState, ['started', 'failed'], true);
    }

    private function path(): string
    {
        $path = config('migration.evidence_path');
        if (app()->environment('production') && (! is_string($path) || $path === '')) {
            throw new LogicException('Production controlled migration requires MIGRATION_EVIDENCE_PATH backed by durable deployment evidence storage.');
        }

        return is_string($path) && $path !== '' ? $path : storage_path('logs/migration-execution.jsonl');
    }
}
