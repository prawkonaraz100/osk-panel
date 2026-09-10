<?php

namespace Tests\Unit;

use App\Support\Migrations\MigrationPlan;
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
        $this->assertSame('ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441', $plan->summary()['authority_blob']);
    }
}
