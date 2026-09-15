<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-LICENSE_PRODUCT_LANGUAGE_CAPABILITIES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-LICENSE_PRODUCT_LANGUAGE_CAPABILITIES requires PostgreSQL.');
        }

        if (Schema::hasTable('license_product_language_capabilities')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE license_product_language_capabilities (
    id uuid PRIMARY KEY,
    license_product_id uuid NOT NULL,
    language_code varchar(16) NOT NULL,
    enabled_at timestamptz NOT NULL,
    disabled_at timestamptz NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('license_product_language_capabilities')) {
            throw new LogicException('MIG-TBL-LICENSE_PRODUCT_LANGUAGE_CAPABILITIES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
