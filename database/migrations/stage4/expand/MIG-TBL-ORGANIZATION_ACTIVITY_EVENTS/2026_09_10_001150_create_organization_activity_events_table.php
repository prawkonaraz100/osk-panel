<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-ORGANIZATION_ACTIVITY_EVENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-ORGANIZATION_ACTIVITY_EVENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('organization_activity_events')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE organization_activity_events (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    source_event_id uuid NOT NULL,
    projection_policy_version bigint NOT NULL,
    event_type varchar(128) NOT NULL,
    occurred_at timestamptz NOT NULL,
    description_snapshot text NOT NULL,
    safe_payload jsonb NULL,
    actor_reference_mode varchar(32) NOT NULL,
    actor_organization_membership_id uuid NULL,
    actor_user_id uuid NULL,
    actor_display_name_snapshot varchar(255) NULL,
    actor_role_snapshot varchar(255) NULL,
    subject_reference_mode varchar(32) NOT NULL,
    subject_type varchar(128) NULL,
    subject_id varchar(128) NULL,
    related_student_id uuid NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('organization_activity_events')) {
            throw new LogicException('MIG-TBL-ORGANIZATION_ACTIVITY_EVENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
