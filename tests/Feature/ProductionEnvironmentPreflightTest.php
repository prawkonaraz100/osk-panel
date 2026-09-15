<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class ProductionEnvironmentPreflightTest extends TestCase
{
    public function test_valid_production_configuration_passes_but_never_claims_go_live_evidence(): void
    {
        $this->configureValidProduction();

        $exit = Artisan::call('operations:production:preflight', ['--json' => true]);

        $this->assertSame(Command::SUCCESS, $exit);

        $report = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('PASS', $report['configuration_status']);
        $this->assertFalse($report['production_ready']);
        $this->assertSame('BLOCKED_EXTERNAL_EVIDENCE', $report['go_live_status']);
        $this->assertContains('target_infrastructure_restore_drill', $report['external_evidence_required']);
        $this->assertSame('FROZEN_UNTIL_EXPLICIT_UNFREEZE', $report['deferred_boundaries']['PKK_PWPW']);
        $this->assertSame('EXTERNAL_PROVIDER_BOUNDARY', $report['deferred_boundaries']['payment_provider_specific_webhook']);

        $output = Artisan::output();
        $this->assertStringNotContainsString('super-secret-sensitive-lookup-material', $output);
        $this->assertStringNotContainsString('base64:production-app-key-material', $output);
    }

    public function test_debug_http_placeholder_mail_and_missing_incident_reference_fail_closed(): void
    {
        $this->configureValidProduction();

        config()->set('app.debug', true);
        config()->set('app.url', 'http://example.test');
        config()->set('mail.default', 'log');
        config()->set('password_recovery.mailer', null);
        config()->set('incident_response.contact_refs.privacy_lead', null);

        $exit = Artisan::call('operations:production:preflight', ['--json' => true]);

        $this->assertSame(Command::FAILURE, $exit);

        $report = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('FAIL', $report['configuration_status']);

        $failed = array_column(array_values(array_filter(
            $report['checks'],
            static fn (array $check): bool => $check['status'] === 'FAIL',
        )), 'id');

        $this->assertContains('app_debug_disabled', $failed);
        $this->assertContains('app_url_https', $failed);
        $this->assertContains('production_mail_transport', $failed);
        $this->assertContains('incident_contact_references', $failed);
    }

    public function test_insecure_object_endpoint_and_enabled_retention_executor_fail_baseline_preflight(): void
    {
        $this->configureValidProduction();

        config()->set('filesystems.disks.s3.endpoint', 'http://127.0.0.1:5000');
        config()->set('retention.executor.enabled', true);

        $exit = Artisan::call('operations:production:preflight', ['--json' => true]);

        $this->assertSame(Command::FAILURE, $exit);

        $report = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $failed = array_column(array_values(array_filter(
            $report['checks'],
            static fn (array $check): bool => $check['status'] === 'FAIL',
        )), 'id');

        $this->assertContains('s3_target_configured', $failed);
        $this->assertContains('retention_executor_disabled_at_baseline', $failed);
    }

    private function configureValidProduction(): void
    {
        config()->set([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://osk.example.test',
            'app.key' => 'base64:production-app-key-material',
            'logging.default' => 'json_stderr',
            'database.default' => 'pgsql',
            'database.connections.pgsql.url' => null,
            'database.connections.pgsql.database' => 'osk_panel',
            'database.connections.pgsql.username' => 'osk_panel_app',
            'cache.default' => 'redis',
            'session.driver' => 'redis',
            'session.secure' => true,
            'queue.default' => 'redis',
            'filesystems.default' => 's3',
            'uploads.disk' => 's3',
            'filesystems.disks.s3.bucket' => 'osk-panel-production',
            'filesystems.disks.s3.region' => 'eu-central-1',
            'filesystems.disks.s3.endpoint' => null,
            'mail.default' => 'smtp',
            'mail.from.address' => 'noreply@osk.example.test',
            'password_recovery.mailer' => null,
            'password_recovery.reset_url_template' => 'https://osk.example.test/reset-password?token={token}',
            'security.sensitive_identifiers.lookup_key' => 'super-secret-sensitive-lookup-material',
            'incident_response.contact_refs' => [
                'primary_on_call' => 'pager://primary',
                'technical_lead' => 'directory://technical-lead',
                'privacy_lead' => 'directory://privacy-lead',
                'business_liaison' => 'directory://business-liaison',
                'communications_lead' => 'directory://communications-lead',
                'paging_channel' => 'pager://critical',
            ],
            'retention.executor.enabled' => false,
        ]);
    }
}
