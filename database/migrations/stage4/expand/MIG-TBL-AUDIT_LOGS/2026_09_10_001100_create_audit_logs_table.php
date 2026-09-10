<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-AUDIT_LOGS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-AUDIT_LOGS requires PostgreSQL.');
        }

        if (Schema::hasTable('audit_logs')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE audit_logs (
    id uuid PRIMARY KEY,
    audit_scope varchar(32) NOT NULL,
    organization_id uuid NULL,
    actor_kind varchar(32) NOT NULL,
    actor_organization_membership_id uuid NULL,
    actor_user_id uuid NULL,
    action varchar(128) NOT NULL,
    audit_policy_version bigint NOT NULL,
    entity_reference_mode varchar(32) NOT NULL,
    entity_type varchar(128) NULL,
    entity_id varchar(128) NULL,
    before_redacted_json jsonb NULL,
    after_redacted_json jsonb NULL,
    reason text NULL,
    request_id varchar(64) NOT NULL,
    ip_hash varchar(128) NULL,
    user_agent varchar(512) NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('audit_logs')) {
            throw new LogicException('MIG-TBL-AUDIT_LOGS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
