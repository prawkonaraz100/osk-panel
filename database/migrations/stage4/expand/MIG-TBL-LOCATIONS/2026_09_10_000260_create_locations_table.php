<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-LOCATIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-LOCATIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('locations')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE locations (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    type_code varchar(64) NOT NULL,
    name varchar(255) NOT NULL,
    street_and_number varchar(255) NOT NULL,
    postal_code varchar(20) NOT NULL,
    city_reference varchar(128) NULL,
    city_name varchar(160) NOT NULL,
    voivodeship_name varchar(160) NULL,
    archived_at timestamptz NULL,
    archived_by_user_id uuid NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('locations')) {
            throw new LogicException('MIG-TBL-LOCATIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
