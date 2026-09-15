<?php

namespace Tests\Feature;

use App\Support\Operations\GoLiveEvidenceValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class GoLiveEvidenceCoreTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_complete_sanitized_manifest_passes_for_exact_release_and_artifact(): void
    {
        CarbonImmutable::setTestNow('2026-09-15T12:00:00+02:00');
        $release = str_repeat('a', 40);
        $artifact = str_repeat('b', 64);
        $path = $this->manifestPath($this->validManifest($release, $artifact));

        try {
            $exit = Artisan::call('operations:go-live:evidence', [
                'manifest' => $path,
                '--release' => $release,
                '--artifact-sha256' => $artifact,
                '--json' => true,
            ]);

            $this->assertSame(Command::SUCCESS, $exit);
            $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('PASS', $payload['status']);
            $this->assertSame(9, $payload['evidence_count']);
            $this->assertFalse($payload['production_activation_performed']);
            $this->assertFalse($payload['production_ready_claim']);
        } finally {
            @unlink($path);
        }
    }

    public function test_missing_external_evidence_fails_closed(): void
    {
        CarbonImmutable::setTestNow('2026-09-15T12:00:00+02:00');
        $release = str_repeat('a', 40);
        $artifact = str_repeat('b', 64);
        $manifest = $this->validManifest($release, $artifact);
        array_pop($manifest['evidence']);
        $path = $this->manifestPath($manifest);

        try {
            $exit = Artisan::call('operations:go-live:evidence', [
                'manifest' => $path,
                '--release' => $release,
                '--artifact-sha256' => $artifact,
                '--json' => true,
            ]);

            $this->assertSame(Command::FAILURE, $exit);
            $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('FAIL', $payload['status']);
            $this->assertStringContainsString('incomplete', $payload['error']);
            $this->assertFalse($payload['production_activation_performed']);
        } finally {
            @unlink($path);
        }
    }

    public function test_restore_target_violation_and_wrong_release_smoke_are_rejected(): void
    {
        CarbonImmutable::setTestNow('2026-09-15T12:00:00+02:00');
        $release = str_repeat('a', 40);
        $artifact = str_repeat('b', 64);

        $restore = $this->validManifest($release, $artifact);
        $restore['evidence'][1]['details']['postgres_rpo_minutes'] = 5.1;
        $restorePath = $this->manifestPath($restore);

        try {
            $this->expectException(\LogicException::class);
            app(GoLiveEvidenceValidator::class)->validateFile($restorePath, $release, $artifact);
        } finally {
            @unlink($restorePath);
        }
    }

    public function test_target_smoke_must_bind_exact_release_and_artifact(): void
    {
        CarbonImmutable::setTestNow('2026-09-15T12:00:00+02:00');
        $release = str_repeat('a', 40);
        $artifact = str_repeat('b', 64);
        $manifest = $this->validManifest($release, $artifact);
        $manifest['evidence'][8]['details']['release_sha'] = str_repeat('c', 40);
        $path = $this->manifestPath($manifest);

        try {
            $this->expectException(\LogicException::class);
            app(GoLiveEvidenceValidator::class)->validateFile($path, $release, $artifact);
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string,mixed> */
    private function validManifest(string $release, string $artifact): array
    {
        $observedAt = '2026-09-15T10:00:00+02:00';

        return [
            'policy_version' => '2026-09-15-v1',
            'environment' => 'production',
            'release_sha' => $release,
            'artifact_sha256' => $artifact,
            'evidence' => [
                $this->entry('target_production_configuration_preflight', $observedAt, ['command_passed' => true]),
                $this->entry('target_infrastructure_restore_drill', $observedAt, [
                    'isolated_restore' => true,
                    'critical_integrity_assertions' => true,
                    'redis_empty_recovery' => true,
                    'postgres_rpo_minutes' => 2,
                    'postgres_rto_minutes' => 20,
                    'object_rpo_minutes' => 15,
                    'object_rto_minutes' => 45,
                ]),
                $this->entry('production_backup_and_PITR_evidence', $observedAt, [
                    'encrypted_base_backup' => true,
                    'pitr_capable' => true,
                    'off_primary_failure_domain_copy' => true,
                ]),
                $this->entry('production_object_versioning_and_restore_evidence', $observedAt, [
                    'versioning_or_equivalent' => true,
                    'encryption' => true,
                    'single_object_restore' => true,
                    'scoped_bulk_restore' => true,
                ]),
                $this->entry('production_secret_manager_or_equivalent_injection_evidence', $observedAt, [
                    'runtime_injection' => true,
                    'repository_secret_material_absent' => true,
                    'rotation_path_documented' => true,
                ]),
                $this->entry('production_monitoring_dashboards_and_alert_routes', $observedAt, [
                    'dashboards_active' => true,
                    'critical_alert_routes_active' => true,
                ]),
                $this->entry('incident_contact_roster_and_paging_smoke_test', $observedAt, [
                    'contact_roster_resolved' => true,
                    'paging_reached_human' => true,
                ]),
                $this->entry('reconciliation_scheduler_execution_and_alert_delivery_smoke_test', $observedAt, [
                    'scheduler_executed' => true,
                    'finding_failure_alert_reached_operator' => true,
                ]),
                $this->entry('target_environment_release_smoke_test', $observedAt, [
                    'https' => true,
                    'health_live' => true,
                    'health_ready' => true,
                    'release_sha' => $release,
                    'artifact_sha256' => $artifact,
                ]),
            ],
        ];
    }

    /** @param array<string,mixed> $details
     *  @return array<string,mixed>
     */
    private function entry(string $id, string $observedAt, array $details): array
    {
        return [
            'id' => $id,
            'status' => 'PASS',
            'observed_at' => $observedAt,
            'evidence_ref' => 'ops/'.$id.'/record-001',
            'details' => $details,
        ];
    }

    /** @param array<string,mixed> $manifest */
    private function manifestPath(array $manifest): string
    {
        $path = tempnam(sys_get_temp_dir(), 'go-live-evidence-');
        if ($path === false) {
            $this->fail('Unable to allocate temporary manifest path.');
        }

        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }
}
