<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PKK_OPERATION_LIFECYCLE_EVENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PKK_OPERATION_LIFECYCLE_EVENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('pkk_operation_lifecycle_events')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE pkk_operation_lifecycle_events (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    pkk_operation_id uuid NOT NULL,
    operation_version bigint NOT NULL,
    from_business_status varchar(64) NULL,
    to_business_status varchar(64) NOT NULL,
    event_origin varchar(32) NOT NULL,
    occurred_at timestamptz NOT NULL,
    actor_kind varchar(32) NOT NULL,
    actor_user_id uuid NULL,
    reason_code varchar(128) NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('pkk_operation_lifecycle_events')) {
            throw new LogicException('MIG-TBL-PKK_OPERATION_LIFECYCLE_EVENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
