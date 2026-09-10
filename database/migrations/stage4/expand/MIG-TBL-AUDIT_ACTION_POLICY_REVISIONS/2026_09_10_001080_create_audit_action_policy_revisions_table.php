<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-AUDIT_ACTION_POLICY_REVISIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-AUDIT_ACTION_POLICY_REVISIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('audit_action_policy_revisions')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE audit_action_policy_revisions (
    action varchar(128) NOT NULL,
    policy_version bigint NOT NULL,
    payload_validator_code varchar(128) NOT NULL,
    before_payload_requirement varchar(32) NOT NULL,
    after_payload_requirement varchar(32) NOT NULL,
    reason_requirement varchar(32) NOT NULL,
    policy_hash varchar(128) NOT NULL,
    created_at timestamptz NOT NULL,
    PRIMARY KEY (action, policy_version)
)
SQL);

        if (! Schema::hasTable('audit_action_policy_revisions')) {
            throw new LogicException('MIG-TBL-AUDIT_ACTION_POLICY_REVISIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
