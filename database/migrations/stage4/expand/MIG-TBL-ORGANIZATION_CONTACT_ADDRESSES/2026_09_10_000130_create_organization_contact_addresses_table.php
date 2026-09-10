<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-ORGANIZATION_CONTACT_ADDRESSES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-ORGANIZATION_CONTACT_ADDRESSES requires PostgreSQL.');
        }

        if (Schema::hasTable('organization_contact_addresses')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE organization_contact_addresses (
    organization_id uuid PRIMARY KEY,
    street varchar(255) NOT NULL,
    house_number varchar(32) NOT NULL,
    unit_number varchar(32) NULL,
    postal_code varchar(20) NOT NULL,
    city_name varchar(160) NOT NULL,
    city_reference varchar(128) NULL,
    voivodeship_name varchar(160) NULL,
    country_code char(2) NOT NULL DEFAULT 'PL',
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('organization_contact_addresses')) {
            throw new LogicException('MIG-TBL-ORGANIZATION_CONTACT_ADDRESSES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
