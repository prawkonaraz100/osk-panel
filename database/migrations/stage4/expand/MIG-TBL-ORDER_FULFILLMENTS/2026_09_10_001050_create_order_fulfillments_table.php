<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-ORDER_FULFILLMENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-ORDER_FULFILLMENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('order_fulfillments')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE order_fulfillments (
    organization_id uuid NOT NULL,
    order_id uuid NOT NULL,
    source_kind varchar(32) NOT NULL,
    settlement_payment_id uuid NULL,
    state varchar(32) NOT NULL,
    created_at timestamptz NOT NULL,
    fulfilled_at timestamptz NULL,
    requires_reconciliation_at timestamptz NULL,
    reconciliation_reason text NULL
)
SQL);

        if (! Schema::hasTable('order_fulfillments')) {
            throw new LogicException('MIG-TBL-ORDER_FULFILLMENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
