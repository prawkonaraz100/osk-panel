<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-INTERNAL_EXAM_ACCESS_TOKENS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_ACCESS_TOKENS requires PostgreSQL.');
        }

        if (Schema::hasTable('internal_exam_access_tokens')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE internal_exam_access_tokens (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    internal_exam_access_id uuid NOT NULL,
    internal_exam_attempt_id uuid NOT NULL,
    purpose varchar(32) NOT NULL,
    token_sequence bigint NOT NULL,
    lookup_id uuid NOT NULL,
    secret_verifier char(64) NOT NULL,
    verifier_key_version integer NOT NULL,
    issued_at timestamptz NOT NULL,
    expires_at timestamptz NOT NULL,
    revoked_at timestamptz NULL,
    revoke_reason_code varchar(64) NULL,
    issued_by_user_id uuid NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('internal_exam_access_tokens')) {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_ACCESS_TOKENS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
