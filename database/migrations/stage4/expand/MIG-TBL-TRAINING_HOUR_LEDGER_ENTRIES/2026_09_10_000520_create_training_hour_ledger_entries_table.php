<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-TRAINING_HOUR_LEDGER_ENTRIES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-TRAINING_HOUR_LEDGER_ENTRIES requires PostgreSQL.');
        }

        if (Schema::hasTable('training_hour_ledger_entries')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE training_hour_ledger_entries (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    training_session_id uuid NULL,
    entry_type varchar(32) NOT NULL,
    training_part varchar(32) NOT NULL,
    minutes integer NOT NULL,
    source_entry_id uuid NULL,
    reason text NULL,
    actor_user_id uuid NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('training_hour_ledger_entries')) {
            throw new LogicException('MIG-TBL-TRAINING_HOUR_LEDGER_ENTRIES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
