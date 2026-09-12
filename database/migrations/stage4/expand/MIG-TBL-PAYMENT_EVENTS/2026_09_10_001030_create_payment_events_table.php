<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PAYMENT_EVENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PAYMENT_EVENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('payment_events')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE payment_events (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    payment_id uuid NOT NULL,
    provider varchar(64) NOT NULL,
    provider_payment_id varchar(255) NOT NULL,
    provider_event_id varchar(255) NOT NULL,
    event_type varchar(128) NOT NULL,
    normalized_outcome varchar(32) NOT NULL,
    payload_hash char(64) NOT NULL,
    received_at timestamptz NOT NULL,
    processed_at timestamptz NULL,
    application_result varchar(64) NULL
)
SQL);

        if (! Schema::hasTable('payment_events')) {
            throw new LogicException('MIG-TBL-PAYMENT_EVENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
