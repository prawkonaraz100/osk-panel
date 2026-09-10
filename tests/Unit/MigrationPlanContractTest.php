<?php

namespace Tests\Unit;

use App\Support\Migrations\MigrationPlan;
use LogicException;
use Tests\TestCase;

class MigrationPlanContractTest extends TestCase
{
    public function test_stage_four_authority_resolves_to_exact_reviewable_plan(): void
    {
        $plan = new MigrationPlan;
        $plan->validate();

        $this->assertSame(170, $plan->nodeCount());
        $this->assertSame(17, $plan->batchCount());
        $this->assertSame(18, $plan->implementedNodeCount());
        $this->assertSame(18, $plan->implementedStepCount());
        $this->assertSame('ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441', $plan->summary()['authority_blob']);
        $this->assertSame('c462faba809196af8a4122feccf78dd029d4f6fab1a3d917a949827af48e63b5', $plan->executionIdentity());
        $this->assertSame([
            'MIG-EXT-BTREE-GIST',
            'MIG-TBL-ORGANIZATIONS',
            'MIG-TBL-USERS',
            'MIG-TBL-PERMISSIONS',
            'MIG-TBL-DATA_SCOPES',
            'MIG-TBL-ORGANIZATION_SETTINGS',
            'MIG-TBL-ORGANIZATION_CONTACT_ADDRESSES',
            'MIG-TBL-AUTH_LOGIN_IDENTIFIERS',
            'MIG-TBL-ORGANIZATION_MEMBERSHIPS',
            'MIG-TBL-MEMBERSHIP_PERMISSIONS',
            'MIG-TBL-PERMISSION_SCOPE_OPTIONS',
            'MIG-TBL-MEMBERSHIP_PERMISSION_SCOPES',
            'MIG-TBL-AUTH_SESSIONS',
            'MIG-TBL-AUDIT_ACTION_POLICY_REVISIONS',
            'MIG-TBL-AUDIT_ACTION_POLICY_CURRENTS',
            'MIG-TBL-AUDIT_LOGS',
            'MIG-TBL-DOMAIN_EVENTS',
            'MIG-TBL-OUTBOX_MESSAGES',
        ], array_column($plan->phaseSteps('expand'), 'node_id'));
    }

    public function test_later_phase_is_closed_until_every_authoritative_earlier_phase_step_is_materialized_and_applied(): void
    {
        $plan = new MigrationPlan;
        $plan->validate();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No materialized migration steps for phase preflight');
        $plan->assertPhaseEntry('preflight', []);
    }
}
