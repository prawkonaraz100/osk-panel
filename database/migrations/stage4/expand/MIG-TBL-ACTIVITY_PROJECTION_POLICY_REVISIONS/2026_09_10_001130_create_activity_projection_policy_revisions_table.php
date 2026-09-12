<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-ACTIVITY_PROJECTION_POLICY_REVISIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-ACTIVITY_PROJECTION_POLICY_REVISIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('activity_projection_policy_revisions')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE activity_projection_policy_revisions (
    event_type varchar(128) NOT NULL,
    policy_version bigint NOT NULL,
    payload_validator_code varchar(128) NOT NULL,
    description_builder_code varchar(128) NOT NULL,
    actor_snapshot_rule_code varchar(128) NOT NULL,
    subject_reference_rule_code varchar(128) NOT NULL,
    related_student_rule_code varchar(128) NOT NULL,
    navigation_rule_code varchar(128) NOT NULL,
    supports_expand boolean NOT NULL DEFAULT false,
    policy_hash char(64) NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('activity_projection_policy_revisions')) {
            throw new LogicException('MIG-TBL-ACTIVITY_PROJECTION_POLICY_REVISIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
