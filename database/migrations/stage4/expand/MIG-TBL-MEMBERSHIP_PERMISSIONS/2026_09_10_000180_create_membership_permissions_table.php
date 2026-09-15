<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-MEMBERSHIP_PERMISSIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-MEMBERSHIP_PERMISSIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('membership_permissions')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE membership_permissions (
    membership_id uuid NOT NULL,
    permission_code varchar(128) NOT NULL,
    granted boolean NOT NULL,
    created_at timestamptz NOT NULL,
    PRIMARY KEY (membership_id, permission_code)
)
SQL);

        if (! Schema::hasTable('membership_permissions')) {
            throw new LogicException('MIG-TBL-MEMBERSHIP_PERMISSIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
