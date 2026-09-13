<?php

use App\Support\Migrations\CandidateKeyWriteFence;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-FINANCE');

        CandidateKeyWriteFence::install('MIG-CK-FINANCE', [
            [
                'name' => 'student_charge_candidate_key_org_id_student_currency',
                'table' => 'student_charges',
                'columns' => ['organization_id', 'id', 'student_id', 'currency'],
            ]
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
