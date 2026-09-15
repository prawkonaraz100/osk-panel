<?php

namespace Tests\Feature;

use App\Console\Commands\OperationalAlertSmokeCommand;
use App\Console\Commands\ReconciliationAlertSmokeCommand;
use App\Support\Operations\OperationalAlertDispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class OperationalAlertingCoreTest extends TestCase
{
    public function test_disabled_dispatcher_never_sends_network_request(): void
    {
        Http::fake();
        config()->set('operational_alerting.enabled', false);

        $result = app(OperationalAlertDispatcher::class)->dispatch('synthetic_smoke');

        $this->assertSame('disabled', $result['status']);
        Http::assertNothingSent();
    }

    public function test_enabled_dispatcher_sends_signed_safe_https_payload(): void
    {
        Http::fake([
            'https://alerts.example.test/*' => Http::response(['accepted' => true], 202),
        ]);
        config()->set('operational_alerting.enabled', true);
        config()->set('operational_alerting.webhook_url', 'https://alerts.example.test/ingest');
        config()->set('operational_alerting.webhook_secret', 'test-only-alert-secret');
        config()->set('operational_alerting.timeout_seconds', 5);

        $result = app(OperationalAlertDispatcher::class)->dispatch('reconciliation_findings', [
            'policy_version' => '2026-09-13-v1',
            'findings_total' => 3,
            'findings_by_scope' => [
                'purchase_fulfillment' => 2,
                'outbox_publication' => 1,
            ],
            'organization_id' => 'must-not-leave-process',
            'entity_id' => 'must-not-leave-process',
        ]);

        $this->assertSame('delivered', $result['status']);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            $signature = $request->header('X-OSK-Alert-Signature')[0] ?? null;

            return $request->url() === 'https://alerts.example.test/ingest'
                && $request->method() === 'POST'
                && is_string($signature)
                && str_starts_with($signature, 'sha256=')
                && ($payload['event_code'] ?? null) === 'reconciliation_findings'
                && ($payload['severity'] ?? null) === 'SEV2'
                && ($payload['runbook'] ?? null) === 'payment_or_reconciliation'
                && ($payload['context']['findings_total'] ?? null) === 3
                && ! array_key_exists('organization_id', $payload['context'])
                && ! array_key_exists('entity_id', $payload['context']);
        });
    }

    public function test_non_https_sink_is_refused_before_network_delivery(): void
    {
        Http::fake();
        config()->set('operational_alerting.enabled', true);
        config()->set('operational_alerting.webhook_url', 'http://alerts.example.test/ingest');
        config()->set('operational_alerting.webhook_secret', 'test-only-alert-secret');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Operational alert endpoint must be an HTTPS URL.');

        try {
            app(OperationalAlertDispatcher::class)->dispatch('synthetic_smoke');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_smoke_command_requires_confirmation_and_real_delivery_success(): void
    {
        Http::fake([
            'https://alerts.example.test/*' => Http::response(null, 204),
        ]);
        config()->set('operational_alerting.enabled', true);
        config()->set('operational_alerting.webhook_url', 'https://alerts.example.test/ingest');
        config()->set('operational_alerting.webhook_secret', 'test-only-alert-secret');

        $refused = Artisan::call('operations:alert:smoke', [
            '--confirm' => 'wrong',
            '--json' => true,
        ]);
        $this->assertSame(Command::FAILURE, $refused);

        $sent = Artisan::call('operations:alert:smoke', [
            '--confirm' => OperationalAlertSmokeCommand::CONFIRMATION,
            '--json' => true,
        ]);
        $this->assertSame(Command::SUCCESS, $sent);

        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('delivered', $payload['status']);
        $this->assertSame('synthetic_smoke', $payload['event_code']);
    }

    public function test_reconciliation_alert_smoke_uses_exact_findings_event_without_business_mutation_claim(): void
    {
        Http::fake([
            'https://alerts.example.test/*' => Http::response(['accepted' => true], 202),
        ]);
        config()->set('operational_alerting.enabled', true);
        config()->set('operational_alerting.webhook_url', 'https://alerts.example.test/ingest');
        config()->set('operational_alerting.webhook_secret', 'test-only-alert-secret');
        config()->set('operational_alerting.timeout_seconds', 5);
        config()->set('reconciliation.policy_version', '2026-09-13-v1');

        $refused = Artisan::call('operations:reconciliation:alert:smoke', [
            '--confirm' => 'wrong',
            '--json' => true,
        ]);
        $this->assertSame(Command::FAILURE, $refused);
        Http::assertNothingSent();

        $sent = Artisan::call('operations:reconciliation:alert:smoke', [
            '--confirm' => ReconciliationAlertSmokeCommand::CONFIRMATION,
            '--json' => true,
        ]);
        $this->assertSame(Command::SUCCESS, $sent);

        $result = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('delivered', $result['status']);
        $this->assertSame('reconciliation_findings', $result['event_code']);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return ($payload['event_code'] ?? null) === 'reconciliation_findings'
                && ($payload['severity'] ?? null) === 'SEV2'
                && ($payload['runbook'] ?? null) === 'payment_or_reconciliation'
                && ($payload['context']['policy_version'] ?? null) === '2026-09-13-v1'
                && ($payload['context']['findings_total'] ?? null) === 1
                && ($payload['context']['findings_by_scope']['synthetic_smoke'] ?? null) === 1
                && ($payload['context']['synthetic_smoke'] ?? null) === true
                && ! array_key_exists('organization_id', $payload['context'])
                && ! array_key_exists('entity_id', $payload['context']);
        });
    }
}
