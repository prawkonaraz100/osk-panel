<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-AUTH_SOCIAL_ACCOUNTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-AUTH_SOCIAL_ACCOUNTS requires PostgreSQL.');
        }

        if (Schema::hasTable('auth_social_accounts')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE auth_social_accounts (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL,
    provider varchar(64) NOT NULL,
    provider_subject varchar(255) NOT NULL,
    created_at timestamptz NOT NULL,
    revoked_at timestamptz NULL
)
SQL);

        if (! Schema::hasTable('auth_social_accounts')) {
            throw new LogicException('MIG-TBL-AUTH_SOCIAL_ACCOUNTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
