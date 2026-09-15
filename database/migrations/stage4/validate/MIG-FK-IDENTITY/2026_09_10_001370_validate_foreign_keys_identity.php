<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\ForeignKeyWriteFence;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'MIG-FK-IDENTITY');

        ForeignKeyWriteFence::validate('MIG-FK-IDENTITY', [
            [
                'name' => 'organization_membership_organization',
                'source_table' => 'organization_memberships',
                'source_columns' => ['organization_id'],
                'target_table' => 'organizations',
                'target_columns' => ['id'],
            ],
            [
                'name' => 'organization_membership_user',
                'source_table' => 'organization_memberships',
                'source_columns' => ['user_id'],
                'target_table' => 'users',
                'target_columns' => ['id'],
            ],
            [
                'name' => 'auth_session_user',
                'source_table' => 'auth_sessions',
                'source_columns' => ['user_id'],
                'target_table' => 'users',
                'target_columns' => ['id'],
            ],
            [
                'name' => 'auth_session_exact_membership_user',
                'source_table' => 'auth_sessions',
                'source_columns' => ['organization_membership_id', 'user_id'],
                'target_table' => 'organization_memberships',
                'target_columns' => ['id', 'user_id'],
            ],
            [
                'name' => 'membership_permission_membership',
                'source_table' => 'membership_permissions',
                'source_columns' => ['membership_id'],
                'target_table' => 'organization_memberships',
                'target_columns' => ['id'],
            ],
            [
                'name' => 'membership_permission_catalog',
                'source_table' => 'membership_permissions',
                'source_columns' => ['permission_code'],
                'target_table' => 'permissions',
                'target_columns' => ['code'],
            ],
            [
                'name' => 'permission_scope_option_permission',
                'source_table' => 'permission_scope_options',
                'source_columns' => ['permission_code'],
                'target_table' => 'permissions',
                'target_columns' => ['code'],
            ],
            [
                'name' => 'permission_scope_option_scope',
                'source_table' => 'permission_scope_options',
                'source_columns' => ['scope_code'],
                'target_table' => 'data_scopes',
                'target_columns' => ['code'],
            ],
            [
                'name' => 'membership_permission_scope_decision',
                'source_table' => 'membership_permission_scopes',
                'source_columns' => ['membership_id', 'permission_code'],
                'target_table' => 'membership_permissions',
                'target_columns' => ['membership_id', 'permission_code'],
            ],
            [
                'name' => 'membership_permission_scope_option',
                'source_table' => 'membership_permission_scopes',
                'source_columns' => ['permission_code', 'scope_code'],
                'target_table' => 'permission_scope_options',
                'target_columns' => ['permission_code', 'scope_code'],
            ],
            [
                'name' => 'terms_acceptance_organization',
                'source_table' => 'terms_acceptances',
                'source_columns' => ['organization_id'],
                'target_table' => 'organizations',
                'target_columns' => ['id'],
            ],
            [
                'name' => 'terms_acceptance_user',
                'source_table' => 'terms_acceptances',
                'source_columns' => ['user_id'],
                'target_table' => 'users',
                'target_columns' => ['id'],
            ],
            [
                'name' => 'terms_acceptance_legal_document',
                'source_table' => 'terms_acceptances',
                'source_columns' => ['legal_document_id'],
                'target_table' => 'legal_documents',
                'target_columns' => ['id'],
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
