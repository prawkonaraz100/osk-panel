<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PKK_PROTECTED_PAYLOAD_KEY_WRAPPINGS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PKK_PROTECTED_PAYLOAD_KEY_WRAPPINGS requires PostgreSQL.');
        }

        if (Schema::hasTable('pkk_protected_payload_key_wrappings')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE pkk_protected_payload_key_wrappings (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    pkk_protected_payload_id uuid NOT NULL,
    wrapping_revision bigint NOT NULL,
    wrapping_profile_version integer NOT NULL,
    key_provider_code varchar(64) NOT NULL,
    kek_reference varchar(255) NOT NULL,
    kek_version varchar(128) NOT NULL,
    wrapped_dek bytea NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('pkk_protected_payload_key_wrappings')) {
            throw new LogicException('MIG-TBL-PKK_PROTECTED_PAYLOAD_KEY_WRAPPINGS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
