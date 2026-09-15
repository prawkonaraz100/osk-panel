<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-LANGUAGES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-LANGUAGES requires PostgreSQL.');
        }

        if (Schema::hasTable('languages')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE languages (
    code varchar(16) PRIMARY KEY,
    label_key varchar(128) NOT NULL,
    active boolean NOT NULL DEFAULT true,
    valid_from date NULL,
    valid_to date NULL
)
SQL);

        if (! Schema::hasTable('languages')) {
            throw new LogicException('MIG-TBL-LANGUAGES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
