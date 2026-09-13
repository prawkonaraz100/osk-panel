<?php

namespace Tests\Unit;

use Tests\TestCase;

final class ReconciliationPolicyContractTest extends TestCase
{
    public function test_provider_neutral_reconciliation_contract_preserves_frozen_pkk_boundary(): void
    {
        $policy = require base_path('config/reconciliation.php');

        $this->assertSame('2026-09-13-v1', $policy['policy_version']);
        $this->assertFalse($policy['automatic_repair_allowed']);
        $this->assertFalse($policy['remote_provider_truth_lookup_performed']);
        $this->assertFalse($policy['PKK']['in_scope']);
        $this->assertFalse($policy['PKK']['provider_specific_reconciliation_allowed']);
        $this->assertTrue($policy['repair_boundary']['repair_requires_separate_audited_path']);
        $this->assertFalse($policy['repair_boundary']['scanner_may_mutate_business_state']);
    }

    public function test_scheduler_contract_uses_the_same_fail_closed_command(): void
    {
        $console = file_get_contents(base_path('routes/console.php'));
        $this->assertIsString($console);
        $this->assertStringContainsString(
            'operations:reconciliation:scan --json --log --fail-on-findings',
            $console,
        );
        $this->assertStringContainsString('->everyFifteenMinutes()', $console);
        $this->assertStringContainsString('->withoutOverlapping(30)', $console);
    }

    public function test_new_atomic_outbox_intents_have_server_derived_due_time(): void
    {
        $source = file_get_contents(base_path('app/Modules/AuditNotification/AtomicAuditOutbox.php'));
        $this->assertIsString($source);
        $this->assertSame(2, substr_count($source, '\'next_attempt_at\' => $now'));
    }
}
