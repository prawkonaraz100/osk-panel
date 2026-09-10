<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-VEHICLES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-VEHICLES requires PostgreSQL.');
        }

        if (Schema::hasTable('vehicles')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE vehicles (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    registration_number_normalized varchar(32) NOT NULL,
    side_number varchar(64) NULL,
    make varchar(120) NOT NULL,
    model varchar(120) NOT NULL,
    production_year smallint NULL,
    engine_capacity_cm3 integer NULL,
    vin_normalized varchar(32) NULL,
    photo_asset_id uuid NULL,
    archived_at timestamptz NULL,
    archived_by_user_id uuid NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('vehicles')) {
            throw new LogicException('MIG-TBL-VEHICLES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
