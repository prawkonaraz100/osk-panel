<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PKK_PAYLOAD_REDACTED_PROJECTIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PKK_PAYLOAD_REDACTED_PROJECTIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('pkk_payload_redacted_projections')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE pkk_payload_redacted_projections (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    payload_role varchar(64) NOT NULL,
    provider_profile_snapshot_id uuid NULL,
    pkk_operation_id uuid NULL,
    pkk_operation_attempt_id uuid NULL,
    reconciliation_id uuid NULL,
    protected_payload_id uuid NULL,
    projection_revision bigint NOT NULL,
    redaction_policy_code varchar(64) NOT NULL,
    redaction_policy_version varchar(64) NOT NULL,
    retention_policy_version varchar(64) NOT NULL,
    source_plaintext_sha256 char(64) NOT NULL,
    redacted_jsonb jsonb NOT NULL,
    projection_sha256 char(64) NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('pkk_payload_redacted_projections')) {
            throw new LogicException('MIG-TBL-PKK_PAYLOAD_REDACTED_PROJECTIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
