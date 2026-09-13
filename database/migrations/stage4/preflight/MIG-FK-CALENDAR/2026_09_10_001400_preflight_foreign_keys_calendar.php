<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\ForeignKeyPreflight;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-FK-CALENDAR');

        ForeignKeyPreflight::assertRelations('MIG-FK-CALENDAR', [
            [
                'name' => 'calendar_event_student',
                'source_table' => 'calendar_events',
                'source_columns' => ['organization_id', 'student_id'],
                'target_table' => 'students',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'calendar_event_instructor',
                'source_table' => 'calendar_events',
                'source_columns' => ['organization_id', 'instructor_id'],
                'target_table' => 'staff_profiles',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'calendar_event_vehicle',
                'source_table' => 'calendar_events',
                'source_columns' => ['organization_id', 'vehicle_id'],
                'target_table' => 'vehicles',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'calendar_event_location',
                'source_table' => 'calendar_events',
                'source_columns' => ['organization_id', 'location_id'],
                'target_table' => 'locations',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'calendar_event_creator',
                'source_table' => 'calendar_events',
                'source_columns' => ['created_by_user_id'],
                'target_table' => 'users',
                'target_columns' => ['id'],
            ],
            [
                'name' => 'availability_instructor',
                'source_table' => 'availability_slots',
                'source_columns' => ['organization_id', 'instructor_id'],
                'target_table' => 'staff_profiles',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'availability_vehicle',
                'source_table' => 'availability_slots',
                'source_columns' => ['organization_id', 'vehicle_id'],
                'target_table' => 'vehicles',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'availability_location',
                'source_table' => 'availability_slots',
                'source_columns' => ['organization_id', 'location_id'],
                'target_table' => 'locations',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'availability_booked_student',
                'source_table' => 'availability_slots',
                'source_columns' => ['organization_id', 'booked_student_id'],
                'target_table' => 'students',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'availability_training_session',
                'source_table' => 'availability_slots',
                'source_columns' => ['organization_id', 'training_session_id'],
                'target_table' => 'training_sessions',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'claim_student',
                'source_table' => 'calendar_resource_claims',
                'source_columns' => ['organization_id', 'student_id'],
                'target_table' => 'students',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'claim_instructor',
                'source_table' => 'calendar_resource_claims',
                'source_columns' => ['organization_id', 'instructor_id'],
                'target_table' => 'staff_profiles',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'claim_vehicle',
                'source_table' => 'calendar_resource_claims',
                'source_columns' => ['organization_id', 'vehicle_id'],
                'target_table' => 'vehicles',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'claim_location',
                'source_table' => 'calendar_resource_claims',
                'source_columns' => ['organization_id', 'location_id'],
                'target_table' => 'locations',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'training_calendar_session',
                'source_table' => 'training_session_calendar_details',
                'source_columns' => ['organization_id', 'training_session_id'],
                'target_table' => 'training_sessions',
                'target_columns' => ['organization_id', 'id'],
            ]
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
