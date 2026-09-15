<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-SERVICE_ENTITLEMENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-SERVICE_ENTITLEMENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('service_entitlements')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE service_entitlements (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    service_type varchar(128) NOT NULL,
    source_order_item_id uuid NULL,
    source_order_item_grant_ordinal integer NULL,
    source_grant_reference varchar(255) NULL,
    activation_mode varchar(32) NOT NULL,
    status varchar(32) NOT NULL,
    granted_at timestamptz NOT NULL,
    expires_at timestamptz NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('service_entitlements')) {
            throw new LogicException('MIG-TBL-SERVICE_ENTITLEMENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
