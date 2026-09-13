<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\ForeignKeyPreflight;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-FK-LICENSES');

        ForeignKeyPreflight::assertRelations('MIG-FK-LICENSES', [
            [
                'name' => 'handoff_learning_account',
                'source_table' => 'student_access_handoffs',
                'source_columns' => ['organization_id', 'student_learning_account_id'],
                'target_table' => 'student_learning_accounts',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'handoff_document_asset',
                'source_table' => 'student_access_handoffs',
                'source_columns' => ['organization_id', 'document_asset_id'],
                'target_table' => 'file_assets',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'assignment_inventory',
                'source_table' => 'license_assignments',
                'source_columns' => ['organization_id', 'license_inventory_entry_id'],
                'target_table' => 'license_inventory_entries',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'assignment_student',
                'source_table' => 'license_assignments',
                'source_columns' => ['organization_id', 'student_id'],
                'target_table' => 'students',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'assignment_exact_account_student',
                'source_table' => 'license_assignments',
                'source_columns' => ['organization_id', 'student_learning_account_id', 'student_id'],
                'target_table' => 'student_learning_accounts',
                'target_columns' => ['organization_id', 'id', 'student_id'],
            ],
            [
                'name' => 'assignment_capability_language',
                'source_table' => 'license_assignments',
                'source_columns' => ['license_product_language_capability_id', 'language_code'],
                'target_table' => 'license_product_language_capabilities',
                'target_columns' => ['id', 'language_code'],
            ],
            [
                'name' => 'activation_assignment',
                'source_table' => 'license_activations',
                'source_columns' => ['organization_id', 'license_assignment_id'],
                'target_table' => 'license_assignments',
                'target_columns' => ['organization_id', 'id'],
            ],
            [
                'name' => 'activation_learning_account',
                'source_table' => 'license_activations',
                'source_columns' => ['organization_id', 'student_learning_account_id'],
                'target_table' => 'student_learning_accounts',
                'target_columns' => ['organization_id', 'id'],
            ]
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
