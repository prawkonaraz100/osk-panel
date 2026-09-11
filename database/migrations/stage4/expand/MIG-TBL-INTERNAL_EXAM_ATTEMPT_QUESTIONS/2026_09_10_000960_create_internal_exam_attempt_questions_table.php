<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-INTERNAL_EXAM_ATTEMPT_QUESTIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_ATTEMPT_QUESTIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('internal_exam_attempt_questions')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE internal_exam_attempt_questions (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    internal_exam_attempt_id uuid NOT NULL,
    ordinal integer NOT NULL,
    "group" varchar(32) NOT NULL,
    source_question_identifier varchar(128) NULL,
    source_question_revision_identifier varchar(128) NULL,
    question_snapshot_schema_version integer NOT NULL,
    question_snapshot jsonb NOT NULL,
    question_snapshot_hash char(64) NOT NULL,
    media_evidence_snapshot jsonb NULL,
    max_points_snapshot integer NOT NULL,
    candidate_answer jsonb NULL,
    is_correct boolean NULL,
    points_awarded integer NULL,
    answered_at timestamptz NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('internal_exam_attempt_questions')) {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_ATTEMPT_QUESTIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
