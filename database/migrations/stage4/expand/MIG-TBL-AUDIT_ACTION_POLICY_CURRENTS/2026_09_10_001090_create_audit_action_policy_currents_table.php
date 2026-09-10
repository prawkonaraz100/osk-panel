<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-AUDIT_ACTION_POLICY_CURRENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-AUDIT_ACTION_POLICY_CURRENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('audit_action_policy_currents')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE audit_action_policy_currents (
    action varchar(128) PRIMARY KEY,
    policy_version bigint NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('audit_action_policy_currents')) {
            throw new LogicException('MIG-TBL-AUDIT_ACTION_POLICY_CURRENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
