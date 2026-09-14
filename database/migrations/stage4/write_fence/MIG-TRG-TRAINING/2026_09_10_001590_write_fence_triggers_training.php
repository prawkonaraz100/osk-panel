<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\TriggerGuards\TrainingTriggerGuards;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-TRG-TRAINING');

        TrainingTriggerGuards::install();
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
