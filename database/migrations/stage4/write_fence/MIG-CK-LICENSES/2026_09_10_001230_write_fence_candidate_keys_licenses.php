<?php

use App\Support\Migrations\CandidateKeyWriteFence;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-LICENSES');

        CandidateKeyWriteFence::install('MIG-CK-LICENSES', [
            [
                'name' => 'license_inventory_entry_candidate_key_org_id',
                'table' => 'license_inventory_entries',
                'columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'license_assignment_candidate_key_org_id',
                'table' => 'license_assignments',
                'columns' => ['organization_id', 'id'],
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
