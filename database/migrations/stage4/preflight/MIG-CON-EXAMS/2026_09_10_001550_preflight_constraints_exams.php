<?php

use App\Support\Migrations\ConstraintPreflight;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-CON-EXAMS');

        ConstraintPreflight::assertChecks('MIG-CON-EXAMS', [
            [
                'name' => 'exam_inventory_closed_state',
                'table' => 'internal_exam_inventory_entries',
                'columns' => ['source_type', 'current_state'],
                'predicate' => 'src.source_type IN (\'free\',\'paid\',\'adjustment\') AND src.current_state IN (\'available\',\'reserved\',\'consumed\',\'adjusted_out\')',
            ],
            [
                'name' => 'exam_inventory_ledger_closed_event',
                'table' => 'internal_exam_inventory_ledger_entries',
                'columns' => ['event_sequence', 'event_type'],
                'predicate' => 'src.event_sequence >= 1 AND src.event_type IN (\'unit_granted\',\'unit_adjustment_granted\',\'unit_reserved\',\'unit_released\',\'unit_consumed\',\'unit_adjusted_out\',\'migration_baseline\')',
            ],
            [
                'name' => 'exam_attempt_closed_state',
                'table' => 'internal_exam_attempts',
                'columns' => ['exam_part', 'course_attempt_sequence', 'status', 'version'],
                'predicate' => 'src.exam_part IN (\'theory\',\'practical\') AND src.course_attempt_sequence >= 1 AND src.version >= 1 AND src.status IN (\'created\',\'in_progress\',\'passed\',\'failed\',\'technical_abort\',\'invalidated\')',
            ],
            [
                'name' => 'exam_access_closed_state_and_mode',
                'table' => 'internal_exam_accesses',
                'columns' => ['launch_mode', 'station_id', 'status', 'version', 'expires_at'],
                'predicate' => 'src.version >= 1 AND src.status IN (\'draft\',\'ready\',\'delivered_or_assigned\',\'opened\',\'started\',\'completed\',\'cancelled\',\'expired\',\'revoked\',\'technical_abort\',\'invalidated\') AND ((src.launch_mode = \'remote_link\' AND src.station_id IS NULL AND src.expires_at IS NOT NULL) OR (src.launch_mode IN (\'local_current_workstation\',\'assigned_exam_station\') AND src.station_id IS NOT NULL AND src.expires_at IS NULL))',
            ],
            [
                'name' => 'exam_station_session_end_tuple',
                'table' => 'internal_exam_station_sessions',
                'columns' => ['session_sequence', 'started_at', 'ended_at', 'end_reason'],
                'predicate' => 'src.session_sequence >= 1 AND ((src.ended_at IS NULL AND src.end_reason IS NULL) OR (src.ended_at IS NOT NULL AND src.ended_at >= src.started_at AND src.end_reason IS NOT NULL))',
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
