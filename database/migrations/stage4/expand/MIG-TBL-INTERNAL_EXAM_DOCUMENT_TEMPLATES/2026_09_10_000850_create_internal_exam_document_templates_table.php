<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-INTERNAL_EXAM_DOCUMENT_TEMPLATES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_DOCUMENT_TEMPLATES requires PostgreSQL.');
        }

        if (Schema::hasTable('internal_exam_document_templates')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE internal_exam_document_templates (
    id uuid PRIMARY KEY,
    document_type varchar(64) NOT NULL,
    exam_part varchar(16) NULL,
    template_version varchar(64) NOT NULL,
    renderer_version varchar(64) NOT NULL,
    template_content_hash char(64) NOT NULL,
    effective_from timestamptz NOT NULL,
    effective_to timestamptz NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('internal_exam_document_templates')) {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_DOCUMENT_TEMPLATES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
