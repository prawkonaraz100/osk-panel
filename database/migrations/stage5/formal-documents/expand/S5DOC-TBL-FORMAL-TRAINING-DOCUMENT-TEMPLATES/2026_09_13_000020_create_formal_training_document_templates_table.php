<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-TEMPLATES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-TEMPLATES requires PostgreSQL.');
        }

        if (Schema::hasTable('formal_training_document_templates')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE formal_training_document_templates (
    id uuid PRIMARY KEY,
    document_type varchar(64) NOT NULL,
    template_version varchar(64) NOT NULL,
    renderer_version varchar(64) NOT NULL,
    template_content_hash char(64) NOT NULL,
    effective_from timestamptz NOT NULL,
    effective_to timestamptz NULL,
    created_at timestamptz NOT NULL,
    CONSTRAINT formal_training_document_templates_document_type_check
        CHECK (document_type IN ('training_record_card', 'theory_delivery_journal')),
    CONSTRAINT formal_training_document_templates_effective_range_check
        CHECK (effective_to IS NULL OR effective_to > effective_from),
    CONSTRAINT formal_training_document_templates_version_unique
        UNIQUE (document_type, template_version)
)
SQL);

        if (! Schema::hasTable('formal_training_document_templates')) {
            throw new LogicException('S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-TEMPLATES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
