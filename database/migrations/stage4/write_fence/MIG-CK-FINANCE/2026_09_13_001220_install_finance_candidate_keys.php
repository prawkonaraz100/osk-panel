<?php

use App\Support\Migrations\CandidateKeyMigrationSupport;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use LogicException;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-FINANCE');

        CandidateKeyMigrationSupport::install(
            'MIG-CK-FINANCE',
            [
            [
                'name' => 'ck_student_charges_org_id_student_currency',
                'table' => 'student_charges',
                'columns' => ['organization_id', 'id', 'student_id', 'currency'],
                'required_not_null' => ['organization_id', 'id', 'student_id', 'currency'],
            ]
            ],
        );
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for Stage-4 candidate-key write fences.');
    }
};
