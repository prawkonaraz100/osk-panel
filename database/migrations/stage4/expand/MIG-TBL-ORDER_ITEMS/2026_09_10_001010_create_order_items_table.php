<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-ORDER_ITEMS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-ORDER_ITEMS requires PostgreSQL.');
        }

        if (Schema::hasTable('order_items')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE order_items (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    order_id uuid NOT NULL,
    commerce_catalog_item_id uuid NOT NULL,
    product_kind varchar(32) NOT NULL,
    quantity integer NOT NULL,
    currency char(3) NOT NULL,
    list_unit_amount_minor bigint NOT NULL,
    unit_amount_minor bigint NOT NULL,
    unit_discount_amount_minor bigint NOT NULL,
    vat_rate_basis_points integer NOT NULL,
    total_amount_minor bigint NOT NULL,
    product_snapshot jsonb NOT NULL,
    pricing_snapshot jsonb NOT NULL,
    snapshot_hash char(64) NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('order_items')) {
            throw new LogicException('MIG-TBL-ORDER_ITEMS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
