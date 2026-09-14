<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\Stage4ReviewedReconciliation;
use App\Support\Migrations\TriggerWriteFence;
use App\Support\Migrations\ProjectionGuards\NotificationsProjectionGuards;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'MIG-PRJ-NOTIFICATIONS');

        TriggerWriteFence::assertInstalled('MIG-PRJ-NOTIFICATIONS', NotificationsProjectionGuards::definitions());

        Stage4ReviewedReconciliation::assertResolved('MIG-PRJ-NOTIFICATIONS');
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
