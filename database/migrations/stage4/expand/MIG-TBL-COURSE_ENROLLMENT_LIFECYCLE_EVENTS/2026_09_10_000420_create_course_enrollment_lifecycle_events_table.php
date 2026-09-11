<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-COURSE_ENROLLMENT_LIFECYCLE_EVENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-COURSE_ENROLLMENT_LIFECYCLE_EVENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('course_enrollment_lifecycle_events')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE course_enrollment_lifecycle_events (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    event_type varchar(48) NOT NULL,
    from_lifecycle_state varchar(24) NULL,
    to_lifecycle_state varchar(24) NOT NULL,
    from_training_stage varchar(64) NULL,
    to_training_stage varchar(64) NOT NULL,
    course_version_before bigint NULL,
    course_version_after bigint NOT NULL,
    reason text NULL,
    actor_user_id uuid NULL,
    correction_of_event_id uuid NULL,
    event_payload_redacted jsonb NULL,
    occurred_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('course_enrollment_lifecycle_events')) {
            throw new LogicException('MIG-TBL-COURSE_ENROLLMENT_LIFECYCLE_EVENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
