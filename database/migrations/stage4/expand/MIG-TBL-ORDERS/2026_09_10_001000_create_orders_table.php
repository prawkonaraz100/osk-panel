<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-ORDERS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-ORDERS requires PostgreSQL.');
        }

        if (Schema::hasTable('orders')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE orders (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    order_sequence bigint NOT NULL,
    ordered_at timestamptz NOT NULL,
    booked_at timestamptz NULL,
    zero_total_settled_at timestamptz NULL,
    total_amount_minor bigint NOT NULL,
    currency char(3) NOT NULL,
    created_by_user_id uuid NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('orders')) {
            throw new LogicException('MIG-TBL-ORDERS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
