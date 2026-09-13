<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE requires PostgreSQL.');
        }

        if (! Schema::hasTable('course_enrollments')) {
            throw new LogicException('S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE requires course_enrollments.');
        }

        DB::statement(<<<'SQL'
ALTER TABLE course_enrollments
    ADD COLUMN IF NOT EXISTS document_mode varchar(16) NULL,
    ADD COLUMN IF NOT EXISTS document_mode_selected_at timestamptz NULL,
    ADD COLUMN IF NOT EXISTS document_mode_selected_by_user_id uuid NULL
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE course_enrollments
    ALTER COLUMN document_mode SET DEFAULT 'paper',
    ALTER COLUMN document_mode_selected_at SET DEFAULT CURRENT_TIMESTAMP
SQL);

        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'course_enrollments'::regclass
          AND conname = 'course_enrollments_document_mode_check'
    ) THEN
        ALTER TABLE course_enrollments
            ADD CONSTRAINT course_enrollments_document_mode_check
            CHECK (document_mode IS NULL OR document_mode IN ('paper', 'electronic'))
            NOT VALID;
    END IF;
END
$$
SQL);

        if (! Schema::hasColumns('course_enrollments', [
            'document_mode',
            'document_mode_selected_at',
            'document_mode_selected_by_user_id',
        ])) {
            throw new LogicException('S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
