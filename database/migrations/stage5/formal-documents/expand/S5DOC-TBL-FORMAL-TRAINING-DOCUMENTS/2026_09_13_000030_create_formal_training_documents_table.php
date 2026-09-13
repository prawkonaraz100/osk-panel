<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'S5DOC-TBL-FORMAL-TRAINING-DOCUMENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('S5DOC-TBL-FORMAL-TRAINING-DOCUMENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('formal_training_documents')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE formal_training_documents (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    document_type varchar(64) NOT NULL,
    revision bigint NOT NULL,
    document_mode_snapshot varchar(16) NOT NULL,
    formal_training_document_template_id uuid NOT NULL,
    template_version_snapshot varchar(64) NOT NULL,
    renderer_version_snapshot varchar(64) NOT NULL,
    template_hash_snapshot char(64) NOT NULL,
    course_version_snapshot bigint NOT NULL,
    requirements_revision_snapshot bigint NOT NULL,
    evidence_bundle_hash char(64) NOT NULL,
    asset_id uuid NOT NULL,
    content_hash char(64) NOT NULL,
    approved_by_user_id uuid NULL,
    approved_at timestamptz NULL,
    generated_by_user_id uuid NULL,
    generated_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL,
    CONSTRAINT formal_training_documents_document_type_check
        CHECK (document_type IN ('training_record_card', 'theory_delivery_journal')),
    CONSTRAINT formal_training_documents_mode_check
        CHECK (document_mode_snapshot IN ('paper', 'electronic')),
    CONSTRAINT formal_training_documents_revision_check
        CHECK (revision > 0),
    CONSTRAINT formal_training_documents_course_version_check
        CHECK (course_version_snapshot > 0),
    CONSTRAINT formal_training_documents_requirements_revision_check
        CHECK (requirements_revision_snapshot > 0),
    CONSTRAINT formal_training_documents_approval_pair_check
        CHECK ((approved_by_user_id IS NULL) = (approved_at IS NULL)),
    CONSTRAINT formal_training_documents_revision_unique
        UNIQUE (organization_id, course_enrollment_id, document_type, revision),
    CONSTRAINT formal_training_documents_evidence_unique
        UNIQUE (
            organization_id,
            course_enrollment_id,
            document_type,
            evidence_bundle_hash,
            template_version_snapshot,
            renderer_version_snapshot
        )
)
SQL);

        if (! Schema::hasTable('formal_training_documents')) {
            throw new LogicException('S5DOC-TBL-FORMAL-TRAINING-DOCUMENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
