<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-INTERNAL_EXAM_INVENTORY_ENTRIES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_INVENTORY_ENTRIES requires PostgreSQL.');
        }

        if (Schema::hasTable('internal_exam_inventory_entries')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE internal_exam_inventory_entries (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    source_type varchar(32) NOT NULL,
    source_order_item_id uuid NULL,
    source_order_item_grant_ordinal integer NULL,
    source_adjustment_id uuid NULL,
    current_state varchar(32) NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('internal_exam_inventory_entries')) {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_INVENTORY_ENTRIES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
