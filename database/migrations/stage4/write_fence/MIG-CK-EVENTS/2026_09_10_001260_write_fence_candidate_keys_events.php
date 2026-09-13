<?php

use App\Support\Migrations\CandidateKeyWriteFence;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-EVENTS');

        CandidateKeyWriteFence::install('MIG-CK-EVENTS', [
            [
                'name' => 'audit_log_candidate_key_org_id',
                'table' => 'audit_logs',
                'columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'domain_event_candidate_key_org_id',
                'table' => 'domain_events',
                'columns' => ['organization_id', 'id'],
            ]
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
