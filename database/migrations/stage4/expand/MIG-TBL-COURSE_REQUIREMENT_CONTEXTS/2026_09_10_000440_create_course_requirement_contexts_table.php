<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-COURSE_REQUIREMENT_CONTEXTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-COURSE_REQUIREMENT_CONTEXTS requires PostgreSQL.');
        }

        if (Schema::hasTable('course_requirement_contexts')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE course_requirement_contexts (
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    state_theory_passed boolean NOT NULL DEFAULT false,
    evidence_reference varchar(255) NULL,
    effective_from timestamptz NOT NULL,
    updated_by_user_id uuid NOT NULL,
    updated_at timestamptz NOT NULL,
    PRIMARY KEY (organization_id, course_enrollment_id)
)
SQL);

        if (! Schema::hasTable('course_requirement_contexts')) {
            throw new LogicException('MIG-TBL-COURSE_REQUIREMENT_CONTEXTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
