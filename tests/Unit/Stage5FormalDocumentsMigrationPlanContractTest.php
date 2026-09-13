<?php

namespace Tests\Unit;

use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\Stage5FormalDocumentsMigrationPlan;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

class Stage5FormalDocumentsMigrationPlanContractTest extends TestCase
{
    public function test_formal_documents_backfill_registry_is_isolated_from_frozen_stage_four_plan(): void
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
        $this->assertSame(4, $extension->implementedNodeCount());
        $this->assertSame(6, $extension->implementedStepCount());
        $this->assertSame('34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99', $extension->identity());
        $this->assertSame('fc32a8fc010561cd395af881a10191c76bc9c53266d34bdab56eaa382f061094', $extension->executionIdentity());
        $this->assertSame(Stage5FormalDocumentsMigrationPlan::AUTHORITY_BLOB, $extension->summary()['authority_blob']);
        $this->assertSame(Stage5FormalDocumentsMigrationPlan::STAGE4_PLAN_IDENTITY, $extension->summary()['stage4_plan_identity']);
        $this->assertSame(Stage5FormalDocumentsMigrationPlan::STAGE4_EXECUTION_IDENTITY, $extension->summary()['stage4_execution_identity']);

        $this->assertSame([
            'S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE',
            'S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-TEMPLATES',
            'S5DOC-TBL-FORMAL-TRAINING-DOCUMENTS',
            'S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-EVENTS',
        ], array_column($extension->phaseSteps('expand'), 'node_id'));
        $this->assertSame(
            ['S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE'],
            array_column($extension->phaseSteps('preflight'), 'node_id'),
        );
        $this->assertSame(
            ['S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE'],
            array_column($extension->phaseSteps('backfill'), 'node_id'),
        );
        $this->assertSame([], $extension->phaseSteps('validate'));
    }

    public function test_validation_command_exposes_backfill_registry_and_stage_four_identities(): void
    {
        $exit = Artisan::call('migration:stage5:formal-docs:plan:validate', ['--json' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99', $payload['plan_identity']);
        $this->assertSame('fc32a8fc010561cd395af881a10191c76bc9c53266d34bdab56eaa382f061094', $payload['execution_identity']);
        $this->assertSame(Stage5FormalDocumentsMigrationPlan::STAGE4_PLAN_IDENTITY, $payload['stage4_plan_identity']);
        $this->assertSame(Stage5FormalDocumentsMigrationPlan::STAGE4_EXECUTION_IDENTITY, $payload['stage4_execution_identity']);
        $this->assertSame(4, $payload['nodes']);
        $this->assertSame(4, $payload['implemented_nodes']);
        $this->assertSame(6, $payload['implemented_steps']);
    }

    public function test_controlled_executor_fails_closed_before_validate_is_materialized(): void
    {
        $extension = new Stage5FormalDocumentsMigrationPlan;
        $extension->validate();

        $exit = Artisan::call('migration:stage5:formal-docs:controlled', [
            '--plan' => $extension->identity(),
            '--execution' => $extension->executionIdentity(),
            '--phase' => 'validate',
            '--force' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString(
            'No materialized Stage-5 formal-documents migration steps for phase validate',
            Artisan::output(),
        );
    }
}
