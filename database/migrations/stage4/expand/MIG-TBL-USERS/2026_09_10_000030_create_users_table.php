<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-USERS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-USERS requires PostgreSQL.');
        }

        if (Schema::hasTable('users')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE users (
    id uuid PRIMARY KEY,
    first_name varchar(120) NULL,
    last_name varchar(120) NULL,
    password_hash varchar(255) NULL,
    status varchar(32) NOT NULL,
    last_login_at timestamptz NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('users')) {
            throw new LogicException('MIG-TBL-USERS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
