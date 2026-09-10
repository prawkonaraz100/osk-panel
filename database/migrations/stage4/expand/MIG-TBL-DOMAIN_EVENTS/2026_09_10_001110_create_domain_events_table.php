<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-DOMAIN_EVENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-DOMAIN_EVENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('domain_events')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE domain_events (
    id uuid PRIMARY KEY,
    event_scope varchar(32) NOT NULL,
    organization_id uuid NULL,
    event_type varchar(128) NOT NULL,
    aggregate_reference_mode varchar(32) NOT NULL,
    aggregate_type varchar(128) NULL,
    aggregate_id varchar(128) NULL,
    request_id varchar(64) NOT NULL,
    causation_event_id uuid NULL,
    required_audit_log_id uuid NULL,
    occurred_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('domain_events')) {
            throw new LogicException('MIG-TBL-DOMAIN_EVENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
