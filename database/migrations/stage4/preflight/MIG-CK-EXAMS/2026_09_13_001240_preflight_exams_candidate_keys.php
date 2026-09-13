<?php

use App\Support\Migrations\CandidateKeyMigrationSupport;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use LogicException;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-CK-EXAMS');

        CandidateKeyMigrationSupport::preflight(
            'MIG-CK-EXAMS',
            [
            [
                'name' => 'ck_exam_inventory_org_id',
                'table' => 'internal_exam_inventory_entries',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['organization_id', 'id'],
            ],
            [
                'name' => 'ck_exam_attempts_org_id',
                'table' => 'internal_exam_attempts',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['organization_id', 'id'],
            ],
            [
                'name' => 'ck_exam_accesses_org_id',
                'table' => 'internal_exam_accesses',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['organization_id', 'id'],
            ],
            [
                'name' => 'ck_exam_stations_org_id',
                'table' => 'exam_stations',
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
