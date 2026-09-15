<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionRepositoryClosureContractTest extends TestCase
{
    public function test_production_repository_closure_keeps_external_evidence_boundary_explicit(): void
    {
        $root = dirname(__DIR__, 2);

        $closure = (string) file_get_contents($root.'/specs/operations/production-repository-closure.yml');
        $runtime = (string) file_get_contents($root.'/specs/operations/production-readiness.yml');
        $retention = (string) file_get_contents($root.'/specs/privacy/retention-executor.yml');
        $preflight = (string) file_get_contents($root.'/specs/operations/production-environment-preflight.yml');
        $evidence = (string) file_get_contents($root.'/specs/operations/go-live-evidence.yml');
        $alerting = (string) file_get_contents($root.'/specs/operations/operational-alerting.yml');
        $smoke = (string) file_get_contents($root.'/specs/operations/production-ops-smoke.yml');

        foreach ([$runtime, $retention, $preflight, $evidence, $alerting, $smoke] as $gateSpec) {
            self::assertStringContainsString('status: PASS', $gateSpec);
        }

        self::assertStringContainsString('gate: PROD-CLOSURE-AUDIT-001', $closure);
        self::assertStringContainsString('status: PASS', $closure);
        self::assertStringContainsString('repository_actionable_P0: 0', $closure);
        self::assertStringContainsString('repository_actionable_P1_for_core_launch: 0', $closure);
        self::assertStringContainsString('production_repository_substrate_complete: true', $closure);
        self::assertStringContainsString('production_ready: false', $closure);
        self::assertStringContainsString('go_live_status: BLOCKED_EXTERNAL_EVIDENCE', $closure);

        $requiredEvidence = [
            'target_production_configuration_preflight',
            'target_infrastructure_restore_drill',
            'production_backup_and_PITR_evidence',
            'production_object_versioning_and_restore_evidence',
            'production_secret_manager_or_equivalent_injection_evidence',
            'production_monitoring_dashboards_and_alert_routes',
            'incident_contact_roster_and_paging_smoke_test',
            'reconciliation_scheduler_execution_and_alert_delivery_smoke_test',
            'target_environment_release_smoke_test',
        ];

        foreach ($requiredEvidence as $id) {
            self::assertStringContainsString('- '.$id, $closure);
            self::assertStringContainsString('- '.$id, $evidence);
        }

        self::assertStringContainsString('production_ready_claim: forbidden', $preflight);
        self::assertStringContainsString('configuration_pass_still_means_go_live_blocked_external_evidence: true', $preflight);
        self::assertStringContainsString('production_ready_claim_from_repository_alone: forbidden', $evidence);
        self::assertStringContainsString('claims_human_received_page: false', $alerting);
        self::assertStringContainsString('human_ack_proven_by_repository: false', $smoke);
        self::assertStringContainsString('scheduler_runtime_proven_by_repository: false', $smoke);
        self::assertStringContainsString('PKK_PWPW: FROZEN_UNTIL_EXPLICIT_UNFREEZE', $closure);
    }
}
