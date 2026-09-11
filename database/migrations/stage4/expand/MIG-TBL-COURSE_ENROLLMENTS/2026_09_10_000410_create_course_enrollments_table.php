<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-COURSE_ENROLLMENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-COURSE_ENROLLMENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('course_enrollments')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE course_enrollments (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    student_id uuid NOT NULL,
    training_type varchar(32) NOT NULL,
    driving_category_id uuid NOT NULL,
    started_at timestamptz NOT NULL,
    lead_instructor_id uuid NOT NULL,
    location_id uuid NULL,
    training_stage varchar(64) NOT NULL DEFAULT 'unassigned',
    declared_theory_minutes integer NULL,
    declared_practical_minutes integer NULL,
    completed_at timestamptz NULL,
    interrupted_at timestamptz NULL,
    cancelled_at timestamptz NULL,
    cancelled_by_user_id uuid NULL,
    created_by_user_id uuid NULL,
    version bigint NOT NULL DEFAULT 1,
    requirements_revision bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('course_enrollments')) {
            throw new LogicException('MIG-TBL-COURSE_ENROLLMENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
