<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\Stage4ReviewedReconciliation;
use App\Support\Migrations\TriggerWriteFence;
use App\Support\Migrations\ProjectionGuards\OrganizationActivityProjectionGuards;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'MIG-PRJ-ORGANIZATION-ACTIVITY');

        TriggerWriteFence::assertInstalled('MIG-PRJ-ORGANIZATION-ACTIVITY', OrganizationActivityProjectionGuards::definitions());

        Stage4ReviewedReconciliation::assertResolved('MIG-PRJ-ORGANIZATION-ACTIVITY');
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
