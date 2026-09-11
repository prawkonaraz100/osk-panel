<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-INTERNAL_EXAM_INVENTORY_ADJUSTMENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_INVENTORY_ADJUSTMENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('internal_exam_inventory_adjustments')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE internal_exam_inventory_adjustments (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    delta integer NOT NULL,
    reason text NOT NULL,
    related_attempt_id uuid NULL,
    created_by_user_id uuid NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('internal_exam_inventory_adjustments')) {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_INVENTORY_ADJUSTMENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
