<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-DRIVING_CATEGORIES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-DRIVING_CATEGORIES requires PostgreSQL.');
        }

        if (Schema::hasTable('driving_categories')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE driving_categories (
    id uuid PRIMARY KEY,
    code varchar(32) NOT NULL,
    label varchar(64) NOT NULL,
    active boolean NOT NULL DEFAULT true,
    valid_from date NULL,
    valid_to date NULL,
    metadata jsonb NULL
)
SQL);

        if (! Schema::hasTable('driving_categories')) {
            throw new LogicException('MIG-TBL-DRIVING_CATEGORIES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
