<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RestoreDrillHarnessContractTest extends TestCase
{
    public function test_implementation_ci_runs_restore_drill_after_application_tests(): void
    {
        $root = dirname(__DIR__, 2);
        $workflow = file_get_contents($root.'/.github/workflows/implementation-ci.yml');
        $script = file_get_contents($root.'/scripts/ops/restore-drill.sh');
        $objectScript = file_get_contents($root.'/scripts/ops/restore-drill-object.php');

        $this->assertIsString($workflow);
        $this->assertIsString($script);
        $this->assertIsString($objectScript);

        $testPosition = strpos($workflow, 'Run PostgreSQL-backed test suite');
        $drillPosition = strpos($workflow, 'Run deterministic restore drill harness');
        $cleanupPosition = strpos($workflow, 'Cleanup runtime');

        $this->assertIsInt($testPosition);
        $this->assertIsInt($drillPosition);
        $this->assertIsInt($cleanupPosition);
        $this->assertLessThan($drillPosition, $testPosition);
        $this->assertLessThan($cleanupPosition, $drillPosition);

        $this->assertStringContainsString('pg_dump', $script);
        $this->assertStringContainsString('pg_restore', $script);
        $this->assertStringContainsString('RESTORE_DRILL_SOURCE_DB', $script);
        $this->assertStringContainsString('RESTORE_DRILL_TARGET_DB', $script);
        $this->assertStringContainsString('"production_target_evidence":false', $script);
        $this->assertStringContainsString('redis-cli FLUSHALL', $script);

        $this->assertStringContainsString('putBucketVersioning', $objectScript);
        $this->assertStringContainsString("'VersionId' => $versionOneId", $objectScript);
        $this->assertStringContainsString("'production_target_evidence' => false", $objectScript);
    }
}
