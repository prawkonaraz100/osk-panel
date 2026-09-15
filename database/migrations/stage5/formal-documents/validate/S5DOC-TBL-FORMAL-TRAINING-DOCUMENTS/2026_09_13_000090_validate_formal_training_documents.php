<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('validate', 'S5DOC-TBL-FORMAL-TRAINING-DOCUMENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('S5DOC-TBL-FORMAL-TRAINING-DOCUMENTS validate requires PostgreSQL.');
        }

        foreach ([
            'formal_training_documents',
            'formal_training_document_templates',
            'course_enrollments',
            'file_assets',
        ] as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                throw new LogicException("FORMAL-DOC-006 document validation requires {$requiredTable}.");
            }
        }

        $invalidCourseRelations = (int) DB::table('formal_training_documents as documents')
            ->leftJoin('course_enrollments as courses', 'courses.id', '=', 'documents.course_enrollment_id')
            ->where(function ($query): void {
                $query->whereNull('courses.id')
                    ->orWhereColumn('courses.organization_id', '<>', 'documents.organization_id');
            })
            ->count();

        if ($invalidCourseRelations !== 0) {
            throw new LogicException(
                "FORMAL-DOC-006 validate failed: {$invalidCourseRelations} formal document rows do not reference a course in the same organization."
            );
        }

        $invalidAssetRelations = (int) DB::table('formal_training_documents as documents')
            ->leftJoin('file_assets as assets', 'assets.id', '=', 'documents.asset_id')
            ->where(function ($query): void {
                $query->whereNull('assets.id')
                    ->orWhereNull('assets.organization_id')
                    ->orWhereColumn('assets.organization_id', '<>', 'documents.organization_id');
            })
            ->count();

        if ($invalidAssetRelations !== 0) {
            throw new LogicException(
                "FORMAL-DOC-006 validate failed: {$invalidAssetRelations} formal document rows do not reference a tenant asset in the same organization."
            );
        }

        $invalidTemplateSnapshots = (int) DB::table('formal_training_documents as documents')
            ->leftJoin('formal_training_document_templates as templates', function ($join): void {
                $join->on('templates.id', '=', 'documents.formal_training_document_template_id')
                    ->on('templates.document_type', '=', 'documents.document_type')
                    ->on('templates.template_version', '=', 'documents.template_version_snapshot')
                    ->on('templates.renderer_version', '=', 'documents.renderer_version_snapshot')
                    ->on('templates.template_content_hash', '=', 'documents.template_hash_snapshot');
            })
            ->whereNull('templates.id')
            ->count();

        if ($invalidTemplateSnapshots !== 0) {
            throw new LogicException(
                "FORMAL-DOC-006 validate failed: {$invalidTemplateSnapshots} formal document rows do not match their exact template snapshot."
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS s5doc_course_enrollments_org_id_uidx ON course_enrollments (organization_id, id)'
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS s5doc_file_assets_org_id_uidx ON file_assets (organization_id, id)'
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS s5doc_formal_training_documents_org_id_uidx ON formal_training_documents (organization_id, id)'
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS s5doc_formal_training_document_templates_snapshot_uidx ON formal_training_document_templates (id, document_type, template_version, renderer_version, template_content_hash)'
        );

        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'formal_training_documents'::regclass
          AND conname = 'formal_training_documents_course_same_tenant_fk'
    ) THEN
        ALTER TABLE formal_training_documents
            ADD CONSTRAINT formal_training_documents_course_same_tenant_fk
            FOREIGN KEY (organization_id, course_enrollment_id)
            REFERENCES course_enrollments (organization_id, id)
            MATCH SIMPLE
            ON UPDATE RESTRICT
            ON DELETE RESTRICT
            NOT VALID;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'formal_training_documents'::regclass
          AND conname = 'formal_training_documents_asset_same_tenant_fk'
    ) THEN
        ALTER TABLE formal_training_documents
            ADD CONSTRAINT formal_training_documents_asset_same_tenant_fk
            FOREIGN KEY (organization_id, asset_id)
            REFERENCES file_assets (organization_id, id)
            MATCH SIMPLE
            ON UPDATE RESTRICT
            ON DELETE RESTRICT
            NOT VALID;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'formal_training_documents'::regclass
          AND conname = 'formal_training_documents_template_snapshot_fk'
    ) THEN
        ALTER TABLE formal_training_documents
            ADD CONSTRAINT formal_training_documents_template_snapshot_fk
            FOREIGN KEY (
                formal_training_document_template_id,
                document_type,
                template_version_snapshot,
                renderer_version_snapshot,
                template_hash_snapshot
            )
            REFERENCES formal_training_document_templates (
                id,
                document_type,
                template_version,
                renderer_version,
                template_content_hash
            )
            MATCH SIMPLE
            ON UPDATE RESTRICT
            ON DELETE RESTRICT
            NOT VALID;
    END IF;
END
$$
SQL);

        DB::statement('ALTER TABLE formal_training_documents VALIDATE CONSTRAINT formal_training_documents_course_same_tenant_fk');
        DB::statement('ALTER TABLE formal_training_documents VALIDATE CONSTRAINT formal_training_documents_asset_same_tenant_fk');
        DB::statement('ALTER TABLE formal_training_documents VALIDATE CONSTRAINT formal_training_documents_template_snapshot_fk');

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION s5doc_guard_formal_training_document_immutable()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'formal training document revisions are immutable; create a new revision'
        USING ERRCODE = '23514';
END
$$
SQL);

        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'formal_training_documents'::regclass
          AND tgname = 'formal_training_documents_immutable_guard'
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER formal_training_documents_immutable_guard
        BEFORE UPDATE OR DELETE
        ON formal_training_documents
        FOR EACH ROW
        EXECUTE FUNCTION s5doc_guard_formal_training_document_immutable();
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
