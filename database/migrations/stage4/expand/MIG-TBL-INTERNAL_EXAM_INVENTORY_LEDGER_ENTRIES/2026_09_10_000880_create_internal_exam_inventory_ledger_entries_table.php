<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-INTERNAL_EXAM_INVENTORY_LEDGER_ENTRIES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_INVENTORY_LEDGER_ENTRIES requires PostgreSQL.');
        }

        if (Schema::hasTable('internal_exam_inventory_ledger_entries')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE internal_exam_inventory_ledger_entries (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    internal_exam_inventory_entry_id uuid NOT NULL,
    internal_exam_reservation_id uuid NULL,
    internal_exam_attempt_id uuid NULL,
    internal_exam_inventory_adjustment_id uuid NULL,
    event_sequence bigint NOT NULL,
    event_type varchar(32) NOT NULL,
    available_delta smallint NOT NULL,
    actor_user_id uuid NULL,
    reason text NULL,
    occurred_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('internal_exam_inventory_ledger_entries')) {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_INVENTORY_LEDGER_ENTRIES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
