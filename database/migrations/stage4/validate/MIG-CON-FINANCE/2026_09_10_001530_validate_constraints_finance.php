<?php

use App\Support\Migrations\ConstraintWriteFence;
use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'MIG-CON-FINANCE');

        ConstraintWriteFence::validate('MIG-CON-FINANCE', [
            [
                'name' => 'student_charge_cancellation_tuple',
                'table' => 'student_charges',
                'columns' => ['cancelled_at', 'cancelled_by_user_id', 'cancellation_reason'],
                'predicate' => '(src.cancelled_at IS NULL AND src.cancelled_by_user_id IS NULL AND src.cancellation_reason IS NULL) OR (src.cancelled_at IS NOT NULL AND src.cancelled_by_user_id IS NOT NULL AND NULLIF(BTRIM(src.cancellation_reason),\'\') IS NOT NULL)',
            ],
            [
                'name' => 'student_payment_positive_and_reversal_tuple',
                'table' => 'student_payments',
                'columns' => ['amount_minor', 'reversed_at', 'reversed_by_user_id', 'reversal_reason'],
                'predicate' => 'src.amount_minor > 0 AND ((src.reversed_at IS NULL AND src.reversed_by_user_id IS NULL AND src.reversal_reason IS NULL) OR (src.reversed_at IS NOT NULL AND src.reversed_by_user_id IS NOT NULL AND NULLIF(BTRIM(src.reversal_reason),\'\') IS NOT NULL))',
            ],
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
