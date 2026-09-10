<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-AUTH_LOGIN_IDENTIFIERS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-AUTH_LOGIN_IDENTIFIERS requires PostgreSQL.');
        }

        if (Schema::hasTable('auth_login_identifiers')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE auth_login_identifiers (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL,
    identifier_type varchar(32) NOT NULL,
    identifier_normalized varchar(320) NOT NULL,
    is_primary_for_type boolean NOT NULL DEFAULT false,
    verified_at timestamptz NULL,
    created_at timestamptz NOT NULL,
    revoked_at timestamptz NULL
)
SQL);

        if (! Schema::hasTable('auth_login_identifiers')) {
            throw new LogicException('MIG-TBL-AUTH_LOGIN_IDENTIFIERS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
