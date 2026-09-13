<?php

use App\Support\Migrations\CandidateKeyWriteFence;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-ASSETS_RESOURCES');

        CandidateKeyWriteFence::install('MIG-CK-ASSETS_RESOURCES', [
            [
                'name' => 'file_asset_candidate_key_org_id',
                'table' => 'file_assets',
                'columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'staff_profile_candidate_key_org_id',
                'table' => 'staff_profiles',
                'columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'location_candidate_key_org_id',
                'table' => 'locations',
                'columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'vehicle_candidate_key_org_id',
                'table' => 'vehicles',
                'columns' => ['organization_id', 'id'],
            ]
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
