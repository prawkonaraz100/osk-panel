<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionEnvironmentPreflightContractTest extends TestCase
{
    public function test_preflight_contract_keeps_external_evidence_and_deferred_provider_boundaries_explicit(): void
    {
        $root = dirname(__DIR__, 2);
        $spec = (string) file_get_contents($root.'/specs/operations/production-environment-preflight.yml');
        $bootstrap = (string) file_get_contents($root.'/bootstrap/app.php');
        $command = (string) file_get_contents($root.'/app/Console/Commands/ProductionPreflightCommand.php');

        self::assertStringContainsString('status: PASS', $spec);
        self::assertStringContainsString('production_ready_claim: forbidden', $spec);
        self::assertStringContainsString('PKK_PWPW: FROZEN_UNTIL_EXPLICIT_UNFREEZE', $spec);
        self::assertStringContainsString('payment_provider_specific_webhook: EXTERNAL_PROVIDER_BOUNDARY', $spec);
        self::assertStringContainsString('target_infrastructure_restore_drill', $spec);
        self::assertStringContainsString('ProductionPreflightCommand::class', $bootstrap);
        self::assertStringContainsString('operational_alerting_enabled', $spec);
        self::assertStringContainsString('operational_alert_endpoint_https', $spec);
        self::assertStringContainsString('operational_alert_secret_present', $spec);
        self::assertStringContainsString('operations:production:preflight', $command);
        self::assertStringNotContainsString('secret_value', $command);
    }
}
