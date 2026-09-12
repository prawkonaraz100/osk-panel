<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-ORDER_PAYMENT_SETTLEMENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-ORDER_PAYMENT_SETTLEMENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('order_payment_settlements')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE order_payment_settlements (
    organization_id uuid NOT NULL,
    order_id uuid NOT NULL,
    payment_id uuid NOT NULL,
    settled_at timestamptz NOT NULL,
    confirmation_source varchar(32) NOT NULL,
    source_payment_event_id uuid NULL,
    reconciled_by_user_id uuid NULL,
    reconciliation_reason text NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('order_payment_settlements')) {
            throw new LogicException('MIG-TBL-ORDER_PAYMENT_SETTLEMENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
