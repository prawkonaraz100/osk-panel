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
        $this->assertSame(1, $plan->implementedNodeCount());
        $this->assertSame(1, $plan->implementedStepCount());
        $this->assertSame('ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441', $plan->summary()['authority_blob']);
        $this->assertSame('05243ae2bd1d283ad53e33ab8dbc887317ef27128bb08ebcce381e3c8f7857ec', $plan->executionIdentity());
        $this->assertSame(['MIG-EXT-BTREE-GIST'], array_column($plan->phaseSteps('expand'), 'node_id'));
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
