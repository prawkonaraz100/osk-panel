<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\IndexWriteFence;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-IDX-FINANCE');

        IndexWriteFence::installUniqueIndexes('MIG-IDX-FINANCE', [
            [
                'name' => 'course_cost_charge_origin_unique_per_course',
                'table' => 'course_cost_charge_origins',
                'columns' => ['organization_id', 'course_enrollment_id'],
                'predicate' => null,
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
