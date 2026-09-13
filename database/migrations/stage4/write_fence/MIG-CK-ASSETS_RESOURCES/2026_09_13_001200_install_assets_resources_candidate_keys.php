<?php

use App\Support\Migrations\CandidateKeyMigrationSupport;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use LogicException;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-ASSETS_RESOURCES');

        CandidateKeyMigrationSupport::install(
            'MIG-CK-ASSETS_RESOURCES',
            [
            [
                'name' => 'ck_file_assets_org_id',
                'table' => 'file_assets',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['id'],
            ],
            [
                'name' => 'ck_staff_profiles_org_id',
                'table' => 'staff_profiles',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['organization_id', 'id'],
            ],
            [
                'name' => 'ck_locations_org_id',
                'table' => 'locations',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['organization_id', 'id'],
            ],
            [
                'name' => 'ck_vehicles_org_id',
                'table' => 'vehicles',
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
