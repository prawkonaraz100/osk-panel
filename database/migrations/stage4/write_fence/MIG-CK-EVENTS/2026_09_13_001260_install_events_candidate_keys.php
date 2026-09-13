<?php

use App\Support\Migrations\CandidateKeyMigrationSupport;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use LogicException;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-EVENTS');

        CandidateKeyMigrationSupport::install(
            'MIG-CK-EVENTS',
            [
            [
                'name' => 'ck_audit_logs_org_id',
                'table' => 'audit_logs',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['id'],
            ],
            [
                'name' => 'ck_domain_events_org_id',
                'table' => 'domain_events',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['id'],
            ]
            ],
        );
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for Stage-4 candidate-key write fences.');
    }
};
