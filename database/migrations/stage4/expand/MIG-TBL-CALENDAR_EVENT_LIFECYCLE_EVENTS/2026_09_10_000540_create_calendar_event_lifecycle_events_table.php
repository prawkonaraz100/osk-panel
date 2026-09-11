<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-CALENDAR_EVENT_LIFECYCLE_EVENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-CALENDAR_EVENT_LIFECYCLE_EVENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('calendar_event_lifecycle_events')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE calendar_event_lifecycle_events (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    calendar_event_id uuid NOT NULL,
    event_type varchar(32) NOT NULL,
    from_status varchar(32) NULL,
    to_status varchar(32) NOT NULL,
    event_version_before bigint NULL,
    event_version_after bigint NOT NULL,
    actor_user_id uuid NULL,
    reason text NULL,
    changed_fields_redacted jsonb NULL,
    occurred_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('calendar_event_lifecycle_events')) {
            throw new LogicException('MIG-TBL-CALENDAR_EVENT_LIFECYCLE_EVENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
