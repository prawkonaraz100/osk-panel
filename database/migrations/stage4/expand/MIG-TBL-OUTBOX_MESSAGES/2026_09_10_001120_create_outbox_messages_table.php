<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-OUTBOX_MESSAGES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-OUTBOX_MESSAGES requires PostgreSQL.');
        }

        if (Schema::hasTable('outbox_messages')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE outbox_messages (
    id uuid PRIMARY KEY,
    domain_event_id uuid NOT NULL,
    event_scope varchar(32) NOT NULL,
    organization_id uuid NULL,
    aggregate_type varchar(128) NULL,
    aggregate_id varchar(128) NULL,
    event_type varchar(128) NOT NULL,
    payload jsonb NOT NULL,
    request_id varchar(64) NOT NULL,
    publication_state varchar(32) NOT NULL DEFAULT 'pending',
    next_attempt_at timestamptz NULL,
    lease_token varchar(128) NULL,
    lease_version bigint NOT NULL DEFAULT 0,
    leased_by varchar(128) NULL,
    lease_expires_at timestamptz NULL,
    attempts bigint NOT NULL DEFAULT 0,
    attempts_in_cycle integer NOT NULL DEFAULT 0,
    replay_count integer NOT NULL DEFAULT 0,
    published_at timestamptz NULL,
    last_error_code varchar(128) NULL,
    last_error varchar(512) NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('outbox_messages')) {
            throw new LogicException('MIG-TBL-OUTBOX_MESSAGES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
