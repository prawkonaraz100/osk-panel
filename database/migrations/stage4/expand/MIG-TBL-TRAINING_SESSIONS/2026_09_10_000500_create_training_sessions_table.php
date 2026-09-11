<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-TRAINING_SESSIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-TRAINING_SESSIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('training_sessions')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE training_sessions (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    session_type varchar(32) NOT NULL,
    starts_at timestamptz NOT NULL,
    ends_at timestamptz NOT NULL,
    duration_minutes integer NOT NULL,
    instructor_id uuid NOT NULL,
    vehicle_id uuid NULL,
    location_id uuid NULL,
    status varchar(32) NOT NULL DEFAULT 'planned',
    completed_at timestamptz NULL,
    completed_by_user_id uuid NULL,
    cancelled_at timestamptz NULL,
    cancelled_by_user_id uuid NULL,
    cancellation_reason text NULL,
    created_by_user_id uuid NULL,
    version bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('training_sessions')) {
            throw new LogicException('MIG-TBL-TRAINING_SESSIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
