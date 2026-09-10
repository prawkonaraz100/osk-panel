<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-STAFF_PROFILES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-STAFF_PROFILES requires PostgreSQL.');
        }

        if (Schema::hasTable('staff_profiles')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE staff_profiles (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    first_name varchar(120) NOT NULL,
    last_name varchar(120) NOT NULL,
    email_normalized varchar(320) NOT NULL,
    phone varchar(40) NULL,
    pesel_ciphertext text NULL,
    pesel_lookup_hash char(64) NULL,
    authorization_number varchar(128) NULL,
    photo_asset_id uuid NULL,
    archived_at timestamptz NULL,
    archived_by_user_id uuid NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('staff_profiles')) {
            throw new LogicException('MIG-TBL-STAFF_PROFILES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
