<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PKK_PROTECTED_PAYLOADS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PKK_PROTECTED_PAYLOADS requires PostgreSQL.');
        }

        if (Schema::hasTable('pkk_protected_payloads')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE pkk_protected_payloads (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    payload_role varchar(64) NOT NULL,
    retention_mode varchar(64) NOT NULL,
    retention_policy_version varchar(64) NOT NULL,
    provider_profile_snapshot_id uuid NULL,
    pkk_operation_id uuid NULL,
    pkk_operation_attempt_id uuid NULL,
    reconciliation_id uuid NULL,
    canonical_plaintext_sha256 char(64) NOT NULL,
    crypto_profile_version integer NOT NULL,
    aead_algorithm_code varchar(64) NOT NULL,
    aad_schema_version integer NOT NULL,
    nonce bytea NOT NULL,
    ciphertext bytea NOT NULL,
    auth_tag bytea NOT NULL,
    ciphertext_sha256 char(64) NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('pkk_protected_payloads')) {
            throw new LogicException('MIG-TBL-PKK_PROTECTED_PAYLOADS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
