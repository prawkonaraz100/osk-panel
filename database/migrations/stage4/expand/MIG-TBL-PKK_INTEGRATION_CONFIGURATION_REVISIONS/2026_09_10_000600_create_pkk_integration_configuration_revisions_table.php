<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PKK_INTEGRATION_CONFIGURATION_REVISIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PKK_INTEGRATION_CONFIGURATION_REVISIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('pkk_integration_configuration_revisions')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE pkk_integration_configuration_revisions (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    execution_configuration_revision bigint NOT NULL,
    organization_settings_version_at_capture integer NOT NULL,
    school_name_snapshot varchar(255) NOT NULL,
    osk_registry_number_snapshot varchar(128) NOT NULL,
    external_osk_login_binding_hmac char(64) NOT NULL,
    readiness_status_snapshot varchar(64) NOT NULL,
    configuration_binding_hmac char(64) NOT NULL,
    revision_origin varchar(32) NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('pkk_integration_configuration_revisions')) {
            throw new LogicException('MIG-TBL-PKK_INTEGRATION_CONFIGURATION_REVISIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
