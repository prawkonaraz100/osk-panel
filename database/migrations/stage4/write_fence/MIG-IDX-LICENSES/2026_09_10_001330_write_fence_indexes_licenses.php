<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\IndexWriteFence;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-IDX-LICENSES');

        IndexWriteFence::installUniqueIndexes('MIG-IDX-LICENSES', [
            [
                'name' => 'license_current_assignment_unique_per_inventory',
                'table' => 'license_assignments',
                'columns' => ['organization_id', 'license_inventory_entry_id'],
                'predicate' => 'status IN (\'assigned\', \'activated\') AND revoked_at IS NULL',
            ],
            [
                'name' => 'license_assignment_sequence_unique',
                'table' => 'license_assignments',
                'columns' => ['organization_id', 'student_learning_account_id', 'assignment_sequence'],
                'predicate' => null,
            ],
            [
                'name' => 'license_activation_unique_per_assignment',
                'table' => 'license_activations',
                'columns' => ['organization_id', 'license_assignment_id'],
                'predicate' => null,
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
