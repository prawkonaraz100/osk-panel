<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\IndexWriteFence;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-IDX-RESOURCES');

        IndexWriteFence::installUniqueIndexes('MIG-IDX-RESOURCES', [
            [
                'name' => 'staff_active_membership_link_unique_per_profile',
                'table' => 'staff_membership_links',
                'columns' => ['staff_profile_id'],
                'predicate' => 'unlinked_at IS NULL',
            ],
            [
                'name' => 'staff_active_membership_link_unique_per_membership',
                'table' => 'staff_membership_links',
                'columns' => ['organization_membership_id'],
                'predicate' => 'unlinked_at IS NULL',
            ],
            [
                'name' => 'staff_pesel_unique_per_organization_including_archived',
                'table' => 'staff_profiles',
                'columns' => ['organization_id', 'pesel_lookup_hash'],
                'predicate' => 'pesel_lookup_hash IS NOT NULL',
            ],
            [
                'name' => 'staff_document_current_unique',
                'table' => 'staff_documents',
                'columns' => ['organization_id', 'staff_profile_id', 'document_type'],
                'predicate' => 'superseded_at IS NULL',
            ],
            [
                'name' => 'vehicle_vin_unique_per_organization_including_archived',
                'table' => 'vehicles',
                'columns' => ['organization_id', 'vin_normalized'],
                'predicate' => 'vin_normalized IS NOT NULL',
            ],
            [
                'name' => 'vehicle_registration_unique_per_organization_current_fleet',
                'table' => 'vehicles',
                'columns' => ['organization_id', 'registration_number_normalized'],
                'predicate' => 'archived_at IS NULL',
            ],
            [
                'name' => 'vehicle_document_current_unique',
                'table' => 'vehicle_documents',
                'columns' => ['organization_id', 'vehicle_id', 'document_type'],
                'predicate' => 'superseded_at IS NULL',
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
