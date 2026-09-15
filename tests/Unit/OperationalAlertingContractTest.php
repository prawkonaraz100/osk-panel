<?php

namespace Tests\Unit;

use App\Console\Commands\OperationalAlertSmokeCommand;
use PHPUnit\Framework\TestCase;

final class OperationalAlertingContractTest extends TestCase
{
    public function test_repository_materializes_provider_neutral_alerting_without_claiming_target_delivery(): void
    {
        $root = dirname(__DIR__, 2);
        $config = (string) file_get_contents($root.'/config/operational_alerting.php');
        $bootstrap = (string) file_get_contents($root.'/bootstrap/app.php');
        $console = (string) file_get_contents($root.'/routes/console.php');
        $spec = (string) file_get_contents($root.'/specs/operations/operational-alerting.yml');

        self::assertStringContainsString("env('OPS_ALERTING_ENABLED', false)", $config);
        self::assertStringContainsString("env('OPS_ALERT_WEBHOOK_URL')", $config);
        self::assertStringContainsString("env('OPS_ALERT_WEBHOOK_SECRET')", $config);
        self::assertStringContainsString('OperationalAlertSmokeCommand::class', $bootstrap);
        self::assertStringContainsString('OperationalAlertDispatcher $alerts', $console);
        self::assertStringContainsString("'reconciliation_findings'", $console);
        self::assertStringContainsString(OperationalAlertSmokeCommand::CONFIRMATION, file_get_contents($root.'/app/Console/Commands/OperationalAlertSmokeCommand.php'));

        self::assertStringContainsString('status: CANDIDATE', $spec);
        self::assertStringContainsString('claims_human_received_page: false', $spec);
        self::assertStringContainsString('entity_identifiers_forbidden: true', $spec);
        self::assertStringContainsString('PKK_PWPW: FROZEN_UNTIL_EXPLICIT_UNFREEZE', $spec);
    }
}
