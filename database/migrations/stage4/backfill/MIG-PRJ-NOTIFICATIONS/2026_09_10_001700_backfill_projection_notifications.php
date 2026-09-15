<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\Stage4ExactEvidenceBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('backfill', 'MIG-PRJ-NOTIFICATIONS');

        Stage4ExactEvidenceBackfill::run('MIG-PRJ-NOTIFICATIONS');
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
