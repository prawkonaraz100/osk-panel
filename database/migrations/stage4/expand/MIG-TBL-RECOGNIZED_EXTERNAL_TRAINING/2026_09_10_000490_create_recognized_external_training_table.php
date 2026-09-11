<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-RECOGNIZED_EXTERNAL_TRAINING');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-RECOGNIZED_EXTERNAL_TRAINING requires PostgreSQL.');
        }

        if (Schema::hasTable('recognized_external_training')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE recognized_external_training (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    training_part varchar(32) NOT NULL,
    recognized_minutes integer NOT NULL,
    record_role varchar(32) NOT NULL,
    source_kind varchar(32) NOT NULL,
    source_school_reference varchar(255) NULL,
    evidence_reference varchar(255) NULL,
    reason text NULL,
    approved_by_user_id uuid NULL,
    recognized_for_driving_category_id uuid NULL,
    recognized_for_training_type varchar(32) NULL,
    supersedes_record_id uuid NULL,
    created_at timestamptz NOT NULL,
    superseded_at timestamptz NULL,
    revoked_at timestamptz NULL,
    revoked_by_user_id uuid NULL,
    revocation_reason text NULL
)
SQL);

        if (! Schema::hasTable('recognized_external_training')) {
            throw new LogicException('MIG-TBL-RECOGNIZED_EXTERNAL_TRAINING postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
