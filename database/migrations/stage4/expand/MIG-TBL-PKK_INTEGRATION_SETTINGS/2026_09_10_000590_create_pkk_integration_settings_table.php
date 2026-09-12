<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PKK_INTEGRATION_SETTINGS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PKK_INTEGRATION_SETTINGS requires PostgreSQL.');
        }

        if (Schema::hasTable('pkk_integration_settings')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE pkk_integration_settings (
    organization_id uuid PRIMARY KEY,
    school_name varchar(255) NULL,
    osk_registry_number varchar(128) NULL,
    external_osk_login_ciphertext text NULL,
    external_osk_login_lookup_hash char(64) NULL,
    readiness_status varchar(64) NOT NULL DEFAULT 'not_configured',
    execution_configuration_revision bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('pkk_integration_settings')) {
            throw new LogicException('MIG-TBL-PKK_INTEGRATION_SETTINGS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
