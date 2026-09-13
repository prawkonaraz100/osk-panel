<?php

use App\Support\Migrations\CandidateKeyWriteFence;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-IDENTITY');

        CandidateKeyWriteFence::install('MIG-CK-IDENTITY', [
            [
                'name' => 'organization_membership_candidate_key_id_user',
                'table' => 'organization_memberships',
                'columns' => ['id', 'user_id'],
            ],
            [
                'name' => 'organization_membership_candidate_key_org_id',
                'table' => 'organization_memberships',
                'columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'organization_membership_candidate_key_org_id_user',
                'table' => 'organization_memberships',
                'columns' => ['organization_id', 'id', 'user_id'],
            ],
            [
                'name' => 'auth_login_identifier_candidate_key_id_user',
                'table' => 'auth_login_identifiers',
                'columns' => ['id', 'user_id'],
            ]
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
