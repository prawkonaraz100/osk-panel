<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-LICENSE_ASSIGNMENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-LICENSE_ASSIGNMENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('license_assignments')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE license_assignments (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    license_inventory_entry_id uuid NOT NULL,
    student_id uuid NOT NULL,
    student_learning_account_id uuid NOT NULL,
    license_product_language_capability_id uuid NOT NULL,
    language_code varchar(16) NOT NULL,
    assignment_sequence bigint NOT NULL,
    status varchar(32) NOT NULL,
    assigned_by_user_id uuid NOT NULL,
    assigned_at timestamptz NOT NULL,
    revoked_at timestamptz NULL,
    revoked_by_user_id uuid NULL,
    revoke_reason text NULL,
    version bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('license_assignments')) {
            throw new LogicException('MIG-TBL-LICENSE_ASSIGNMENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
