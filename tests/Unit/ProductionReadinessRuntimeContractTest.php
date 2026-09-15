<?php

namespace Tests\Unit;

use Monolog\Formatter\JsonFormatter;
use PHPUnit\Framework\TestCase;

final class ProductionReadinessRuntimeContractTest extends TestCase
{
    public function test_repository_materializes_health_logging_release_and_smoke_contract_without_external_claims(): void
    {
        $root = dirname(__DIR__, 2);
        $spec = (string) file_get_contents($root.'/specs/operations/production-readiness.yml');
        $routes = (string) file_get_contents($root.'/routes/web.php');
        $logging = (string) file_get_contents($root.'/config/logging.php');
        $workflow = (string) file_get_contents($root.'/.github/workflows/implementation-ci.yml');
        $artifact = (string) file_get_contents($root.'/scripts/ops/build-release-artifact.sh');
        $smoke = (string) file_get_contents($root.'/scripts/ops/production-smoke.sh');

        self::assertStringContainsString('status: CANDIDATE', $spec);
        self::assertStringContainsString('claims_target_infrastructure_ready: false', $spec);
        self::assertStringContainsString('FROZEN_UNTIL_EXPLICIT_UNFREEZE', $spec);

        self::assertStringContainsString("Route::get('/health/live'", $routes);
        self::assertStringContainsString("Route::get('/health/ready'", $routes);

        self::assertStringContainsString("'json_stderr' => [", $logging);
        self::assertStringContainsString(JsonFormatter::class, $logging);

        self::assertStringContainsString('release-artifact:', $workflow);
        self::assertStringContainsString('actions/upload-artifact@v4', $workflow);
        self::assertStringContainsString('github.sha', $workflow);

        self::assertStringContainsString('sha256sum', $artifact);
        self::assertStringContainsString('contains_env_file', $artifact);
        self::assertStringContainsString('PRODUCTION_BASE_URL', $smoke);
        self::assertStringContainsString('/health/live', $smoke);
        self::assertStringContainsString('/health/ready', $smoke);
        self::assertStringContainsString('https://', $smoke);

        self::assertStringNotContainsString('PWPW', $smoke);
        self::assertStringNotContainsString('PKK', $smoke);
    }
}
