<?php

use App\Support\Migrations\ConstraintPreflight;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-CON-TRAINING');

        ConstraintPreflight::assertChecks('MIG-CON-TRAINING', [
            [
                'name' => 'student_identity_pair_and_declaration',
                'table' => 'students',
                'columns' => ['pesel_ciphertext', 'pesel_lookup_hash', 'no_pesel_declared', 'birth_date', 'version'],
                'predicate' => '(src.pesel_ciphertext IS NULL) = (src.pesel_lookup_hash IS NULL) AND src.version >= 1 AND (NOT src.no_pesel_declared OR (src.pesel_ciphertext IS NULL AND src.birth_date IS NOT NULL)) AND (src.pesel_ciphertext IS NULL OR src.no_pesel_declared = false)',
            ],
            [
                'name' => 'course_stage_closed',
                'table' => 'course_enrollments',
                'columns' => ['training_stage'],
                'predicate' => 'src.training_stage IN (\'unassigned\',\'theory\',\'practice\',\'documentation\',\'word_exam\',\'supplementary_training\',\'training_completed\')',
            ],
            [
                'name' => 'course_numeric_bounds',
                'table' => 'course_enrollments',
                'columns' => ['declared_theory_minutes', 'declared_practical_minutes', 'version', 'requirements_revision'],
                'predicate' => '(src.declared_theory_minutes IS NULL OR src.declared_theory_minutes >= 0) AND (src.declared_practical_minutes IS NULL OR src.declared_practical_minutes >= 0) AND src.version >= 1 AND src.requirements_revision >= 1',
            ],
            [
                'name' => 'course_terminal_matrix',
                'table' => 'course_enrollments',
                'columns' => ['training_stage', 'completed_at', 'interrupted_at', 'cancelled_at', 'cancelled_by_user_id'],
                'predicate' => 'num_nonnulls(src.completed_at,src.interrupted_at,src.cancelled_at) <= 1 AND ((src.training_stage = \'training_completed\') = (src.completed_at IS NOT NULL)) AND (src.cancelled_at IS NULL OR src.cancelled_by_user_id IS NOT NULL)',
            ],
            [
                'name' => 'requirement_profile_numeric_bounds',
                'table' => 'training_requirement_profiles',
                'columns' => ['requirements_revision', 'course_version_after', 'minimum_theory_minutes', 'minimum_practical_minutes'],
                'predicate' => 'src.requirements_revision >= 1 AND src.course_version_after >= 1 AND src.minimum_theory_minutes >= 0 AND src.minimum_practical_minutes >= 0',
            ],
            [
                'name' => 'training_session_time_and_status',
                'table' => 'training_sessions',
                'columns' => ['session_type', 'starts_at', 'ends_at', 'duration_minutes', 'status', 'version'],
                'predicate' => 'src.session_type IN (\'theory\',\'practical\') AND src.ends_at > src.starts_at AND src.duration_minutes > 0 AND src.status IN (\'planned\',\'completed\',\'cancelled\') AND src.version >= 1',
            ],
            [
                'name' => 'training_session_terminal_matrix',
                'table' => 'training_sessions',
                'columns' => ['status', 'completed_at', 'completed_by_user_id', 'cancelled_at', 'cancelled_by_user_id'],
                'predicate' => '(src.status = \'planned\' AND src.completed_at IS NULL AND src.completed_by_user_id IS NULL AND src.cancelled_at IS NULL AND src.cancelled_by_user_id IS NULL) OR (src.status = \'completed\' AND src.completed_at IS NOT NULL AND src.completed_by_user_id IS NOT NULL AND src.cancelled_at IS NULL AND src.cancelled_by_user_id IS NULL) OR (src.status = \'cancelled\' AND src.completed_at IS NULL AND src.completed_by_user_id IS NULL AND src.cancelled_at IS NOT NULL AND src.cancelled_by_user_id IS NOT NULL)',
            ],
            [
                'name' => 'attendance_status_closed',
                'table' => 'training_session_attendance',
                'columns' => ['status'],
                'predicate' => 'src.status IN (\'present\',\'absent\')',
            ]
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
