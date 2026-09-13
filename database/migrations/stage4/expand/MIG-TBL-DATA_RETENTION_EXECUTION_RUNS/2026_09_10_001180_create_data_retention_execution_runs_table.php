<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-DATA_RETENTION_EXECUTION_RUNS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-DATA_RETENTION_EXECUTION_RUNS requires PostgreSQL.');
        }

        if (Schema::hasTable('data_retention_execution_runs')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE data_retention_execution_runs (
    id uuid PRIMARY KEY,
    policy_version_reference varchar(128) NOT NULL,
    data_class varchar(128) NOT NULL,
    cutoff_at timestamptz NOT NULL,
    organization_id uuid NULL,
    initiated_by_user_id uuid NULL,
    reason text NOT NULL,
    candidate_count bigint NOT NULL,
    deleted_or_redacted_count bigint NOT NULL,
    skipped_hold_count bigint NOT NULL,
    started_at timestamptz NOT NULL,
    completed_at timestamptz NULL,
    result varchar(64) NOT NULL
)
SQL);

        if (! Schema::hasTable('data_retention_execution_runs')) {
            throw new LogicException('MIG-TBL-DATA_RETENTION_EXECUTION_RUNS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
