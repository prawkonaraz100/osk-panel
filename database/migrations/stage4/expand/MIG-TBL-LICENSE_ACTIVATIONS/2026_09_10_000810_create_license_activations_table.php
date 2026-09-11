<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-LICENSE_ACTIVATIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-LICENSE_ACTIVATIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('license_activations')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE license_activations (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    license_assignment_id uuid NOT NULL,
    student_learning_account_id uuid NOT NULL,
    entitlement_sequence bigint NOT NULL,
    activation_origin varchar(32) NOT NULL,
    duration_snapshot_source varchar(32) NOT NULL,
    duration_days_snapshot integer NOT NULL,
    expiry_before timestamptz NULL,
    activated_by_user_id uuid NULL,
    activated_at timestamptz NOT NULL,
    effective_from timestamptz NOT NULL,
    effective_to timestamptz NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('license_activations')) {
            throw new LogicException('MIG-TBL-LICENSE_ACTIVATIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
