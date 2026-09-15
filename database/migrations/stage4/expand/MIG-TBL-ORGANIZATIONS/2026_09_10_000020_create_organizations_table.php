<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-ORGANIZATIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-ORGANIZATIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('organizations')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE organizations (
    id uuid PRIMARY KEY,
    name varchar(255) NOT NULL,
    nip varchar(16) NULL,
    phone varchar(40) NULL,
    timezone varchar(64) NOT NULL DEFAULT 'Europe/Warsaw',
    status varchar(32) NOT NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('organizations')) {
            throw new LogicException('MIG-TBL-ORGANIZATIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
