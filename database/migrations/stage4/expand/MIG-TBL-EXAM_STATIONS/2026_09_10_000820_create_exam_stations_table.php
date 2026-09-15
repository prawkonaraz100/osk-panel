<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-EXAM_STATIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-EXAM_STATIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('exam_stations')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE exam_stations (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    administrative_status varchar(16) NOT NULL,
    last_authenticated_heartbeat_at timestamptz NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('exam_stations')) {
            throw new LogicException('MIG-TBL-EXAM_STATIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
