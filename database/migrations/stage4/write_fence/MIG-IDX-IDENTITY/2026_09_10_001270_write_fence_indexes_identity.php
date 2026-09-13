<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\IndexWriteFence;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('write_fence', 'MIG-IDX-IDENTITY');

        IndexWriteFence::installUniqueIndexes('MIG-IDX-IDENTITY', [
            [
                'name' => 'auth_login_identifier_global_current_unique',
                'table' => 'auth_login_identifiers',
                'columns' => ['identifier_normalized'],
                'predicate' => 'revoked_at IS NULL',
            ],
            [
                'name' => 'auth_login_identifier_primary_current_unique_per_user_type',
                'table' => 'auth_login_identifiers',
                'columns' => ['user_id', 'identifier_type'],
                'predicate' => 'revoked_at IS NULL AND is_primary_for_type = true',
            ],
            [
                'name' => 'organization_membership_unique_org_user',
                'table' => 'organization_memberships',
                'columns' => ['organization_id', 'user_id'],
                'predicate' => null,
            ],
            [
                'name' => 'membership_permission_unique',
                'table' => 'membership_permissions',
                'columns' => ['membership_id', 'permission_code'],
                'predicate' => null,
            ],
            [
                'name' => 'membership_permission_scope_unique',
                'table' => 'membership_permission_scopes',
                'columns' => ['membership_id', 'permission_code', 'scope_code'],
                'predicate' => null,
            ],
            [
                'name' => 'account_closure_pending_unique_organization_scope',
                'table' => 'account_closure_requests',
                'columns' => ['user_id', 'organization_id'],
                'predicate' => 'status = \'pending\' AND organization_id IS NOT NULL',
            ],
            [
                'name' => 'account_closure_pending_unique_global_scope',
                'table' => 'account_closure_requests',
                'columns' => ['user_id'],
                'predicate' => 'status = \'pending\' AND organization_id IS NULL',
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
