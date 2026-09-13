<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\IndexWriteFence;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-IDX-EXAMS');

        IndexWriteFence::installUniqueIndexes('MIG-IDX-EXAMS', [
            [
                'name' => 'internal_exam_inventory_ledger_sequence_unique',
                'table' => 'internal_exam_inventory_ledger_entries',
                'columns' => ['organization_id', 'internal_exam_inventory_entry_id', 'event_sequence'],
                'predicate' => null,
            ],
            [
                'name' => 'internal_exam_course_attempt_sequence_unique',
                'table' => 'internal_exam_attempts',
                'columns' => ['organization_id', 'course_enrollment_id', 'course_attempt_sequence'],
                'predicate' => null,
            ],
            [
                'name' => 'internal_exam_one_active_reservation_per_attempt',
                'table' => 'internal_exam_reservations',
                'columns' => ['organization_id', 'internal_exam_attempt_id'],
                'predicate' => 'status = \'reserved\'',
            ],
            [
                'name' => 'internal_exam_one_consumed_reservation_per_attempt',
                'table' => 'internal_exam_reservations',
                'columns' => ['organization_id', 'internal_exam_attempt_id'],
                'predicate' => 'status = \'consumed\'',
            ],
            [
                'name' => 'internal_exam_one_nonterminal_access_per_attempt',
                'table' => 'internal_exam_accesses',
                'columns' => ['organization_id', 'internal_exam_attempt_id'],
                'predicate' => 'status IN (\'draft\', \'ready\', \'delivered_or_assigned\', \'opened\', \'started\')',
            ],
            [
                'name' => 'internal_exam_one_started_access_per_attempt',
                'table' => 'internal_exam_accesses',
                'columns' => ['organization_id', 'internal_exam_attempt_id'],
                'predicate' => 'started_at IS NOT NULL',
            ],
            [
                'name' => 'internal_exam_one_active_station_session_per_attempt',
                'table' => 'internal_exam_station_sessions',
                'columns' => ['organization_id', 'internal_exam_attempt_id'],
                'predicate' => 'ended_at IS NULL',
            ],
            [
                'name' => 'internal_exam_one_active_station_session_per_station',
                'table' => 'internal_exam_station_sessions',
                'columns' => ['organization_id', 'exam_station_id'],
                'predicate' => 'ended_at IS NULL',
            ],
            [
                'name' => 'internal_exam_station_session_sequence_unique',
                'table' => 'internal_exam_station_sessions',
                'columns' => ['organization_id', 'internal_exam_attempt_id', 'session_sequence'],
                'predicate' => null,
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
