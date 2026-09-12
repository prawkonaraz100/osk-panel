<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-SERVICE_ACTIVATIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-SERVICE_ACTIVATIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('service_activations')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE service_activations (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    service_entitlement_id uuid NOT NULL,
    activated_by_user_id uuid NULL,
    activated_at timestamptz NOT NULL,
    effective_from timestamptz NOT NULL,
    effective_to timestamptz NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('service_activations')) {
            throw new LogicException('MIG-TBL-SERVICE_ACTIVATIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
