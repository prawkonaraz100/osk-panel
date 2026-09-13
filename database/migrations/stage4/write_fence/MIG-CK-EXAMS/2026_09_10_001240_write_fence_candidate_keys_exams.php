<?php

use App\Support\Migrations\CandidateKeyWriteFence;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-EXAMS');

        CandidateKeyWriteFence::install('MIG-CK-EXAMS', [
            [
                'name' => 'internal_exam_inventory_entry_candidate_key_org_id',
                'table' => 'internal_exam_inventory_entries',
                'columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'internal_exam_attempt_candidate_key_org_id',
                'table' => 'internal_exam_attempts',
                'columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'internal_exam_access_candidate_key_org_id',
                'table' => 'internal_exam_accesses',
                'columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'exam_station_candidate_key_org_id',
                'table' => 'exam_stations',
                'columns' => ['organization_id', 'id'],
            ]
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
