<?php

namespace App\Support\Operations;

use Carbon\CarbonImmutable;
use LogicException;
use Throwable;

final class GoLiveEvidenceValidator
{
    /**
     * @return array{
     *   status:string,
     *   policy_version:string,
     *   environment:string,
     *   release_sha:string,
     *   artifact_sha256:string,
     *   evidence_count:int,
     *   required_evidence_count:int,
     *   production_activation_performed:bool,
     *   production_ready_claim:bool
     * }
     */
    public function validateFile(
        string $path,
        string $expectedReleaseSha,
        string $expectedArtifactSha256,
        ?CarbonImmutable $now = null,
    ): array {
        $this->assertSha($expectedReleaseSha, 40, 'Expected release SHA');
        $this->assertSha($expectedArtifactSha256, 64, 'Expected artifact SHA-256');

        if (is_file($path) === false || is_readable($path) === false) {
            throw new LogicException('Go-live evidence manifest is not a readable file.');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new LogicException('Go-live evidence manifest could not be read.');
        }

        try {
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new LogicException('Go-live evidence manifest is not valid JSON.', 0, $exception);
        }

        if (is_array($manifest) === false) {
            throw new LogicException('Go-live evidence manifest root must be an object.');
        }

        $this->assertExactKeys(
            $manifest,
            ['policy_version', 'environment', 'release_sha', 'artifact_sha256', 'evidence'],
            'manifest',
        );

        $policyVersion = $this->requiredString($manifest, 'policy_version', 'manifest');
        $expectedPolicy = config('go_live_evidence.policy_version');
        if (is_string($expectedPolicy) === false || hash_equals($expectedPolicy, $policyVersion) === false) {
            throw new LogicException('Go-live evidence policy version mismatch.');
        }

        if ($this->requiredString($manifest, 'environment', 'manifest') !== 'production') {
            throw new LogicException('Go-live evidence must target the production environment.');
        }

        $releaseSha = $this->requiredString($manifest, 'release_sha', 'manifest');
        $artifactSha256 = $this->requiredString($manifest, 'artifact_sha256', 'manifest');
        $this->assertSha($releaseSha, 40, 'Manifest release SHA');
        $this->assertSha($artifactSha256, 64, 'Manifest artifact SHA-256');

        if (hash_equals($expectedReleaseSha, $releaseSha) === false) {
            throw new LogicException('Go-live evidence release SHA does not match the requested release.');
        }
        if (hash_equals($expectedArtifactSha256, $artifactSha256) === false) {
            throw new LogicException('Go-live evidence artifact SHA-256 does not match the requested artifact.');
        }

        $evidence = $manifest['evidence'] ?? null;
        if (is_array($evidence) === false || array_is_list($evidence) === false) {
            throw new LogicException('Go-live evidence must be a JSON array.');
        }

        $required = config('go_live_evidence.required_evidence');
        if (is_array($required) === false || $required === []) {
            throw new LogicException('Required go-live evidence registry is unavailable.');
        }

        $requiredIds = [];
        foreach ($required as $id) {
            if (is_string($id) === false || trim($id) === '') {
                throw new LogicException('Required go-live evidence registry is invalid.');
            }
            $requiredIds[] = $id;
        }

        $now ??= CarbonImmutable::now();
        $seen = [];

        foreach ($evidence as $entry) {
            if (is_array($entry) === false) {
                throw new LogicException('Each go-live evidence entry must be an object.');
            }

            $this->assertExactKeys($entry, ['id', 'status', 'observed_at', 'evidence_ref', 'details'], 'evidence entry');

            $id = $this->requiredString($entry, 'id', 'evidence entry');
            if (in_array($id, $requiredIds, true) === false) {
                throw new LogicException("Unknown go-live evidence id: {$id}.");
            }
            if (isset($seen[$id])) {
                throw new LogicException("Duplicate go-live evidence id: {$id}.");
            }
            $seen[$id] = true;

            if ($this->requiredString($entry, 'status', $id) !== 'PASS') {
                throw new LogicException("Go-live evidence {$id} is not PASS.");
            }

            $observedAt = $this->parseObservedAt($this->requiredString($entry, 'observed_at', $id), $id);
            $futureSkew = config('go_live_evidence.future_clock_skew_seconds', 300);
            if (is_int($futureSkew) === false || $futureSkew < 0) {
                throw new LogicException('Go-live evidence future clock skew policy is invalid.');
            }
            if ($observedAt->greaterThan($now->addSeconds($futureSkew))) {
                throw new LogicException("Go-live evidence {$id} has a future observed_at.");
            }

            $evidenceRef = $this->requiredString($entry, 'evidence_ref', $id);
            if (strlen($evidenceRef) > 200 || str_contains($evidenceRef, '?') || str_contains($evidenceRef, '#')) {
                throw new LogicException("Go-live evidence {$id} reference must be an opaque or query-free reference.");
            }

            $details = $entry['details'] ?? null;
            if (is_array($details) === false) {
                throw new LogicException("Go-live evidence {$id} details must be an object.");
            }

            $this->validateDetails($id, $details, $releaseSha, $artifactSha256, $observedAt, $now);
        }

        $seenIds = array_keys($seen);
        sort($seenIds);
        $expectedIds = $requiredIds;
        sort($expectedIds);
        if ($seenIds !== $expectedIds) {
            $missing = array_values(array_diff($expectedIds, $seenIds));

            throw new LogicException('Go-live evidence manifest is incomplete: '.implode(', ', $missing).'.');
        }

        return [
            'status' => 'PASS',
            'policy_version' => $policyVersion,
            'environment' => 'production',
            'release_sha' => $releaseSha,
            'artifact_sha256' => $artifactSha256,
            'evidence_count' => count($seenIds),
            'required_evidence_count' => count($expectedIds),
            'production_activation_performed' => false,
            'production_ready_claim' => false,
        ];
    }

