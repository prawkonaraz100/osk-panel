<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-ACCOUNT_CLOSURE_REQUESTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-ACCOUNT_CLOSURE_REQUESTS requires PostgreSQL.');
        }

        if (Schema::hasTable('account_closure_requests')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE account_closure_requests (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL,
    organization_id uuid NULL,
    requested_at timestamptz NOT NULL,
    reason text NULL,
    status varchar(32) NOT NULL,
    resolved_at timestamptz NULL,
    resolved_by_user_id uuid NULL,
    resolution_note text NULL,
    request_id varchar(64) NOT NULL
)
SQL);

        if (! Schema::hasTable('account_closure_requests')) {
            throw new LogicException('MIG-TBL-ACCOUNT_CLOSURE_REQUESTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
