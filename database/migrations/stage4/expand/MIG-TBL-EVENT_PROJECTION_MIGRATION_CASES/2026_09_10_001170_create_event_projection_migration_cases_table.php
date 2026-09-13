<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-EVENT_PROJECTION_MIGRATION_CASES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-EVENT_PROJECTION_MIGRATION_CASES requires PostgreSQL.');
        }

        if (Schema::hasTable('event_projection_migration_cases')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE event_projection_migration_cases (
    id uuid PRIMARY KEY,
    source_table varchar(128) NOT NULL,
    source_row_id varchar(128) NOT NULL,
    source_row_fingerprint char(64) NOT NULL,
    issue_code varchar(128) NOT NULL,
    evidence_class varchar(64) NOT NULL,
    resolution_state varchar(64) NOT NULL,
    evidence_reference_json_safe jsonb NULL,
    resolution_kind varchar(64) NULL,
    resolution_reason text NULL,
    reviewed_by_user_id uuid NULL,
    reviewed_at timestamptz NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('event_projection_migration_cases')) {
            throw new LogicException('MIG-TBL-EVENT_PROJECTION_MIGRATION_CASES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
