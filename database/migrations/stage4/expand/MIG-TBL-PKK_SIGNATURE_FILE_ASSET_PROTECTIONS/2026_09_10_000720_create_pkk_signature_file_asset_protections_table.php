<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PKK_SIGNATURE_FILE_ASSET_PROTECTIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PKK_SIGNATURE_FILE_ASSET_PROTECTIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('pkk_signature_file_asset_protections')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE pkk_signature_file_asset_protections (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    pkk_signature_handoff_id uuid NOT NULL,
    file_asset_id uuid NOT NULL,
    asset_role varchar(32) NOT NULL,
    crypto_profile_version integer NOT NULL,
    aead_algorithm_code varchar(64) NOT NULL,
    aad_schema_version integer NOT NULL,
    nonce bytea NOT NULL,
    auth_tag bytea NOT NULL,
    encrypted_storage_object_sha256 char(64) NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('pkk_signature_file_asset_protections')) {
            throw new LogicException('MIG-TBL-PKK_SIGNATURE_FILE_ASSET_PROTECTIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
