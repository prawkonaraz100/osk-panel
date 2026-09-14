<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\TriggerWriteFence;
use App\Support\Migrations\Stage4ReviewedReconciliation;
use App\Support\Migrations\TriggerGuards\EventsTriggerGuards;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'MIG-TRG-EVENTS');

        TriggerWriteFence::assertInstalled('MIG-TRG-EVENTS', EventsTriggerGuards::definitions());

        Stage4ReviewedReconciliation::assertResolved('MIG-TRG-EVENTS');
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
