<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\TriggerGuards\CalendarTriggerGuards;
use App\Support\Migrations\TriggerWriteFence;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'MIG-TRG-CALENDAR');

        TriggerWriteFence::assertInstalled('MIG-TRG-CALENDAR', CalendarTriggerGuards::definitions());
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
