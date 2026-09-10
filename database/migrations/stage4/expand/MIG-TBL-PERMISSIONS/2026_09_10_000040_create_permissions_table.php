<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PERMISSIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PERMISSIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('permissions')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE permissions (
    code varchar(128) PRIMARY KEY,
    description varchar(255) NOT NULL
)
SQL);

        if (! Schema::hasTable('permissions')) {
            throw new LogicException('MIG-TBL-PERMISSIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