    /** @param array<string,mixed> $details */
    private function validateDetails(
        string $id,
        array $details,
        string $releaseSha,
        string $artifactSha256,
        CarbonImmutable $observedAt,
        CarbonImmutable $now,
    ): void {
        match ($id) {
            'target_production_configuration_preflight' => $this->validateAllTrue(
                $details,
                ['command_passed'],
                $id,
            ),
            'target_infrastructure_restore_drill' => $this->validateRestoreDrill($details, $observedAt, $now),
            'production_backup_and_PITR_evidence' => $this->validateAllTrue(
                $details,
                ['encrypted_base_backup', 'pitr_capable', 'off_primary_failure_domain_copy'],
                $id,
            ),
            'production_object_versioning_and_restore_evidence' => $this->validateAllTrue(
                $details,
                ['versioning_or_equivalent', 'encryption', 'single_object_restore', 'scoped_bulk_restore'],
                $id,
            ),
            'production_secret_manager_or_equivalent_injection_evidence' => $this->validateAllTrue(
                $details,
                ['runtime_injection', 'repository_secret_material_absent', 'rotation_path_documented'],
                $id,
            ),
            'production_monitoring_dashboards_and_alert_routes' => $this->validateAllTrue(
                $details,
                ['dashboards_active', 'critical_alert_routes_active'],
                $id,
            ),
            'incident_contact_roster_and_paging_smoke_test' => $this->validateAllTrue(
                $details,
                ['contact_roster_resolved', 'paging_reached_human'],
                $id,
            ),
            'reconciliation_scheduler_execution_and_alert_delivery_smoke_test' => $this->validateAllTrue(
                $details,
                ['scheduler_executed', 'finding_failure_alert_reached_operator'],
                $id,
            ),
            'target_environment_release_smoke_test' => $this->validateReleaseSmoke(
                $details,
                $releaseSha,
                $artifactSha256,
            ),
            default => throw new LogicException("Unsupported go-live evidence id: {$id}."),
        };
    }

