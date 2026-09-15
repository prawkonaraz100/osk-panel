<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionTargetPreflightContractTest extends TestCase
{
    public function test_preflight_is_cli_only_fail_closed_and_preserves_external_boundaries(): void
    {
        $root = dirname(__DIR__, 2);
        $service = (string) file_get_contents($root.'/app/Support/Operations/ProductionEnvironmentPreflight.php');
        $command = (string) file_get_contents($root.'/app/Console/Commands/ProductionPreflightCommand.php');
        $bootstrap = (string) file_get_contents($root.'/bootstrap/app.php');
        $web = (string) file_get_contents($root.'/routes/web.php');
        $spec = (string) file_get_contents($root.'/specs/operations/production-target-preflight.yml');

        self::assertStringContainsString('operations:production:preflight', $command);
        self::assertStringContainsString('ProductionPreflightCommand::class', $bootstrap);
        self::assertStringNotContainsString('operations:production:preflight', $web);

        self::assertStringContainsString('status: CANDIDATE', $spec);
        self::assertStringContainsString('gated_by_static_pass: true', $spec);
        self::assertStringContainsString('business_database: forbidden', $spec);
        self::assertStringContainsString('secret_values_in_report: forbidden', $spec);
        self::assertStringContainsString('PKK_provider_required: false', $spec);
        self::assertStringContainsString('payment_provider_webhook_required: false', $spec);

        self::assertStringContainsString('DB::selectOne(\'select 1 as ready\')', $service);
        self::assertStringContainsString('production-preflight/', $service);
        self::assertStringNotContainsString('PWPW', $service);
    }
}
