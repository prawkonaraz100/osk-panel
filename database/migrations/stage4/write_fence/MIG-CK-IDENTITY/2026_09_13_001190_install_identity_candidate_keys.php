<?php

use App\Support\Migrations\CandidateKeyMigrationSupport;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use LogicException;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-CK-IDENTITY');

        CandidateKeyMigrationSupport::install(
            'MIG-CK-IDENTITY',
            [
            [
                'name' => 'ck_org_memberships_id_user',
                'table' => 'organization_memberships',
                'columns' => ['id', 'user_id'],
                'required_not_null' => ['id', 'user_id'],
            ],
            [
                'name' => 'ck_org_memberships_org_id',
                'table' => 'organization_memberships',
                'columns' => ['organization_id', 'id'],
                'required_not_null' => ['organization_id', 'id'],
            ],
            [
                'name' => 'ck_org_memberships_org_id_user',
                'table' => 'organization_memberships',
                'columns' => ['organization_id', 'id', 'user_id'],
                'required_not_null' => ['organization_id', 'id', 'user_id'],
            ],
            [
                'name' => 'ck_auth_login_identifiers_id_user',
                'table' => 'auth_login_identifiers',
                'columns' => ['id', 'user_id'],
                'required_not_null' => ['id', 'user_id'],
            ]
            ],
        );
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for Stage-4 candidate-key write fences.');
    }
};
