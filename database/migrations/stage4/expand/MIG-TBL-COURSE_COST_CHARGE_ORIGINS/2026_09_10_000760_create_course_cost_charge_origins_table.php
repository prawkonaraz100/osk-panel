<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-COURSE_COST_CHARGE_ORIGINS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-COURSE_COST_CHARGE_ORIGINS requires PostgreSQL.');
        }

        if (Schema::hasTable('course_cost_charge_origins')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE course_cost_charge_origins (
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    student_id uuid NOT NULL,
    student_charge_id uuid NOT NULL,
    source_amount_minor bigint NOT NULL,
    source_currency char(3) NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('course_cost_charge_origins')) {
            throw new LogicException('MIG-TBL-COURSE_COST_CHARGE_ORIGINS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
