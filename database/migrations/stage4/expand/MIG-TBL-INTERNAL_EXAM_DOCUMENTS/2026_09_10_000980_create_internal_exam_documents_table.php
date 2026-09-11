<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-INTERNAL_EXAM_DOCUMENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_DOCUMENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('internal_exam_documents')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE internal_exam_documents (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    internal_exam_attempt_id uuid NOT NULL,
    document_type varchar(64) NOT NULL,
    internal_exam_document_template_id uuid NOT NULL,
    template_version_snapshot varchar(64) NOT NULL,
    renderer_version_snapshot varchar(64) NOT NULL,
    template_hash_snapshot char(64) NOT NULL,
    evidence_bundle_hash char(64) NOT NULL,
    asset_id uuid NOT NULL,
    content_hash char(64) NOT NULL,
    generated_at timestamptz NOT NULL,
    generated_by_user_id uuid NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('internal_exam_documents')) {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_DOCUMENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
