<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\IndexWriteFence;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-IDX-CALENDAR_GIST');

        IndexWriteFence::installExclusionConstraints('MIG-IDX-CALENDAR_GIST', [
            [
                'name' => 'calendar_claim_student_no_overlap',
                'table' => 'calendar_resource_claims',
                'columns' => ['organization_id', 'student_id', 'occupied_during'],
                'predicate' => 'student_id IS NOT NULL',
            ],
            [
                'name' => 'calendar_claim_instructor_no_overlap',
                'table' => 'calendar_resource_claims',
                'columns' => ['organization_id', 'instructor_id', 'occupied_during'],
                'predicate' => 'instructor_id IS NOT NULL',
            ],
            [
                'name' => 'calendar_claim_vehicle_no_overlap',
                'table' => 'calendar_resource_claims',
                'columns' => ['organization_id', 'vehicle_id', 'occupied_during'],
                'predicate' => 'vehicle_id IS NOT NULL',
            ],
            [
                'name' => 'calendar_claim_location_no_overlap',
                'table' => 'calendar_resource_claims',
                'columns' => ['organization_id', 'location_id', 'occupied_during'],
                'predicate' => 'location_id IS NOT NULL',
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
