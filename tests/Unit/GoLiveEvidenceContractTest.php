<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class GoLiveEvidenceContractTest extends TestCase
{
    public function test_gate_is_cli_only_sanitized_and_preserves_deferred_provider_boundaries(): void
    {
        $root = dirname(__DIR__, 2);
        $spec = (string) file_get_contents($root.'/specs/operations/go-live-evidence.yml');
        $command = (string) file_get_contents($root.'/app/Console/Commands/GoLiveEvidenceCommand.php');
        $web = (string) file_get_contents($root.'/routes/web.php');
        $console = (string) file_get_contents($root.'/routes/console.php');

        self::assertStringContainsString('status: PASS', $spec);
        self::assertStringContainsString('production_ready_claim_from_repository_alone: forbidden', $spec);
        self::assertStringContainsString('PKK_PWPW: FROZEN_UNTIL_EXPLICIT_UNFREEZE', $spec);
        self::assertStringContainsString('operations:go-live:evidence', $command);
        self::assertStringNotContainsString('operations:go-live:evidence', $web);
        self::assertStringNotContainsString("Schedule::command('operations:go-live:evidence", $console);
        self::assertStringContainsString('production_activation_performed', $command);
        self::assertStringContainsString('production_ready_claim', $command);
    }
}
