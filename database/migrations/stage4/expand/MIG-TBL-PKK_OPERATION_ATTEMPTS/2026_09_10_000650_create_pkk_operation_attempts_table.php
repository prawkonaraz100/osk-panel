<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PKK_OPERATION_ATTEMPTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PKK_OPERATION_ATTEMPTS requires PostgreSQL.');
        }

        if (Schema::hasTable('pkk_operation_attempts')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE pkk_operation_attempts (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    pkk_operation_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    pkk_profile_id uuid NOT NULL,
    attempt_no bigint NOT NULL,
    command_idempotency_record_id uuid NULL,
    integration_configuration_revision bigint NOT NULL,
    signature_handoff_id uuid NULL,
    attempt_dispatch_status varchar(64) NOT NULL,
    retry_disposition varchar(64) NOT NULL,
    provider_request_id varchar(255) NULL,
    provider_neutral_error_class varchar(64) NULL,
    adapter_error_code varchar(128) NULL,
    adapter_error_message text NULL,
    dispatch_started_at timestamptz NULL,
    transport_finished_at timestamptz NULL,
    resolved_at timestamptz NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('pkk_operation_attempts')) {
            throw new LogicException('MIG-TBL-PKK_OPERATION_ATTEMPTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
