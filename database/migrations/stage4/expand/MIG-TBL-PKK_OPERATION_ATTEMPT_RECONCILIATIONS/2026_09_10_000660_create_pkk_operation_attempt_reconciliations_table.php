<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PKK_OPERATION_ATTEMPT_RECONCILIATIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PKK_OPERATION_ATTEMPT_RECONCILIATIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('pkk_operation_attempt_reconciliations')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE pkk_operation_attempt_reconciliations (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    pkk_operation_id uuid NOT NULL,
    pkk_operation_attempt_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    pkk_profile_id uuid NOT NULL,
    reconciliation_sequence bigint NOT NULL,
    outcome varchar(64) NOT NULL,
    provider_reference varchar(255) NULL,
    evidence_hash char(64) NOT NULL,
    safe_summary jsonb NULL,
    actor_kind varchar(32) NOT NULL,
    actor_user_id uuid NULL,
    performed_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('pkk_operation_attempt_reconciliations')) {
            throw new LogicException('MIG-TBL-PKK_OPERATION_ATTEMPT_RECONCILIATIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
