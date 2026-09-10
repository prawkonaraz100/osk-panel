<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-DATA_SCOPES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-DATA_SCOPES requires PostgreSQL.');
        }

        if (Schema::hasTable('data_scopes')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE data_scopes (
    code varchar(64) PRIMARY KEY,
    description varchar(255) NOT NULL
)
SQL);

        if (! Schema::hasTable('data_scopes')) {
            throw new LogicException('MIG-TBL-DATA_SCOPES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
