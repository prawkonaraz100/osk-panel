<?php

namespace Tests\Feature;

use App\Modules\OrganizationSettings\OrganizationSettingsService;
use App\Support\Operations\CoreReconciliationScanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class CoreReconciliationScannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_clean_scan_is_read_only_and_keeps_pkk_frozen(): void
    {
        $before = [
            'payments' => DB::table('payments')->count(),
            'license_inventory_entries' => DB::table('license_inventory_entries')->count(),
            'internal_exam_inventory_entries' => DB::table('internal_exam_inventory_entries')->count(),
            'outbox_messages' => DB::table('outbox_messages')->count(),
        ];

        $report = app(CoreReconciliationScanner::class)->scan(
            CarbonImmutable::parse('2026-09-13T00:00:00+02:00'),
        );

        $this->assertSame('PASS', $report['status']);
        $this->assertSame(0, $report['findings_total']);
        $this->assertSame(0, $report['mutations_performed']);
        $this->assertFalse($report['remote_provider_truth_lookup_performed']);
        $this->assertFalse($report['pkk_in_scope']);

        $after = [
            'payments' => DB::table('payments')->count(),
            'license_inventory_entries' => DB::table('license_inventory_entries')->count(),
            'internal_exam_inventory_entries' => DB::table('internal_exam_inventory_entries')->count(),
            'outbox_messages' => DB::table('outbox_messages')->count(),
        ];
        $this->assertSame($before, $after);
    }

    public function test_pending_outbox_is_due_and_explicit_reconciliation_state_fails_scan(): void
    {
        $actor = FoundationSchema::actor();
        app(OrganizationSettingsService::class)->update(
            $actor['session_id'],
            1,
            ['phone' => '+48123456789'],
            (string) Str::uuid7(),
        );

        $outbox = DB::table('outbox_messages')->firstOrFail();
        $this->assertSame('pending', $outbox->publication_state);
        $this->assertNotNull($outbox->next_attempt_at);

        $clean = app(CoreReconciliationScanner::class)->scan();
        $this->assertSame('PASS', $clean['status']);

        DB::table('outbox_messages')->where('id', $outbox->id)->update([
            'publication_state' => 'requires_reconciliation',
            'next_attempt_at' => null,
            'lease_token' => null,
            'leased_by' => null,
            'lease_expires_at' => null,
            'last_error_code' => 'synthetic_reconciliation',
            'last_error' => 'Synthetic reconciliation fixture.',
        ]);

        $report = app(CoreReconciliationScanner::class)->scan();
        $this->assertSame('FINDINGS', $report['status']);
        $this->assertSame(1, $report['findings_by_scope']['outbox_publication']);
        $this->assertContains(
            'outbox_requires_reconciliation',
            array_column($report['findings'], 'code'),
        );

        $exit = Artisan::call('operations:reconciliation:scan', [
            '--json' => true,
            '--fail-on-findings' => true,
        ]);
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('outbox_requires_reconciliation', Artisan::output());
    }

    public function test_registered_schedule_and_policy_are_fail_closed(): void
    {
        $this->assertSame('2026-09-13-v1', config('reconciliation.policy_version'));
        $this->assertFalse((bool) config('reconciliation.automatic_repair_allowed'));
        $this->assertFalse((bool) config('reconciliation.PKK.in_scope'));
        $this->assertSame('frozen_until_explicit_unfreeze', config('reconciliation.PKK.status'));
        $this->assertSame(15, config('reconciliation.schedule.cadence_minutes'));

        Artisan::call('schedule:list');
        $schedule = Artisan::output();
        $this->assertStringContainsString('operations:reconciliation:scan', $schedule);
        $this->assertStringContainsString('--fail-on-findings', $schedule);
    }
}
