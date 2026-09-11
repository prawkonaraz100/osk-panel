<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-INTERNAL_EXAM_ACCESSES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_ACCESSES requires PostgreSQL.');
        }

        if (Schema::hasTable('internal_exam_accesses')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE internal_exam_accesses (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    internal_exam_attempt_id uuid NOT NULL,
    launch_mode varchar(32) NOT NULL,
    station_id uuid NULL,
    status varchar(32) NOT NULL,
    version bigint NOT NULL DEFAULT 1,
    expires_at timestamptz NULL,
    ready_at timestamptz NULL,
    delivered_or_assigned_at timestamptz NULL,
    opened_at timestamptz NULL,
    started_at timestamptz NULL,
    completed_at timestamptz NULL,
    cancelled_at timestamptz NULL,
    expired_at timestamptz NULL,
    revoked_at timestamptz NULL,
    technical_aborted_at timestamptz NULL,
    invalidated_at timestamptz NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('internal_exam_accesses')) {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_ACCESSES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
