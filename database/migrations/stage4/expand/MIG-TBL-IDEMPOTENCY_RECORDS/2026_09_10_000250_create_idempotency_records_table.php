<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-IDEMPOTENCY_RECORDS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-IDEMPOTENCY_RECORDS requires PostgreSQL.');
        }

        if (Schema::hasTable('idempotency_records')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE idempotency_records (
    id uuid PRIMARY KEY,
    organization_id uuid NULL,
    operation_key varchar(128) NOT NULL,
    idempotency_key uuid NOT NULL,
    request_hash char(64) NOT NULL,
    status varchar(32) NOT NULL,
    result_resource_type varchar(128) NULL,
    result_resource_id varchar(128) NULL,
    response_status smallint NULL,
    safe_response_snapshot jsonb NULL,
    created_at timestamptz NOT NULL,
    completed_at timestamptz NULL,
    expires_at timestamptz NULL
)
SQL);

        if (! Schema::hasTable('idempotency_records')) {
            throw new LogicException('MIG-TBL-IDEMPOTENCY_RECORDS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
