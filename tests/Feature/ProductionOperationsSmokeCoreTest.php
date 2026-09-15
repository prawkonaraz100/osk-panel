<?php

namespace Tests\Feature;

use App\Mail\ProductionPagingSmokeMail;
use App\Support\Operations\ProductionOperationsSmoke;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Command\Command;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class ProductionOperationsSmokeCoreTest extends TestCase
{
    public function test_validate_only_passes_with_complete_refs_and_clean_read_only_reconciliation(): void
    {
        FoundationSchema::ensureMigrated();
        $this->configureContacts();

        $exit = Artisan::call('operations:production:smoke', ['--json' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('PASS_VALIDATE_ONLY', $payload['result']);
        $this->assertTrue($payload['contact_refs_configured']);
        $this->assertSame('PASS', $payload['reconciliation_status']);
        $this->assertSame(0, $payload['reconciliation_findings_total']);
        $this->assertSame(0, $payload['reconciliation_mutations_performed']);
        $this->assertFalse($payload['alert_attempted']);
        $this->assertFalse($payload['human_ack_proven']);
        $this->assertFalse($payload['scheduler_runtime_proven']);
        $this->assertFalse($payload['pkk_in_scope']);
    }

    public function test_send_alert_requires_real_transport_configuration_and_never_claims_human_ack(): void
    {
        FoundationSchema::ensureMigrated();
        $this->configureContacts();
        config()->set('incident_response.paging_smoke.enabled', true);
        config()->set('incident_response.paging_smoke.recipient', 'ops@example.test');
        config()->set('incident_response.paging_smoke.mailer', 'smtp');
        Mail::fake();

        $exit = Artisan::call('operations:production:smoke', [
            '--send-alert' => true,
            '--confirm' => ProductionOperationsSmoke::CONFIRMATION,
            '--json' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exit);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('PASS_ALERT_SENT_AWAITING_HUMAN_ACK', $payload['result']);
        $this->assertTrue($payload['alert_attempted']);
        $this->assertTrue($payload['alert_sent']);
        $this->assertNotEmpty($payload['smoke_id']);
        $this->assertFalse($payload['human_ack_proven']);
        $this->assertFalse($payload['scheduler_runtime_proven']);

        Mail::assertSent(ProductionPagingSmokeMail::class, function (ProductionPagingSmokeMail $mail): bool {
            return $mail->hasTo('ops@example.test');
        });
    }

    public function test_missing_contact_refs_fail_closed_before_reconciliation_or_delivery(): void
    {
        FoundationSchema::ensureMigrated();
        config()->set('incident_response.contact_refs', []);

        $exit = Artisan::call('operations:production:smoke', ['--json' => true]);

        $this->assertSame(Command::FAILURE, $exit);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('REFUSED_MISSING_CONTACT_REFS', $payload['result']);
        $this->assertFalse($payload['contact_refs_configured']);
        $this->assertSame('NOT_RUN', $payload['reconciliation_status']);
    }

    public function test_send_alert_refuses_non_delivery_mail_transport(): void
    {
        FoundationSchema::ensureMigrated();
        $this->configureContacts();
        config()->set('incident_response.paging_smoke.enabled', true);
        config()->set('incident_response.paging_smoke.recipient', 'ops@example.test');
        config()->set('incident_response.paging_smoke.mailer', 'log');

        $exit = Artisan::call('operations:production:smoke', [
            '--send-alert' => true,
            '--confirm' => ProductionOperationsSmoke::CONFIRMATION,
            '--json' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exit);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('REFUSED', $payload['result']);
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
}
