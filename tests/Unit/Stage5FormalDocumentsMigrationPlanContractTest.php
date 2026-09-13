<?php

namespace Tests\Unit;

use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\Stage5FormalDocumentsMigrationPlan;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

class Stage5FormalDocumentsMigrationPlanContractTest extends TestCase
{
    public function test_formal_documents_extension_is_isolated_from_frozen_stage_four_plan(): void
    {
        $stage4 = new MigrationPlan;
        $stage4->validate();

        $extension = new Stage5FormalDocumentsMigrationPlan;
        $extension->validate();

        $this->assertSame(170, $stage4->nodeCount());
        $this->assertSame(112, $stage4->implementedNodeCount());
        $this->assertSame(112, $stage4->implementedStepCount());
        $this->assertSame(Stage5FormalDocumentsMigrationPlan::STAGE4_PLAN_IDENTITY, $stage4->identity());
        $this->assertSame(Stage5FormalDocumentsMigrationPlan::STAGE4_EXECUTION_IDENTITY, $stage4->executionIdentity());

        $this->assertSame(4, $extension->nodeCount());
        $this->assertSame(0, $extension->implementedNodeCount());
        $this->assertSame(0, $extension->implementedStepCount());
        $this->assertSame('34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99', $extension->identity());
        $this->assertSame('8948ce50d6c7f3c8871586945d2e757397600182820cbb0e89d80add12998ade', $extension->executionIdentity());
        $this->assertSame(Stage5FormalDocumentsMigrationPlan::AUTHORITY_BLOB, $extension->summary()['authority_blob']);
        $this->assertSame(Stage5FormalDocumentsMigrationPlan::STAGE4_PLAN_IDENTITY, $extension->summary()['stage4_plan_identity']);
        $this->assertSame(Stage5FormalDocumentsMigrationPlan::STAGE4_EXECUTION_IDENTITY, $extension->summary()['stage4_execution_identity']);
    }

    public function test_validation_command_exposes_both_extension_and_stage_four_identities(): void
    {
        $exit = Artisan::call('migration:stage5:formal-docs:plan:validate', ['--json' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99', $payload['plan_identity']);
        $this->assertSame('8948ce50d6c7f3c8871586945d2e757397600182820cbb0e89d80add12998ade', $payload['execution_identity']);
        $this->assertSame(Stage5FormalDocumentsMigrationPlan::STAGE4_PLAN_IDENTITY, $payload['stage4_plan_identity']);
        $this->assertSame(Stage5FormalDocumentsMigrationPlan::STAGE4_EXECUTION_IDENTITY, $payload['stage4_execution_identity']);
        $this->assertSame(4, $payload['nodes']);
        $this->assertSame(0, $payload['implemented_steps']);
    }

    public function test_controlled_executor_fails_closed_before_any_extension_step_is_materialized(): void
    {
        $extension = new Stage5FormalDocumentsMigrationPlan;
        $extension->validate();

        $exit = Artisan::call('migration:stage5:formal-docs:controlled', [
            '--plan' => $extension->identity(),
            '--execution' => $extension->executionIdentity(),
            '--phase' => 'expand',
            '--force' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString(
            'No materialized Stage-5 formal-documents migration steps for phase expand',
            Artisan::output(),
        );
    }
}
