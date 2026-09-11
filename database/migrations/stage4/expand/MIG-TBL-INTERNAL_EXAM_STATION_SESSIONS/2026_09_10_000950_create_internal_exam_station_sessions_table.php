<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-INTERNAL_EXAM_STATION_SESSIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_STATION_SESSIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('internal_exam_station_sessions')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE internal_exam_station_sessions (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    internal_exam_attempt_id uuid NOT NULL,
    internal_exam_access_id uuid NOT NULL,
    exam_station_id uuid NOT NULL,
    session_sequence bigint NOT NULL,
    transferred_from_session_id uuid NULL,
    started_at timestamptz NOT NULL,
    ended_at timestamptz NULL,
    end_reason varchar(32) NULL,
    created_by_user_id uuid NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('internal_exam_station_sessions')) {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_STATION_SESSIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
