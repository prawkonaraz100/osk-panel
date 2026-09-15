<?php

namespace Tests\Unit;

use App\Support\Operations\ProductionOperationsSmoke;
use Tests\TestCase;

final class ProductionOperationsSmokeContractTest extends TestCase
{
    public function test_smoke_contract_preserves_external_evidence_boundary(): void
    {
        $root = dirname(__DIR__, 2);
        $spec = (string) file_get_contents($root.'/specs/operations/production-ops-smoke.yml');
        $command = (string) file_get_contents($root.'/app/Console/Commands/ProductionOperationsSmokeCommand.php');
        $bootstrap = (string) file_get_contents($root.'/bootstrap/app.php');
        $web = (string) file_get_contents($root.'/routes/web.php');
        $console = (string) file_get_contents($root.'/routes/console.php');

        self::assertStringContainsString('status: PASS', $spec);
        self::assertStringContainsString('human_ack_proven_by_repository: false', $spec);
        self::assertStringContainsString('scheduler_runtime_proven_by_repository: false', $spec);
        self::assertStringContainsString('PKK_provider_runtime: frozen', $spec);
        self::assertStringContainsString('operations:production:smoke', $command);
        self::assertStringContainsString(ProductionOperationsSmoke::CONFIRMATION, file_get_contents($root.'/app/Support/Operations/ProductionOperationsSmoke.php'));
        self::assertStringContainsString('ProductionOperationsSmokeCommand::class', $bootstrap);
        self::assertStringNotContainsString('operations:production:smoke', $web);
        self::assertStringNotContainsString("Schedule::command('operations:production:smoke", $console);
    }
}
