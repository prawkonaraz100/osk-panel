<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-TRAINING_SESSION_CALENDAR_DETAILS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-TRAINING_SESSION_CALENDAR_DETAILS requires PostgreSQL.');
        }

        if (Schema::hasTable('training_session_calendar_details')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE training_session_calendar_details (
    organization_id uuid NOT NULL,
    training_session_id uuid NOT NULL,
    display_name text NULL,
    custom_meeting_place text NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('training_session_calendar_details')) {
            throw new LogicException('MIG-TBL-TRAINING_SESSION_CALENDAR_DETAILS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
