<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\TriggerWriteFence;
use App\Support\Migrations\Stage4ReviewedReconciliation;
use App\Support\Migrations\ProjectionGuards\CalendarResourceClaimsProjectionGuards;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'MIG-PRJ-CALENDAR-RESOURCE-CLAIMS');

        TriggerWriteFence::assertInstalled('MIG-PRJ-CALENDAR-RESOURCE-CLAIMS', CalendarResourceClaimsProjectionGuards::definitions());

        Stage4ReviewedReconciliation::assertResolved('MIG-PRJ-CALENDAR-RESOURCE-CLAIMS');
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
