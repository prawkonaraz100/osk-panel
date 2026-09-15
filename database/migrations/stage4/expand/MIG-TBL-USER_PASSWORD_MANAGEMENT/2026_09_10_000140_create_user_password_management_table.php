<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-USER_PASSWORD_MANAGEMENT');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-USER_PASSWORD_MANAGEMENT requires PostgreSQL.');
        }

        if (Schema::hasTable('user_password_management')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE user_password_management (
    user_id uuid PRIMARY KEY,
    management_mode varchar(32) NOT NULL DEFAULT 'unclassified',
    managing_organization_id uuid NULL,
    credential_version bigint NOT NULL DEFAULT 0,
    password_changed_at timestamptz NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('user_password_management')) {
            throw new LogicException('MIG-TBL-USER_PASSWORD_MANAGEMENT postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
