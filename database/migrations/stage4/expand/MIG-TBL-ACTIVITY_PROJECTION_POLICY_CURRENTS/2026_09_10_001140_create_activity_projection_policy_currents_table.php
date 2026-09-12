<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-ACTIVITY_PROJECTION_POLICY_CURRENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-ACTIVITY_PROJECTION_POLICY_CURRENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('activity_projection_policy_currents')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE activity_projection_policy_currents (
    event_type varchar(128) NOT NULL,
    policy_version bigint NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('activity_projection_policy_currents')) {
            throw new LogicException('MIG-TBL-ACTIVITY_PROJECTION_POLICY_CURRENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
