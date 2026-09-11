<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-LICENSE_INVENTORY_ENTRIES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-LICENSE_INVENTORY_ENTRIES requires PostgreSQL.');
        }

        if (Schema::hasTable('license_inventory_entries')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE license_inventory_entries (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    license_product_id uuid NOT NULL,
    source_order_item_id uuid NULL,
    source_order_item_grant_ordinal integer NULL,
    status varchar(32) NOT NULL,
    granted_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('license_inventory_entries')) {
            throw new LogicException('MIG-TBL-LICENSE_INVENTORY_ENTRIES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
