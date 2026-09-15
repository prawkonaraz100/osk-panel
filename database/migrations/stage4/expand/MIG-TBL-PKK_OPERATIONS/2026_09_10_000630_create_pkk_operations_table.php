<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PKK_OPERATIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PKK_OPERATIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('pkk_operations')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE pkk_operations (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    pkk_profile_id uuid NOT NULL,
    operation_type varchar(64) NOT NULL,
    business_status varchar(64) NOT NULL,
    operation_origin varchar(32) NOT NULL,
    version bigint NOT NULL DEFAULT 1,
    initial_idempotency_record_id uuid NULL,
    course_operation_sequence bigint NOT NULL,
    confirmed_at timestamptz NULL,
    confirmed_by_user_id uuid NULL,
    completed_at timestamptz NULL,
    actor_user_id uuid NOT NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('pkk_operations')) {
            throw new LogicException('MIG-TBL-PKK_OPERATIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
