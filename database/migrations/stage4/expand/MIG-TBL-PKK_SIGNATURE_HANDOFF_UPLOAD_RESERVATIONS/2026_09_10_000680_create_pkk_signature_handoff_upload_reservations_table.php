<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PKK_SIGNATURE_HANDOFF_UPLOAD_RESERVATIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PKK_SIGNATURE_HANDOFF_UPLOAD_RESERVATIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('pkk_signature_handoff_upload_reservations')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE pkk_signature_handoff_upload_reservations (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    pkk_signature_handoff_id uuid NOT NULL,
    pkk_operation_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    pkk_profile_id uuid NOT NULL,
    file_asset_id uuid NOT NULL,
    created_by_user_id uuid NOT NULL,
    created_at timestamptz NOT NULL,
    accepted_at timestamptz NULL,
    rejected_at timestamptz NULL
)
SQL);

        if (! Schema::hasTable('pkk_signature_handoff_upload_reservations')) {
            throw new LogicException('MIG-TBL-PKK_SIGNATURE_HANDOFF_UPLOAD_RESERVATIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
