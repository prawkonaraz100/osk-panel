<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-STAFF_CATEGORY_ASSIGNMENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-STAFF_CATEGORY_ASSIGNMENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('staff_category_assignments')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE staff_category_assignments (
    staff_profile_id uuid NOT NULL,
    driving_category_id uuid NOT NULL,
    PRIMARY KEY (staff_profile_id, driving_category_id)
)
SQL);

        if (! Schema::hasTable('staff_category_assignments')) {
            throw new LogicException('MIG-TBL-STAFF_CATEGORY_ASSIGNMENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
