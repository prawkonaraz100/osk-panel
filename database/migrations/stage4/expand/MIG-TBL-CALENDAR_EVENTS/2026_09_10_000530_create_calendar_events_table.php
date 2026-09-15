<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-CALENDAR_EVENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-CALENDAR_EVENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('calendar_events')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE calendar_events (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    event_type varchar(32) NOT NULL,
    name varchar(255) NULL,
    starts_at timestamptz NOT NULL,
    ends_at timestamptz NOT NULL,
    student_id uuid NULL,
    instructor_id uuid NULL,
    vehicle_id uuid NULL,
    location_id uuid NULL,
    custom_meeting_place varchar(255) NULL,
    status varchar(32) NOT NULL DEFAULT 'scheduled',
    created_by_user_id uuid NOT NULL,
    version bigint NOT NULL DEFAULT 1,
    completed_at timestamptz NULL,
    completed_by_user_id uuid NULL,
    cancelled_at timestamptz NULL,
    cancelled_by_user_id uuid NULL,
    cancellation_reason text NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('calendar_events')) {
            throw new LogicException('MIG-TBL-CALENDAR_EVENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
