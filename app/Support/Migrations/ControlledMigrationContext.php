<?php

namespace App\Support\Migrations;

use LogicException;

final class ControlledMigrationContext
{
    private static bool $active = false;

    private static ?string $phase = null;

    private static ?string $nodeId = null;

    private static ?string $executionIdentity = null;

    public static function enter(string $phase, string $nodeId, string $executionIdentity): void
    {
        if (self::$active) {
            throw new LogicException('Controlled migration context is already active.');
        }

        self::$active = true;
        self::$phase = $phase;
        self::$nodeId = $nodeId;
        self::$executionIdentity = $executionIdentity;
    }

    public static function leave(): void
    {
        self::$active = false;
        self::$phase = null;
        self::$nodeId = null;
        self::$executionIdentity = null;
    }

    public static function assertActive(string $phase, string $nodeId): void
    {
        if (! self::$active || self::$phase !== $phase || self::$nodeId !== $nodeId || self::$executionIdentity === null) {
            throw new LogicException("Migration {$nodeId}/{$phase} must run through migration:controlled with the registered execution identity.");
        }
    }
}
