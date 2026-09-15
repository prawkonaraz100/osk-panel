<?php

namespace Tests\Unit;

use App\Console\Commands\ReconciliationAlertSmokeCommand;
use PHPUnit\Framework\TestCase;

final class ReconciliationEvidenceSmokeContractTest extends TestCase
{
    public function test_safe_reconciliation_evidence_smoke_is_explicit_and_non_mutating(): void
    {
        $root = dirname(__DIR__, 2);
        $command = (string) file_get_contents($root.'/app/Console/Commands/ReconciliationAlertSmokeCommand.php');
        $alerting = (string) file_get_contents($root.'/specs/operations/operational-alerting.yml');
        $evidence = (string) file_get_contents($root.'/specs/operations/go-live-evidence.yml');
        $authority = (string) file_get_contents($root.'/specs/operations/reconciliation-evidence-smoke.yml');

        self::assertStringContainsString('status: PASS', $authority);
        self::assertStringContainsString('mutates_business_state: false', $authority);
        self::assertStringContainsString('creates_real_reconciliation_finding: false', $authority);
        self::assertStringContainsString('exact_event_code: reconciliation_findings', $authority);
        self::assertStringContainsString('synthetic_smoke', $command);
        self::assertStringContainsString(ReconciliationAlertSmokeCommand::CONFIRMATION, $command);
        self::assertStringNotContainsString('DB::', $command);
        self::assertStringNotContainsString('Artisan::call', $command);
        self::assertStringContainsString('synthetic_smoke_explicitly_marked: true', $alerting);
        self::assertStringContainsString('policy_version: 2026-09-15-v2', $evidence);
        self::assertStringContainsString('reconciliation_alert_smoke_reached_operator_required: true', $evidence);
        self::assertStringContainsString('production_business_state_mutation_for_smoke: forbidden', $evidence);
        self::assertStringContainsString('PKK_PWPW: FROZEN_UNTIL_EXPLICIT_UNFREEZE', $authority);
    }
}