    /** @param array<string,mixed> $details */
    private function validateRestoreDrill(
        array $details,
        CarbonImmutable $observedAt,
        CarbonImmutable $now,
    ): void {
        $expected = [
            'isolated_restore',
            'critical_integrity_assertions',
            'redis_empty_recovery',
            'postgres_rpo_minutes',
            'postgres_rto_minutes',
            'object_rpo_minutes',
            'object_rto_minutes',
        ];
        $this->assertExactKeys($details, $expected, 'target_infrastructure_restore_drill details');

        foreach (['isolated_restore', 'critical_integrity_assertions', 'redis_empty_recovery'] as $key) {
            if (($details[$key] ?? null) !== true) {
                throw new LogicException("Restore drill detail {$key} must be true.");
            }
        }

        $limits = [
            'postgres_rpo_minutes' => 5.0,
            'postgres_rto_minutes' => 60.0,
            'object_rpo_minutes' => 60.0,
            'object_rto_minutes' => 240.0,
        ];

        foreach ($limits as $key => $maximum) {
            $value = $details[$key] ?? null;
            if (is_int($value) === false && is_float($value) === false) {
                throw new LogicException("Restore drill measurement {$key} must be numeric.");
            }
            if ($value < 0 || $value > $maximum) {
                throw new LogicException("Restore drill measurement {$key} exceeds the accepted target.");
            }
        }

        $maxAgeDays = config('go_live_evidence.restore_drill_max_age_days', 90);
        if (is_int($maxAgeDays) === false || $maxAgeDays < 1) {
            throw new LogicException('Restore drill maximum age policy is invalid.');
        }
        if ($observedAt->lessThan($now->subDays($maxAgeDays))) {
            throw new LogicException('Target infrastructure restore drill evidence is older than the accepted interval.');
        }
    }

    /** @param array<string,mixed> $details
     *  @param list<string> $keys
     */
    private function validateAllTrue(array $details, array $keys, string $id): void
    {
        $this->assertExactKeys($details, $keys, "{$id} details");

        foreach ($keys as $key) {
            if (($details[$key] ?? null) !== true) {
                throw new LogicException("Go-live evidence {$id} detail {$key} must be true.");
            }
        }
    }

    /** @param array<string,mixed> $details */
    private function validateReleaseSmoke(array $details, string $releaseSha, string $artifactSha256): void
    {
        $this->assertExactKeys(
            $details,
            ['https', 'health_live', 'health_ready', 'release_sha', 'artifact_sha256'],
            'target_environment_release_smoke_test details',
        );

        foreach (['https', 'health_live', 'health_ready'] as $key) {
            if (($details[$key] ?? null) !== true) {
                throw new LogicException("Target release smoke detail {$key} must be true.");
            }
        }

        $detailRelease = $this->requiredString($details, 'release_sha', 'target release smoke');
        $detailArtifact = $this->requiredString($details, 'artifact_sha256', 'target release smoke');

        if (hash_equals($releaseSha, $detailRelease) === false) {
            throw new LogicException('Target release smoke was not executed for the manifest release SHA.');
        }
        if (hash_equals($artifactSha256, $detailArtifact) === false) {
            throw new LogicException('Target release smoke was not executed for the manifest artifact SHA-256.');
        }
    }

    private function assertSha(string $value, int $length, string $label): void
    {
        if (strlen($value) !== $length || preg_match('/^[0-9a-f]+$/', $value) !== 1) {
            throw new LogicException("{$label} must be lowercase hexadecimal with exactly {$length} characters.");
        }
    }

    /** @param array<string,mixed> $object
     *  @param list<string> $expected
     */
    private function assertExactKeys(array $object, array $expected, string $context): void
    {
        $actual = array_keys($object);
        sort($actual);
        $expectedKeys = $expected;
        sort($expectedKeys);

        if ($actual !== $expectedKeys) {
            throw new LogicException("{$context} contains missing or unexpected fields.");
        }
    }

    /** @param array<string,mixed> $object */
    private function requiredString(array $object, string $key, string $context): string
    {
        $value = $object[$key] ?? null;
        if (is_string($value) === false || trim($value) === '') {
            throw new LogicException("{$context} field {$key} must be a nonblank string.");
        }

        return trim($value);
    }

    private function parseObservedAt(string $value, string $id): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable $exception) {
            throw new LogicException("Go-live evidence {$id} observed_at is invalid.", 0, $exception);
        }
    }
}
