<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class ProductionDeploymentPreflightTest extends TestCase
{
    public function test_secure_core_configuration_passes_without_pkk_or_payment_provider_requirements(): void
    {
        $this->configureSecureProduction();

        $exit = Artisan::call('operations:production:preflight', ['--json' => true]);

        $this->assertSame(Command::SUCCESS, $exit);

        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame(0, $payload['error_count']);
        $this->assertSame('frozen_not_required_for_core_launch', $payload['deferred_boundaries']['PKK_PWPW']);
        $this->assertSame(
            'external_provider_boundary_not_required_by_this_preflight',
            $payload['deferred_boundaries']['payment_provider_webhook'],
        );
        $this->assertSame('required_external', $payload['external_evidence']['target_database_PITR']);
        $this->assertSame('required_external', $payload['external_evidence']['paging_smoke']);
    }

    public function test_local_or_unsafe_defaults_fail_without_leaking_secret_values(): void
    {
        $this->configureSecureProduction();

        config()->set('app.env', 'local');
        config()->set('app.debug', true);
        config()->set('app.url', 'http://localhost');
        config()->set('logging.default', 'stack');
        config()->set('database.connections.pgsql.sslmode', 'prefer');
        config()->set('session.secure', false);
        config()->set('filesystems.disks.s3.key', 'test');
        config()->set('filesystems.disks.s3.secret', 'DO-NOT-LEAK-THIS-SECRET-MARKER');
        config()->set('mail.default', 'log');
        config()->set('incident_response.contact_refs.primary_on_call', '');
        config()->set('retention.executor.enabled', true);

        $exit = Artisan::call('operations:production:preflight', ['--json' => true]);

        $this->assertSame(Command::FAILURE, $exit);

        $output = trim(Artisan::output());
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('fail', $payload['status']);
        $this->assertGreaterThan(0, $payload['error_count']);
        $this->assertStringNotContainsString('DO-NOT-LEAK-THIS-SECRET-MARKER', $output);
        $this->assertContains(
            ['code' => 'retention.executor.disabled_at_baseline', 'severity' => 'error', 'status' => 'fail'],
            $payload['checks'],
        );
    }

    private function configureSecureProduction(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.debug', false);
        config()->set('app.url', 'https://osk.example.invalid');
        config()->set('app.key', 'unit-test-app-key-material-000000000000000000');

        config()->set('logging.default', 'json_stderr');
        config()->set('logging.channels.json_stderr.level', 'info');

        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.sslmode', 'verify-full');
        config()->set('cache.default', 'redis');
        config()->set('session.driver', 'redis');
        config()->set('session.secure', true);
        config()->set('session.http_only', true);
        config()->set('session.serialization', 'json');
        config()->set('queue.default', 'redis');
        config()->set('queue.failed.driver', 'database-uuids');

        config()->set('filesystems.default', 's3');
        config()->set('filesystems.disks.s3.bucket', 'osk-panel-production');
        config()->set('filesystems.disks.s3.region', 'eu-central-1');
        config()->set('filesystems.disks.s3.endpoint', 'https://objects.example.invalid');
        config()->set('filesystems.disks.s3.key', null);
        config()->set('filesystems.disks.s3.secret', null);

        config()->set('mail.default', 'smtp');
        config()->set('mail.from.address', 'noreply@prawkonaraz.invalid');
        config()->set('password_recovery.reset_url_template', 'https://osk.example.invalid/reset-password?token={token}');

        config()->set('security.sensitive_identifiers.lookup_key', 'unit-test-sensitive-lookup-key-material-000000000000000000');

        config()->set('internal_exams.station_heartbeat_fresh_seconds', 60);
        config()->set('internal_exams.execution_token_ttl_minutes', 15);
        config()->set('internal_exams.result_token_ttl_minutes', 15);
        config()->set('internal_exams.remote_access_ttl_minutes', 30);
        config()->set('internal_exams.remote_public_base_url', 'https://exam.example.invalid');
        config()->set('internal_exams.token_verifier_key_v1', 'unit-test-exam-token-key-material-000000000000');
        config()->set('internal_exams.station_verifier_key_v1', 'unit-test-exam-station-key-material-0000000000');
        config()->set('internal_exams.document_storage_disk', 's3');

        config()->set('incident_response.contact_refs', [
            'primary_on_call' => 'contact-ref:on-call',
            'technical_lead' => 'contact-ref:technical',
            'privacy_lead' => 'contact-ref:privacy',
            'business_liaison' => 'contact-ref:business',
            'communications_lead' => 'contact-ref:communications',
            'paging_channel' => 'contact-ref:paging',
        ]);

        config()->set('retention.executor.enabled', false);
    }
}
