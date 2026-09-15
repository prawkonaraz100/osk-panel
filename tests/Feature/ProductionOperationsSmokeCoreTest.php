<?php

namespace Tests\Feature;

use App\Support\Operations\ProductionOperationsSmoke;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Command\Command;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class ProductionOperationsSmokeCoreTest extends TestCase
{
    public function test_validate_only_passes_with_complete_refs_and_clean_read_only_reconciliation(): void
    {
        FoundationSchema::ensureMigrated();
        $this->configureContacts();
        Http::fake();

        $exit = Artisan::call('operations:production:smoke', ['--json' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('PASS_VALIDATE_ONLY', $payload['result']);
        $this->assertTrue($payload['contact_refs_configured']);
        $this->assertSame('PASS', $payload['reconciliation_status']);
        $this->assertSame(0, $payload['reconciliation_findings_total']);
        $this->assertSame(0, $payload['reconciliation_mutations_performed']);
        $this->assertFalse($payload['alert_attempted']);
        $this->assertSame('not_requested', $payload['alert_delivery_status']);
        $this->assertFalse($payload['human_ack_proven']);
        $this->assertFalse($payload['scheduler_runtime_proven']);
        $this->assertFalse($payload['pkk_in_scope']);
        Http::assertNothingSent();
    }

    public function test_send_alert_reuses_accepted_operational_alert_transport_without_claiming_human_ack(): void
    {
        FoundationSchema::ensureMigrated();
        $this->configureContacts();
        $this->configureAlerting();

        Http::fake([
            'https://alerts.example.test/*' => Http::response(['accepted' => true], 202),
        ]);

        $exit = Artisan::call('operations:production:smoke', [
            '--send-alert' => true,
            '--confirm' => ProductionOperationsSmoke::CONFIRMATION,
            '--json' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exit);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('PASS_ALERT_DELIVERED_AWAITING_HUMAN_ACK', $payload['result']);
        $this->assertTrue($payload['alert_attempted']);
        $this->assertSame('delivered', $payload['alert_delivery_status']);
        $this->assertNotEmpty($payload['alert_event_id']);
        $this->assertFalse($payload['human_ack_proven']);
        $this->assertFalse($payload['scheduler_runtime_proven']);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return $request->url() === 'https://alerts.example.test/ingest'
                && ($payload['event_code'] ?? null) === 'synthetic_smoke'
                && ($payload['context'] ?? null) === [];
        });
    }

    public function test_missing_contact_refs_fail_closed_before_reconciliation_or_delivery(): void
    {
        FoundationSchema::ensureMigrated();
        config()->set('incident_response.contact_refs', []);
        Http::fake();

        $exit = Artisan::call('operations:production:smoke', ['--json' => true]);

        $this->assertSame(Command::FAILURE, $exit);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('REFUSED_MISSING_CONTACT_REFS', $payload['result']);
        $this->assertFalse($payload['contact_refs_configured']);
        $this->assertSame('NOT_RUN', $payload['reconciliation_status']);
        Http::assertNothingSent();
    }

    public function test_send_alert_requires_exact_confirmation_before_network_delivery(): void
    {
        FoundationSchema::ensureMigrated();
        $this->configureContacts();
        $this->configureAlerting();
        Http::fake();

        $exit = Artisan::call('operations:production:smoke', [
            '--send-alert' => true,
            '--confirm' => 'wrong',
            '--json' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exit);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('REFUSED', $payload['result']);
        $this->assertFalse($payload['human_ack_proven']);
        Http::assertNothingSent();
    }

    public function test_failed_alert_delivery_cannot_be_reported_as_smoke_pass(): void
    {
        FoundationSchema::ensureMigrated();
        $this->configureContacts();
        $this->configureAlerting();

        Http::fake([
            'https://alerts.example.test/*' => Http::response(['accepted' => false], 503),
        ]);

        $exit = Artisan::call('operations:production:smoke', [
            '--send-alert' => true,
            '--confirm' => ProductionOperationsSmoke::CONFIRMATION,
            '--json' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exit);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('FAIL_ALERT_DELIVERY', $payload['result']);
        $this->assertSame('failed', $payload['alert_delivery_status']);
        $this->assertFalse($payload['human_ack_proven']);
    }

    private function configureContacts(): void
    {
        config()->set('incident_response.contact_refs', [
            'primary_on_call' => 'ref-primary-on-call',
            'technical_lead' => 'ref-technical-lead',
            'privacy_lead' => 'ref-privacy-lead',
            'business_liaison' => 'ref-business-liaison',
            'communications_lead' => 'ref-communications-lead',
            'paging_channel' => 'ref-paging-channel',
        ]);
    }

    private function configureAlerting(): void
    {
        config()->set('operational_alerting.enabled', true);
        config()->set('operational_alerting.webhook_url', 'https://alerts.example.test/ingest');
        config()->set('operational_alerting.webhook_secret', 'test-only-alert-secret');
        config()->set('operational_alerting.timeout_seconds', 5);
    }
}
