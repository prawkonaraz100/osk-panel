<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-INTERNAL_EXAM_RESERVATIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_RESERVATIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('internal_exam_reservations')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE internal_exam_reservations (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    internal_exam_inventory_entry_id uuid NOT NULL,
    internal_exam_attempt_id uuid NOT NULL,
    status varchar(32) NOT NULL,
    version bigint NOT NULL DEFAULT 1,
    reserved_at timestamptz NOT NULL,
    released_at timestamptz NULL,
    consumed_at timestamptz NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('internal_exam_reservations')) {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_RESERVATIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
