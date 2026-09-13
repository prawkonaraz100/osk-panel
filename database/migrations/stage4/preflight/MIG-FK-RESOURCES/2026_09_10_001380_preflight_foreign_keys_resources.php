<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\ForeignKeyPreflight;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-FK-RESOURCES');

        ForeignKeyPreflight::assertRelations('MIG-FK-RESOURCES', [
            [
                'name' => 'staff_location_staff',
                'source_table' => 'staff_location_assignments',
                'source_columns' => ['organization_id', 'staff_profile_id'],
                'target_table' => 'staff_profiles',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'staff_location_location',
                'source_table' => 'staff_location_assignments',
                'source_columns' => ['organization_id', 'location_id'],
                'target_table' => 'locations',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'staff_membership_staff',
                'source_table' => 'staff_membership_links',
                'source_columns' => ['organization_id', 'staff_profile_id'],
                'target_table' => 'staff_profiles',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'staff_membership_membership',
                'source_table' => 'staff_membership_links',
                'source_columns' => ['organization_id', 'organization_membership_id'],
                'target_table' => 'organization_memberships',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'staff_document_staff',
                'source_table' => 'staff_documents',
                'source_columns' => ['organization_id', 'staff_profile_id'],
                'target_table' => 'staff_profiles',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'staff_document_asset',
                'source_table' => 'staff_documents',
                'source_columns' => ['organization_id', 'asset_id'],
                'target_table' => 'file_assets',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'vehicle_document_vehicle',
                'source_table' => 'vehicle_documents',
                'source_columns' => ['organization_id', 'vehicle_id'],
                'target_table' => 'vehicles',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'vehicle_document_asset',
                'source_table' => 'vehicle_documents',
                'source_columns' => ['organization_id', 'asset_id'],
                'target_table' => 'file_assets',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'vehicle_location_vehicle',
                'source_table' => 'vehicle_location_assignments',
                'source_columns' => ['organization_id', 'vehicle_id'],
                'target_table' => 'vehicles',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'vehicle_location_location',
                'source_table' => 'vehicle_location_assignments',
                'source_columns' => ['organization_id', 'location_id'],
                'target_table' => 'locations',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'student_default_location',
                'source_table' => 'students',
                'source_columns' => ['organization_id', 'default_location_id'],
                'target_table' => 'locations',
                'target_columns' => ['organization_id', 'id'],
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
