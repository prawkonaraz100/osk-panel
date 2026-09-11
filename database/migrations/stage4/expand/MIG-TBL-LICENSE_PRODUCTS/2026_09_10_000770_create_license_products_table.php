<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-LICENSE_PRODUCTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-LICENSE_PRODUCTS requires PostgreSQL.');
        }

        if (Schema::hasTable('license_products')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE license_products (
    id uuid PRIMARY KEY,
    code varchar(64) NOT NULL,
    duration_days integer NOT NULL,
    active boolean NOT NULL DEFAULT true,
    activation_mode varchar(32) NOT NULL,
    metadata jsonb NULL
)
SQL);

        if (! Schema::hasTable('license_products')) {
            throw new LogicException('MIG-TBL-LICENSE_PRODUCTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
