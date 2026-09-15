<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-MEMBERSHIP_PERMISSION_SCOPES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-MEMBERSHIP_PERMISSION_SCOPES requires PostgreSQL.');
        }

        if (Schema::hasTable('membership_permission_scopes')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE membership_permission_scopes (
    membership_id uuid NOT NULL,
    permission_code varchar(128) NOT NULL,
    scope_code varchar(64) NOT NULL,
    created_at timestamptz NOT NULL,
    PRIMARY KEY (membership_id, permission_code, scope_code)
)
SQL);

        if (! Schema::hasTable('membership_permission_scopes')) {
            throw new LogicException('MIG-TBL-MEMBERSHIP_PERMISSION_SCOPES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
