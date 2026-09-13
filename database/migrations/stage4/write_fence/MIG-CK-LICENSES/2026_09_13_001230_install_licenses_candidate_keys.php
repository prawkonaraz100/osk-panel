<?php

use App\Support\Migrations\CandidateKeyMigrationSupport;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use LogicException;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-LICENSES');

        CandidateKeyMigrationSupport::install(
            'MIG-CK-LICENSES',
            [
            [
                'name' => 'ck_license_inventory_org_id',
                'table' => 'license_inventory_entries',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['organization_id', 'id'],
            ],
            [
                'name' => 'ck_license_assignments_org_id',
                'table' => 'license_assignments',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['organization_id', 'id'],
            ]
            ],
        );
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for Stage-4 candidate-key write fences.');
    }
};
