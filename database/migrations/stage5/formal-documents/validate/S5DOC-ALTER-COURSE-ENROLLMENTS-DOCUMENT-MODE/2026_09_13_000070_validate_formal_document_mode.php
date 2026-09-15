<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE validate requires PostgreSQL.');
        }

        if (! Schema::hasTable('course_enrollments') || ! Schema::hasColumns('course_enrollments', [
            'document_mode',
            'document_mode_selected_at',
            'document_mode_selected_by_user_id',
        ])) {
            throw new LogicException('FORMAL-DOC-006 requires the completed course document-mode expand shape.');
        }

        $incomplete = (int) DB::table('course_enrollments')
            ->whereNull('document_mode')
            ->orWhereNull('document_mode_selected_at')
            ->count();

        if ($incomplete !== 0) {
            throw new LogicException(
                "FORMAL-DOC-006 validate failed: {$incomplete} course rows have incomplete document-mode selection."
            );
        }

        $invalidMode = (int) DB::table('course_enrollments')
            ->whereNotIn('document_mode', ['paper', 'electronic'])
            ->count();

        if ($invalidMode !== 0) {
            throw new LogicException(
                "FORMAL-DOC-006 validate failed: {$invalidMode} course rows contain an unsupported document mode."
            );
        }

        DB::statement('ALTER TABLE course_enrollments VALIDATE CONSTRAINT course_enrollments_document_mode_check');

        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'course_enrollments'::regclass
          AND conname = 'course_enrollments_document_mode_complete_check'
    ) THEN
        ALTER TABLE course_enrollments
            ADD CONSTRAINT course_enrollments_document_mode_complete_check
            CHECK (document_mode IS NOT NULL AND document_mode_selected_at IS NOT NULL)
            NOT VALID;
    END IF;
END
$$
SQL);

        DB::statement('ALTER TABLE course_enrollments VALIDATE CONSTRAINT course_enrollments_document_mode_complete_check');

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION s5doc_guard_course_document_mode_after_start()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF ROW(
        OLD.document_mode,
        OLD.document_mode_selected_at,
        OLD.document_mode_selected_by_user_id
    ) IS DISTINCT FROM ROW(
        NEW.document_mode,
        NEW.document_mode_selected_at,
        NEW.document_mode_selected_by_user_id
    ) THEN
        IF clock_timestamp() >= OLD.started_at OR clock_timestamp() >= NEW.started_at THEN
            RAISE EXCEPTION 'formal document mode cannot be changed at or after course started_at'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    RETURN NEW;
END
$$
SQL);

        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'course_enrollments'::regclass
          AND tgname = 'course_enrollments_document_mode_after_start_guard'
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER course_enrollments_document_mode_after_start_guard
        BEFORE UPDATE OF document_mode, document_mode_selected_at, document_mode_selected_by_user_id
        ON course_enrollments
        FOR EACH ROW
        EXECUTE FUNCTION s5doc_guard_course_document_mode_after_start();
    END IF;
END
$$
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. FORMAL-DOC-006 validation constraints require an explicitly reviewed reversal.');
    }
};
