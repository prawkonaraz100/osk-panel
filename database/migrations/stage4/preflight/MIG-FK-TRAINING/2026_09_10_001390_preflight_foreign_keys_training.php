<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\ForeignKeyPreflight;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-FK-TRAINING');

        ForeignKeyPreflight::assertRelations('MIG-FK-TRAINING', [
            [
                'name' => 'learning_account_student',
                'source_table' => 'student_learning_accounts',
                'source_columns' => ['organization_id', 'student_id'],
                'target_table' => 'students',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'learning_account_exact_auth_identifier_user',
                'source_table' => 'student_learning_accounts',
                'source_columns' => ['auth_login_identifier_id', 'user_id'],
                'target_table' => 'auth_login_identifiers',
                'target_columns' => ['id', 'user_id'],
            ],
            [
                'name' => 'course_student',
                'source_table' => 'course_enrollments',
                'source_columns' => ['organization_id', 'student_id'],
                'target_table' => 'students',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'course_instructor',
                'source_table' => 'course_enrollments',
                'source_columns' => ['organization_id', 'lead_instructor_id'],
                'target_table' => 'staff_profiles',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'course_location',
                'source_table' => 'course_enrollments',
                'source_columns' => ['organization_id', 'location_id'],
                'target_table' => 'locations',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'course_driving_category',
                'source_table' => 'course_enrollments',
                'source_columns' => ['driving_category_id'],
                'target_table' => 'driving_categories',
                'target_columns' => ['id'],
            ],
            [
                'name' => 'requirement_profile_course',
                'source_table' => 'training_requirement_profiles',
                'source_columns' => ['organization_id', 'course_enrollment_id'],
                'target_table' => 'course_enrollments',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'exemption_course',
                'source_table' => 'course_exemption_decisions',
                'source_columns' => ['organization_id', 'course_enrollment_id'],
                'target_table' => 'course_enrollments',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'external_training_course',
                'source_table' => 'recognized_external_training',
                'source_columns' => ['organization_id', 'course_enrollment_id'],
                'target_table' => 'course_enrollments',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'session_course',
                'source_table' => 'training_sessions',
                'source_columns' => ['organization_id', 'course_enrollment_id'],
                'target_table' => 'course_enrollments',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'session_instructor',
                'source_table' => 'training_sessions',
                'source_columns' => ['organization_id', 'instructor_id'],
                'target_table' => 'staff_profiles',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'session_vehicle',
                'source_table' => 'training_sessions',
                'source_columns' => ['organization_id', 'vehicle_id'],
                'target_table' => 'vehicles',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'session_location',
                'source_table' => 'training_sessions',
                'source_columns' => ['organization_id', 'location_id'],
                'target_table' => 'locations',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'attendance_exact_session_course',
                'source_table' => 'training_session_attendance',
                'source_columns' => ['organization_id', 'training_session_id', 'course_enrollment_id'],
                'target_table' => 'training_sessions',
                'target_columns' => ['organization_id', 'id', 'course_enrollment_id'],
            ],
            [
                'name' => 'attendance_exact_course_student',
                'source_table' => 'training_session_attendance',
                'source_columns' => ['organization_id', 'course_enrollment_id', 'student_id'],
                'target_table' => 'course_enrollments',
                'target_columns' => ['organization_id', 'id', 'student_id'],
            ],
            [
                'name' => 'ledger_course',
                'source_table' => 'training_hour_ledger_entries',
                'source_columns' => ['organization_id', 'course_enrollment_id'],
                'target_table' => 'course_enrollments',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'ledger_exact_session_course',
                'source_table' => 'training_hour_ledger_entries',
                'source_columns' => ['organization_id', 'training_session_id', 'course_enrollment_id'],
                'target_table' => 'training_sessions',
                'target_columns' => ['organization_id', 'id', 'course_enrollment_id'],
            ],
            [
                'name' => 'ledger_source_same_course_part',
                'source_table' => 'training_hour_ledger_entries',
                'source_columns' => ['organization_id', 'source_entry_id', 'course_enrollment_id', 'training_part'],
                'target_table' => 'training_hour_ledger_entries',
                'target_columns' => ['organization_id', 'id', 'course_enrollment_id', 'training_part'],
            ]
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
