<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-NOTIFICATIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-NOTIFICATIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('notifications')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE notifications (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    source_event_id uuid NOT NULL,
    organization_membership_id uuid NOT NULL,
    user_id uuid NOT NULL,
    audience_kind varchar(32) NOT NULL,
    type varchar(128) NOT NULL,
    payload jsonb NOT NULL,
    read_at timestamptz NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('notifications')) {
            throw new LogicException('MIG-TBL-NOTIFICATIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
