<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\ForeignKeyWriteFence;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-FK-FINANCE');

        ForeignKeyWriteFence::install('MIG-FK-FINANCE', [
            [
                'name' => 'charge_student',
                'source_table' => 'student_charges',
                'source_columns' => ['organization_id', 'student_id'],
                'target_table' => 'students',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'charge_exact_course_student',
                'source_table' => 'student_charges',
                'source_columns' => ['organization_id', 'course_enrollment_id', 'student_id'],
                'target_table' => 'course_enrollments',
                'target_columns' => ['organization_id', 'id', 'student_id'],
            ],
            [
                'name' => 'payment_student',
                'source_table' => 'student_payments',
                'source_columns' => ['organization_id', 'student_id'],
                'target_table' => 'students',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'payment_exact_charge_student_currency',
                'source_table' => 'student_payments',
                'source_columns' => ['organization_id', 'charge_id', 'student_id', 'currency'],
                'target_table' => 'student_charges',
                'target_columns' => ['organization_id', 'id', 'student_id', 'currency'],
            ],
            [
                'name' => 'course_cost_origin_exact_course_student',
                'source_table' => 'course_cost_charge_origins',
                'source_columns' => ['organization_id', 'course_enrollment_id', 'student_id'],
                'target_table' => 'course_enrollments',
                'target_columns' => ['organization_id', 'id', 'student_id'],
            ],
            [
                'name' => 'course_cost_origin_exact_charge',
                'source_table' => 'course_cost_charge_origins',
                'source_columns' => ['organization_id', 'student_charge_id', 'student_id', 'source_currency'],
                'target_table' => 'student_charges',
                'target_columns' => ['organization_id', 'id', 'student_id', 'currency'],
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
