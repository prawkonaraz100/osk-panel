<?php

namespace Tests\Feature;

use App\Support\Operations\ProductionEnvironmentPreflight;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ProductionTargetPreflightTest extends TestCase
{
    public function test_production_safe_configuration_passes_without_provider_specific_requirements(): void
    {
        $this->configureProductionSafeStaticEnvironment();

        $report = app(ProductionEnvironmentPreflight::class)->run(false);

        $this->assertSame('pass', $report['status']);
        $this->assertSame(0, $report['failures']);
        $this->assertFalse($report['live_requested']);
        $this->assertFalse($report['PKK_required']);
        $this->assertFalse($report['payment_provider_webhook_required']);

        foreach ($report['static_checks'] as $check) {
            $this->assertSame('pass', $check['status']);
            $this->assertNull($check['failure_code']);
        }
    }

    public function test_development_defaults_fail_closed_and_live_checks_are_skipped(): void
    {
        $report = app(ProductionEnvironmentPreflight::class)->run(true);

        $this->assertSame('fail', $report['status']);
        $this->assertTrue($report['live_requested']);
        $this->assertTrue($report['live_skipped']);
        $this->assertSame([], $report['live_checks']);
        $this->assertSame('fail', $report['static_checks']['app_environment_production']['status']);
        $this->assertSame('fail', $report['static_checks']['app_debug_disabled']['status']);
        $this->assertSame('fail', $report['static_checks']['app_url_https_nonlocal']['status']);
        $this->assertGreaterThan(0, $report['failures']);
    }

    public function test_live_dependency_checks_round_trip_the_ci_runtime_without_business_mutation(): void
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'http://localhost:5000',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
        ]);

        try {
            $client->headBucket(['Bucket' => 'osk-panel-test']);
        } catch (AwsException) {
            $client->createBucket(['Bucket' => 'osk-panel-test']);
        }

        $checks = app(ProductionEnvironmentPreflight::class)->liveChecks();

        $this->assertSame([
            'postgresql_connectivity',
            'redis_connectivity',
            'redis_cache_round_trip',
            'redis_queue_visibility',
            'object_storage_round_trip',
        ], array_keys($checks));

        foreach ($checks as $check) {
            $this->assertSame('pass', $check['status']);
            $this->assertNull($check['failure_code']);
        }
    }

    public function test_cli_json_report_does_not_emit_secret_values(): void
    {
        $this->configureProductionSafeStaticEnvironment();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('A', 32)));
        config()->set('security.sensitive_identifiers.lookup_key', str_repeat('B', 40));
        config()->set('database.connections.pgsql.password', 'super-secret-database-password');
        config()->set('database.redis.default.password', 'super-secret-redis-password');
        config()->set('filesystems.disks.s3.key', 'super-secret-s3-key');
        config()->set('filesystems.disks.s3.secret', 'super-secret-s3-secret');

        $exit = Artisan::call('operations:production:preflight', ['--json' => true]);
        $output = trim(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('super-secret', $output);

        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $payload['status']);
        $this->assertFalse($payload['PKK_required']);
    }

    private function configureProductionSafeStaticEnvironment(): void
    {
        config()->set([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://osk.example.pl',
            'app.key' => 'base64:'.base64_encode(str_repeat('A', 32)),
            'security.sensitive_identifiers.lookup_key' => str_repeat('B', 40),

            'database.default' => 'pgsql',
            'database.connections.pgsql.url' => null,
            'database.connections.pgsql.username' => 'osk_prod',
            'database.connections.pgsql.password' => 'non-development-database-password',
            'database.redis.default.url' => null,
            'database.redis.default.password' => 'non-development-redis-password',

            'cache.default' => 'redis',
            'session.driver' => 'redis',
            'session.secure' => true,
            'session.http_only' => true,
            'session.serialization' => 'json',
            'queue.default' => 'redis',
            'queue.failed.driver' => 'database-uuids',

            'filesystems.default' => 's3',
            'uploads.disk' => 's3',
            'filesystems.disks.s3.bucket' => 'osk-panel-production',
            'filesystems.disks.s3.key' => null,
            'filesystems.disks.s3.secret' => null,
            'filesystems.disks.s3.endpoint' => 'https://storage.example.pl',

            'logging.default' => 'json_stderr',
            'logging.channels.json_stderr.level' => 'info',

            'password_recovery.reset_url_template' => 'https://osk.example.pl/reset-password?token={token}',
            'password_recovery.mailer' => 'smtp',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.from.address' => 'noreply@osk.example.pl',

            'incident_response.contact_refs' => [
                'primary_on_call' => 'secretref://on-call',
                'technical_lead' => 'secretref://technical-lead',
                'privacy_lead' => 'secretref://privacy-lead',
                'business_liaison' => 'secretref://business-liaison',
                'communications_lead' => 'secretref://communications-lead',
                'paging_channel' => 'secretref://paging',
            ],

            'retention.executor.enabled' => false,
        ]);
    }
}
