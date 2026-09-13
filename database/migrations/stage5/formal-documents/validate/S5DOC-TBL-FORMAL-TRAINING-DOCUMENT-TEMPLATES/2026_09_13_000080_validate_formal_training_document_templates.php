<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-TEMPLATES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-TEMPLATES validate requires PostgreSQL.');
        }

        if (! Schema::hasTable('formal_training_document_templates') || ! Schema::hasTable('formal_training_documents')) {
            throw new LogicException('FORMAL-DOC-006 template validation requires the completed Stage-5 expand shape.');
        }

        $overlaps = (int) DB::table('formal_training_document_templates as left_template')
            ->join('formal_training_document_templates as right_template', function ($join): void {
                $join->on('left_template.document_type', '=', 'right_template.document_type')
                    ->whereColumn('left_template.id', '<', 'right_template.id');
            })
            ->whereRaw(
                "tstzrange(left_template.effective_from, left_template.effective_to, '[)') && tstzrange(right_template.effective_from, right_template.effective_to, '[)')"
            )
            ->count();

        if ($overlaps !== 0) {
            throw new LogicException(
                "FORMAL-DOC-006 validate failed: {$overlaps} formal document template interval pairs overlap."
            );
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'formal_training_document_templates'::regclass
          AND conname = 'formal_training_document_templates_effective_nonoverlap'
    ) THEN
        ALTER TABLE formal_training_document_templates
            ADD CONSTRAINT formal_training_document_templates_effective_nonoverlap
            EXCLUDE USING gist (
                document_type WITH =,
                tstzrange(effective_from, effective_to, '[)') WITH &&
            );
    END IF;
END
$$
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION s5doc_guard_used_template_immutable()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM formal_training_documents
        WHERE formal_training_document_template_id = OLD.id
    ) THEN
        RAISE EXCEPTION 'used formal training document template rows are immutable'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
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
        WHERE tgrelid = 'formal_training_document_templates'::regclass
          AND tgname = 'formal_training_document_templates_used_immutable_guard'
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER formal_training_document_templates_used_immutable_guard
        BEFORE UPDATE OR DELETE
        ON formal_training_document_templates
        FOR EACH ROW
        EXECUTE FUNCTION s5doc_guard_used_template_immutable();
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
