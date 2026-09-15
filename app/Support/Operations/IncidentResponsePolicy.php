<?php

namespace App\Support\Operations;

use InvalidArgumentException;
use LogicException;

final class IncidentResponsePolicy
{
    public function version(): string
    {
        $version = config('incident_response.policy_version');
        if (! is_string($version) || trim($version) === '') {
            throw new LogicException('Incident response policy version is unavailable.');
        }

        return $version;
    }

    /** @return array<string,mixed> */
    public function severity(string $severity): array
    {
        $definition = config('incident_response.severities.'.$severity);
        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown incident severity: {$severity}");
        }

        return $definition;
    }

    /** @return array<string,mixed> */
    public function runbook(string $runbook): array
    {
        $definition = config('incident_response.runbooks.'.$runbook);
        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown incident runbook: {$runbook}");
        }

        return $definition;
    }

    /** @return list<string> */
    public function requiredOwnerRoles(): array
    {
        $roles = config('incident_response.owner_roles');
        if (! is_array($roles)) {
            throw new LogicException('Incident owner roles are unavailable.');
        }

        return array_values(array_map(static fn (mixed $role): string => (string) $role, $roles));
    }

    public function productionContactRosterComplete(): bool
    {
        $refs = config('incident_response.contact_refs');
        if (! is_array($refs) || $refs === []) {
            return false;
        }

        foreach ($refs as $ref) {
            if (! is_string($ref) || trim($ref) === '') {
                return false;
            }
        }

        return true;
    }

    public function regulatorDeadlineHoursWhenRequired(): int
    {
        $hours = config('incident_response.personal_data_breach.supervisory_authority.deadline_hours_from_awareness_when_required');
        if (! is_int($hours) || $hours <= 0) {
            throw new LogicException('Personal data breach regulator deadline is invalid.');
        }

        return $hours;
    }

    public function regulatorNotificationIsAutomatic(): bool
    {
        return (bool) config('incident_response.personal_data_breach.supervisory_authority.automatic_notification', true);
    }

    public function everyPersonalDataBreachMustBeDocumented(): bool
    {
        return (bool) config('incident_response.personal_data_breach.document_every_breach', false);
    }
}
