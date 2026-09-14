<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\TriggerWriteFence;
use App\Support\Migrations\TriggerGuards\PkkTriggerGuards;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'MIG-TRG-PKK');

        TriggerWriteFence::assertInstalled('MIG-TRG-PKK', PkkTriggerGuards::definitions());
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
