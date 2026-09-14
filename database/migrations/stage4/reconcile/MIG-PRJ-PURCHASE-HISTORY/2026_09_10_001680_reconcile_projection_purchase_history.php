<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\Stage4ReviewedReconciliation;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('reconcile', 'MIG-PRJ-PURCHASE-HISTORY');

        Stage4ReviewedReconciliation::assertResolved('MIG-PRJ-PURCHASE-HISTORY');
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
