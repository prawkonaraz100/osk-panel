<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PKK_PROVIDER_PROFILE_SNAPSHOTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PKK_PROVIDER_PROFILE_SNAPSHOTS requires PostgreSQL.');
        }

        if (Schema::hasTable('pkk_provider_profile_snapshots')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE pkk_provider_profile_snapshots (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    pkk_profile_id uuid NOT NULL,
    snapshot_revision bigint NOT NULL,
    snapshot_schema_version integer NOT NULL,
    capture_origin varchar(32) NOT NULL,
    provider_status_snapshot varchar(128) NULL,
    provider_profile_payload_ciphertext bytea NULL,
    redacted_profile_projection_jsonb jsonb NOT NULL DEFAULT '{}'::jsonb,
    snapshot_content_hash char(64) NOT NULL,
    fetched_at timestamptz NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('pkk_provider_profile_snapshots')) {
            throw new LogicException('MIG-TBL-PKK_PROVIDER_PROFILE_SNAPSHOTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
