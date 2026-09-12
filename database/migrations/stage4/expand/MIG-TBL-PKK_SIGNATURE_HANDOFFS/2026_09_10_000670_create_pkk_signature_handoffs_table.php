<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PKK_SIGNATURE_HANDOFFS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PKK_SIGNATURE_HANDOFFS requires PostgreSQL.');
        }

        if (Schema::hasTable('pkk_signature_handoffs')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE pkk_signature_handoffs (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    pkk_profile_id uuid NOT NULL,
    pkk_operation_id uuid NOT NULL,
    handoff_no bigint NOT NULL,
    requires_signature_operation_version bigint NOT NULL,
    source_pkk_operation_attempt_id uuid NULL,
    unsigned_file_asset_id uuid NOT NULL,
    unsigned_sha256 char(64) NOT NULL,
    signed_file_asset_id uuid NULL,
    signed_sha256 char(64) NULL,
    signed_attached_by_user_id uuid NULL,
    signed_attached_at timestamptz NULL,
    consumed_at timestamptz NULL,
    cancelled_at timestamptz NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('pkk_signature_handoffs')) {
            throw new LogicException('MIG-TBL-PKK_SIGNATURE_HANDOFFS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
