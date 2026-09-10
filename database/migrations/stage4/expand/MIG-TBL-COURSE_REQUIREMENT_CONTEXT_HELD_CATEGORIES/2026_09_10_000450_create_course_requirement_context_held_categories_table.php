<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-COURSE_REQUIREMENT_CONTEXT_HELD_CATEGORIES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-COURSE_REQUIREMENT_CONTEXT_HELD_CATEGORIES requires PostgreSQL.');
        }

        if (Schema::hasTable('course_requirement_context_held_categories')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE course_requirement_context_held_categories (
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    driving_category_id uuid NOT NULL,
    PRIMARY KEY (organization_id, course_enrollment_id, driving_category_id)
)
SQL);

        if (! Schema::hasTable('course_requirement_context_held_categories')) {
            throw new LogicException('MIG-TBL-COURSE_REQUIREMENT_CONTEXT_HELD_CATEGORIES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
