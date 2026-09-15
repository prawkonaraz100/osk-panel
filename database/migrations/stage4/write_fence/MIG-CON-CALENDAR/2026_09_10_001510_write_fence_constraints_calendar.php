<?php

use App\Support\Migrations\ConstraintWriteFence;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CON-CALENDAR');

        ConstraintWriteFence::install('MIG-CON-CALENDAR', [
            [
                'name' => 'calendar_event_closed_state',
                'table' => 'calendar_events',
                'columns' => ['event_type', 'starts_at', 'ends_at', 'status', 'version'],
                'predicate' => 'src.event_type = \'general_event\' AND src.ends_at > src.starts_at AND src.status IN (\'scheduled\',\'completed\',\'cancelled\') AND src.version >= 1',
            ],
            [
                'name' => 'calendar_event_meeting_place_xor',
                'table' => 'calendar_events',
                'columns' => ['location_id', 'custom_meeting_place'],
                'predicate' => 'NOT (src.location_id IS NOT NULL AND NULLIF(BTRIM(src.custom_meeting_place),\'\') IS NOT NULL)',
            ],
            [
                'name' => 'calendar_event_terminal_matrix',
                'table' => 'calendar_events',
                'columns' => ['status', 'completed_at', 'completed_by_user_id', 'cancelled_at', 'cancelled_by_user_id'],
                'predicate' => '(src.status = \'scheduled\' AND src.completed_at IS NULL AND src.completed_by_user_id IS NULL AND src.cancelled_at IS NULL AND src.cancelled_by_user_id IS NULL) OR (src.status = \'completed\' AND src.completed_at IS NOT NULL AND src.completed_by_user_id IS NOT NULL AND src.cancelled_at IS NULL AND src.cancelled_by_user_id IS NULL) OR (src.status = \'cancelled\' AND src.completed_at IS NULL AND src.completed_by_user_id IS NULL AND src.cancelled_at IS NOT NULL AND src.cancelled_by_user_id IS NOT NULL)',
            ],
            [
                'name' => 'availability_slot_closed_state',
                'table' => 'availability_slots',
                'columns' => ['starts_at', 'ends_at', 'status', 'version'],
                'predicate' => 'src.ends_at > src.starts_at AND src.status IN (\'available\',\'booked\',\'cancelled\') AND src.version >= 1',
            ],
            [
                'name' => 'availability_slot_state_matrix',
                'table' => 'availability_slots',
                'columns' => ['status', 'booked_student_id', 'booked_at', 'training_session_id'],
                'predicate' => '(src.status = \'available\' AND src.booked_student_id IS NULL AND src.booked_at IS NULL AND src.training_session_id IS NULL) OR (src.status = \'booked\' AND src.booked_student_id IS NOT NULL AND src.booked_at IS NOT NULL) OR (src.status = \'cancelled\' AND src.booked_student_id IS NULL AND src.booked_at IS NULL AND src.training_session_id IS NULL)',
            ],
            [
                'name' => 'calendar_claim_closed_shape',
                'table' => 'calendar_resource_claims',
                'columns' => ['claim_owner_kind', 'student_id', 'instructor_id', 'vehicle_id', 'location_id', 'starts_at', 'ends_at'],
                'predicate' => 'src.ends_at > src.starts_at AND src.claim_owner_kind IN (\'calendar_event\',\'availability_slot_booking\',\'training_session\') AND num_nonnulls(src.student_id,src.instructor_id,src.vehicle_id,src.location_id) = 1',
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
