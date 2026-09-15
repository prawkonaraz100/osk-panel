<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ProductionHealthCoreTest extends TestCase
{
    public function test_liveness_is_public_and_dependency_free(): void
    {
        $this->getJson('/health/live')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'osk-panel',
            ]);
    }

    public function test_readiness_proves_required_local_runtime_dependencies(): void
    {
        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('dependencies.database', 'ok')
            ->assertJsonPath('dependencies.redis', 'ok');
    }
}
