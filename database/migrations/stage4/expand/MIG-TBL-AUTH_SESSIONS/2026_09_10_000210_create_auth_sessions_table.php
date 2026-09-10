<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-AUTH_SESSIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-AUTH_SESSIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('auth_sessions')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE auth_sessions (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL,
    organization_membership_id uuid NULL,
    token_or_framework_session_hash varchar(255) NOT NULL,
    created_at timestamptz NOT NULL,
    last_seen_at timestamptz NULL,
    revoked_at timestamptz NULL,
    revoke_reason varchar(255) NULL,
    ip_hash varchar(128) NULL,
    user_agent varchar(512) NULL
)
SQL);

        if (! Schema::hasTable('auth_sessions')) {
            throw new LogicException('MIG-TBL-AUTH_SESSIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
