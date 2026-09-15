<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionDeploymentPreflightContractTest extends TestCase
{
    public function test_gate_is_config_only_provider_neutral_and_release_verification_is_wired(): void
    {
        $root = dirname(__DIR__, 2);
        $preflight = (string) file_get_contents($root.'/app/Support/Operations/ProductionDeploymentPreflight.php');
        $command = (string) file_get_contents($root.'/app/Console/Commands/ProductionPreflightCommand.php');
        $bootstrap = (string) file_get_contents($root.'/bootstrap/app.php');
        $build = (string) file_get_contents($root.'/scripts/ops/build-release-artifact.sh');
        $verify = (string) file_get_contents($root.'/scripts/ops/verify-release-artifact.sh');
        $workflow = (string) file_get_contents($root.'/.github/workflows/implementation-ci.yml');
        $spec = (string) file_get_contents($root.'/specs/operations/production-deployment-preflight.yml');

        self::assertStringContainsString('operations:production:preflight', $command);
        self::assertStringContainsString('ProductionPreflightCommand::class', $bootstrap);
        self::assertStringNotContainsString('Http::', $preflight);
        self::assertStringNotContainsString('DB::', $preflight);
        self::assertStringNotContainsString('Artisan::call', $preflight);

        self::assertStringContainsString('PKK_PWPW', $preflight);
        self::assertStringContainsString('frozen_not_required_for_core_launch', $preflight);
        self::assertStringContainsString('payment_provider_webhook', $preflight);

        self::assertStringContainsString("--exclude='storage/logs/*'", $build);
        self::assertStringContainsString("--exclude='storage/framework/sessions/*'", $build);
        self::assertStringContainsString('RELEASE_ARTIFACT_VERIFY=PASS', $verify);
        self::assertStringContainsString('Release manifest mismatch.', $verify);
        self::assertStringContainsString('Verify immutable release artifact', $workflow);

        self::assertStringContainsString('status: PASS', $spec);
        self::assertStringContainsString('network_calls: forbidden', $spec);
        self::assertStringContainsString('mutations: forbidden', $spec);
        self::assertStringContainsString('production_target_ready_claim: false', $spec);
    }
}
