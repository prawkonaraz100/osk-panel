<?php

namespace App\Support\Operations;

use InvalidArgumentException;
use LogicException;

final class RecoveryPolicy
{
    public function version(): string
    {
        $version = config('recovery.policy_version');
        if (! is_string($version) || trim($version) === '') {
            throw new LogicException('Recovery policy version is unavailable.');
        }

        return $version;
    }

    /** @return array<string,mixed> */
    public function tier(string $tier): array
    {
        $definition = config('recovery.tiers.'.$tier);
        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown recovery tier: {$tier}");
        }

        return $definition;
    }

    public function rpoMinutes(string $tier): ?int
    {
        $value = $this->tier($tier)['rpo_minutes'] ?? null;

        return is_int($value) ? $value : null;
    }

    public function rtoMinutes(string $tier): int
    {
        $value = $this->tier($tier)['rto_minutes'] ?? null;
        if (! is_int($value) || $value <= 0) {
            throw new LogicException("Recovery RTO must be a positive integer for {$tier}.");
        }

        return $value;
    }

    public function isSourceOfTruth(string $tier): bool
    {
        return ($this->tier($tier)['source_of_truth'] ?? false) === true;
    }

    public function goLiveRequiresRestoreDrill(): bool
    {
        return (bool) config('recovery.go_live_requires_restore_drill', true);
    }

    public function drillIntervalDays(): int
    {
        $value = config('recovery.drill_interval_days');
        if (! is_int($value) || $value <= 0) {
            throw new LogicException('Recovery drill interval must be a positive integer.');
        }

        return $value;
    }
}
