<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('contract', 'S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE contract requires PostgreSQL.');
        }

        if (! Schema::hasTable('course_enrollments') || ! Schema::hasColumns('course_enrollments', [
            'document_mode',
            'document_mode_selected_at',
            'document_mode_selected_by_user_id',
        ])) {
            throw new LogicException('FORMAL-DOC-007 requires the completed document-mode expand shape.');
        }

        $incomplete = (int) DB::table('course_enrollments')
            ->whereNull('document_mode')
            ->orWhereNull('document_mode_selected_at')
            ->count();

        if ($incomplete !== 0) {
            throw new LogicException(
                "FORMAL-DOC-007 contract failed: {$incomplete} course rows still have incomplete document-mode selection."
            );
        }

        $validationState = DB::selectOne(<<<'SQL'
SELECT
    COUNT(*)::int AS constraint_count,
    COALESCE(bool_and(convalidated), false) AS all_validated
FROM pg_constraint
WHERE conrelid = 'course_enrollments'::regclass
  AND conname IN (
      'course_enrollments_document_mode_check',
      'course_enrollments_document_mode_complete_check'
  )
SQL);

        if ($validationState === null
            || (int) $validationState->constraint_count !== 2
            || ! (bool) $validationState->all_validated) {
            throw new LogicException(
                'FORMAL-DOC-007 contract requires both document-mode validation constraints to exist and be validated.'
            );
        }

        DB::statement(<<<'SQL'
ALTER TABLE course_enrollments
    ALTER COLUMN document_mode SET NOT NULL,
    ALTER COLUMN document_mode_selected_at SET NOT NULL
SQL);

        $nullableColumns = (int) DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'course_enrollments')
            ->whereIn('column_name', ['document_mode', 'document_mode_selected_at'])
            ->where('is_nullable', '<>', 'NO')
            ->count();

        if ($nullableColumns !== 0) {
            throw new LogicException('FORMAL-DOC-007 contract postcondition failed: required columns remain nullable.');
        }

        $actorColumnNullable = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'course_enrollments')
            ->where('column_name', 'document_mode_selected_by_user_id')
            ->value('is_nullable');

        if ($actorColumnNullable !== 'YES') {
            throw new LogicException('FORMAL-DOC-007 contract postcondition failed: legacy selection actor must remain nullable.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. FORMAL-DOC-007 contract requires an explicitly reviewed reversal.');
    }
};
