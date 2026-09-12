<?php

namespace App\Support\Privacy;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;

final class RetentionPolicy
{
    public function version(): string
    {
        $version = config('retention.policy_version');
        if (! is_string($version) || trim($version) === '') {
            throw new LogicException('Retention policy version is unavailable.');
        }

        return $version;
    }

    /** @return array<string,mixed> */
    public function definition(string $dataClass): array
    {
        $definition = config('retention.classes.'.$dataClass);
        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown retention data class: {$dataClass}");
        }

        return $definition;
    }

    public function eligibleAt(string $dataClass, CarbonImmutable $anchor, bool $legalHold = false): ?CarbonImmutable
    {
        $definition = $this->definition($dataClass);
        if ($legalHold && (($definition['legal_hold'] ?? false) === true)) {
            return null;
        }

        $clock = $definition['clock'] ?? null;
        if (! is_array($clock) || ! is_string($clock['kind'] ?? null)) {
            throw new LogicException("Retention clock is invalid for {$dataClass}.");
        }

        $kind = $clock['kind'];
        $value = $clock['value'] ?? null;

        return match ($kind) {
            'days_after_anchor' => $anchor->addDays($this->positiveInt($value, $dataClass)),
            'months_after_anchor' => $anchor->addMonthsNoOverflow($this->positiveInt($value, $dataClass)),
            'fiscal_year_following_start_plus_years' => $anchor->addYear()->startOfYear()->addYears($this->positiveInt($value, $dataClass)),
            'inherit', 'deferred' => null,
            default => throw new LogicException("Unsupported retention clock {$kind} for {$dataClass}."),
        };
    }

    public function mayNormalApplicationRolePurge(): bool
    {
        return (bool) config('retention.normal_application_role_may_purge', true);
    }

    public function globalDeleteAfterDays(): ?int
    {
        $value = config('retention.global_delete_after_days');

        return is_int($value) ? $value : null;
    }

    private function positiveInt(mixed $value, string $dataClass): int
    {
        if (! is_int($value) || $value <= 0) {
            throw new LogicException("Retention duration must be a positive integer for {$dataClass}.");
        }

        return $value;
    }
}
