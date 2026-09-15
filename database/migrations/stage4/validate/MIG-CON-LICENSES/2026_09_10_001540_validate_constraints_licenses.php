<?php

use App\Support\Migrations\ConstraintWriteFence;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'MIG-CON-LICENSES');

        ConstraintWriteFence::validate('MIG-CON-LICENSES', [
            [
                'name' => 'learning_account_closed_state',
                'table' => 'student_learning_accounts',
                'columns' => ['status', 'version'],
                'predicate' => 'src.status IN (\'active\',\'suspended\') AND src.version >= 1',
            ],
            [
                'name' => 'license_inventory_closed_state_and_purchase_pair',
                'table' => 'license_inventory_entries',
                'columns' => ['status', 'source_order_item_id', 'source_order_item_grant_ordinal'],
                'predicate' => 'src.status IN (\'available\',\'assigned\',\'consumed\',\'expired\',\'adjusted\') AND ((src.source_order_item_id IS NULL AND src.source_order_item_grant_ordinal IS NULL) OR (src.source_order_item_id IS NOT NULL AND src.source_order_item_grant_ordinal IS NOT NULL AND src.source_order_item_grant_ordinal >= 1))',
            ],
            [
                'name' => 'license_assignment_closed_state',
                'table' => 'license_assignments',
                'columns' => ['assignment_sequence', 'status', 'version', 'revoked_at', 'revoked_by_user_id', 'revoke_reason'],
                'predicate' => 'src.assignment_sequence >= 1 AND src.version >= 1 AND src.status IN (\'assigned\',\'activated\',\'revoked_before_activation\') AND ((src.status IN (\'assigned\',\'activated\') AND src.revoked_at IS NULL AND src.revoked_by_user_id IS NULL AND src.revoke_reason IS NULL) OR (src.status = \'revoked_before_activation\' AND src.revoked_at IS NOT NULL AND src.revoked_by_user_id IS NOT NULL AND NULLIF(BTRIM(src.revoke_reason),\'\') IS NOT NULL))',
            ],
            [
                'name' => 'license_activation_period',
                'table' => 'license_activations',
                'columns' => ['entitlement_sequence', 'duration_days_snapshot', 'effective_from', 'effective_to'],
                'predicate' => 'src.entitlement_sequence >= 1 AND src.duration_days_snapshot > 0 AND src.effective_to > src.effective_from',
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
