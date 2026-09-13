<?php

use App\Support\Migrations\CandidateKeyMigrationSupport;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use LogicException;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-CK-TRAINING');

        CandidateKeyMigrationSupport::preflight(
            'MIG-CK-TRAINING',
            [
            [
                'name' => 'ck_students_org_id',
                'table' => 'students',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['organization_id', 'id'],
            ],
            [
                'name' => 'ck_learning_accounts_org_id',
                'table' => 'student_learning_accounts',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['organization_id', 'id'],
            ],
            [
                'name' => 'ck_learning_accounts_org_id_student',
                'table' => 'student_learning_accounts',
                'columns' => ['organization_id', 'id', 'student_id'],
                'required_not_null' => ['organization_id', 'id', 'student_id'],
            ],
            [
                'name' => 'ck_course_enrollments_org_id',
                'table' => 'course_enrollments',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['organization_id', 'id'],
            ],
            [
                'name' => 'ck_course_enrollments_org_id_student',
                'table' => 'course_enrollments',
                'columns' => ['organization_id', 'id', 'student_id'],
                'required_not_null' => ['organization_id', 'id', 'student_id'],
            ],
            [
                'name' => 'ck_training_sessions_org_id',
                'table' => 'training_sessions',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['organization_id', 'id'],
            ]
            ],
        );
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for Stage-4 candidate-key preflight.');
    }
};
