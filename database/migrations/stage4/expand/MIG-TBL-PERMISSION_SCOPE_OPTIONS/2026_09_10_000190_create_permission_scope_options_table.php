<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PERMISSION_SCOPE_OPTIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PERMISSION_SCOPE_OPTIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('permission_scope_options')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE permission_scope_options (
    permission_code varchar(128) NOT NULL,
    scope_code varchar(64) NOT NULL,
    resolver_code varchar(96) NOT NULL,
    PRIMARY KEY (permission_code, scope_code)
)
SQL);

        if (! Schema::hasTable('permission_scope_options')) {
            throw new LogicException('MIG-TBL-PERMISSION_SCOPE_OPTIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
