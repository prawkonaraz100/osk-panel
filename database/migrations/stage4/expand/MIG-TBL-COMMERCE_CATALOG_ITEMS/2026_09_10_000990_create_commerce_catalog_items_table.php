<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-COMMERCE_CATALOG_ITEMS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-COMMERCE_CATALOG_ITEMS requires PostgreSQL.');
        }

        if (Schema::hasTable('commerce_catalog_items')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE commerce_catalog_items (
    id uuid PRIMARY KEY,
    code varchar(128) NOT NULL,
    product_kind varchar(32) NOT NULL,
    license_product_id uuid NULL,
    active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('commerce_catalog_items')) {
            throw new LogicException('MIG-TBL-COMMERCE_CATALOG_ITEMS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
