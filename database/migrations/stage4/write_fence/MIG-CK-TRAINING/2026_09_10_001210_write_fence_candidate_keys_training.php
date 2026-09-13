<?php

use App\Support\Migrations\CandidateKeyWriteFence;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-TRAINING');

        CandidateKeyWriteFence::install('MIG-CK-TRAINING', [
            [
                'name' => 'student_candidate_key_org_id',
                'table' => 'students',
                'columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'student_learning_account_candidate_key_org_id',
                'table' => 'student_learning_accounts',
                'columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'student_learning_account_candidate_key_org_id_student',
                'table' => 'student_learning_accounts',
                'columns' => ['organization_id', 'id', 'student_id'],
            ],
            [
                'name' => 'course_enrollment_candidate_key_org_id',
                'table' => 'course_enrollments',
                'columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'course_enrollment_candidate_key_org_id_student',
                'table' => 'course_enrollments',
                'columns' => ['organization_id', 'id', 'student_id'],
            ],
            [
                'name' => 'training_session_candidate_key_org_id',
                'table' => 'training_sessions',
                'columns' => ['organization_id', 'id'],
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
