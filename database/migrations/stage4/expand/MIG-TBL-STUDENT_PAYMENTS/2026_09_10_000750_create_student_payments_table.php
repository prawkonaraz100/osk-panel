<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-STUDENT_PAYMENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-STUDENT_PAYMENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('student_payments')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE student_payments (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    student_id uuid NOT NULL,
    charge_id uuid NOT NULL,
    amount_minor bigint NOT NULL,
    currency char(3) NOT NULL,
    paid_at timestamptz NOT NULL,
    payment_method varchar(64) NULL,
    note text NULL,
    received_by_user_id uuid NOT NULL,
    reversed_at timestamptz NULL,
    reversed_by_user_id uuid NULL,
    reversal_reason text NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('student_payments')) {
            throw new LogicException('MIG-TBL-STUDENT_PAYMENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
