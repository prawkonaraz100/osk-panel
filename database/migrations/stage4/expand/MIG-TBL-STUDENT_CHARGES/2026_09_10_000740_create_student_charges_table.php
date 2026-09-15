<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-STUDENT_CHARGES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-STUDENT_CHARGES requires PostgreSQL.');
        }

        if (Schema::hasTable('student_charges')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE student_charges (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    student_id uuid NOT NULL,
    course_enrollment_id uuid NULL,
    title varchar(255) NOT NULL,
    amount_minor bigint NOT NULL,
    currency char(3) NOT NULL,
    due_at date NULL,
    created_by_user_id uuid NOT NULL,
    cancelled_at timestamptz NULL,
    cancelled_by_user_id uuid NULL,
    cancellation_reason text NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('student_charges')) {
            throw new LogicException('MIG-TBL-STUDENT_CHARGES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
