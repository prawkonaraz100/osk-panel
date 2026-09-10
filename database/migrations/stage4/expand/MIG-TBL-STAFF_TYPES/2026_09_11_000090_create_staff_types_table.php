<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-STAFF_TYPES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-STAFF_TYPES requires PostgreSQL.');
        }

        if (Schema::hasTable('staff_types')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE staff_types (
    code varchar(64) PRIMARY KEY,
    label_key varchar(128) NOT NULL,
    active boolean NOT NULL DEFAULT true
)
SQL);

        if (! Schema::hasTable('staff_types')) {
            throw new LogicException('MIG-TBL-STAFF_TYPES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
