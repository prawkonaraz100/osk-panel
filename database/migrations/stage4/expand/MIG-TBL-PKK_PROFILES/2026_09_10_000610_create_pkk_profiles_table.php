<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PKK_PROFILES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PKK_PROFILES requires PostgreSQL.');
        }

        if (Schema::hasTable('pkk_profiles')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE pkk_profiles (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    pkk_number_ciphertext text NOT NULL,
    pkk_lookup_hash char(64) NOT NULL,
    identity_revision bigint NOT NULL DEFAULT 1,
    bound_driving_category_id uuid NOT NULL,
    bound_training_type varchar(32) NOT NULL,
    record_origin varchar(32) NOT NULL,
    recorded_at timestamptz NOT NULL,
    recorded_by_user_id uuid NULL,
    supersedes_pkk_profile_id uuid NULL,
    superseded_at timestamptz NULL,
    superseded_by_user_id uuid NULL,
    status varchar(64) NULL,
    profile_snapshot_ciphertext text NULL,
    profile_snapshot_redacted jsonb NULL,
    fetched_at timestamptz NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('pkk_profiles')) {
            throw new LogicException('MIG-TBL-PKK_PROFILES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
