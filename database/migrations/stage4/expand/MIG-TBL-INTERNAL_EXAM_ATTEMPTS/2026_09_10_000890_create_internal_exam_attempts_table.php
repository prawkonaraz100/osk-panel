<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-INTERNAL_EXAM_ATTEMPTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_ATTEMPTS requires PostgreSQL.');
        }

        if (Schema::hasTable('internal_exam_attempts')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE internal_exam_attempts (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    student_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    exam_part varchar(16) NOT NULL,
    driving_category_id uuid NOT NULL,
    language_code varchar(16) NOT NULL,
    candidate_snapshot jsonb NOT NULL,
    training_requirement_profile_id uuid NOT NULL,
    requirements_revision bigint NOT NULL,
    internal_exam_capability_id uuid NOT NULL,
    requirement_basis_snapshot jsonb NOT NULL,
    course_attempt_sequence bigint NOT NULL,
    status varchar(32) NOT NULL,
    version bigint NOT NULL DEFAULT 1,
    started_at timestamptz NULL,
    finished_at timestamptz NULL,
    technical_aborted_at timestamptz NULL,
    invalidated_at timestamptz NULL,
    internal_exam_definition_id uuid NULL,
    exam_definition_version_snapshot varchar(64) NULL,
    exam_definition_hash_snapshot char(64) NULL,
    evidence_schema_version integer NULL,
    question_set_hash char(64) NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('internal_exam_attempts')) {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_ATTEMPTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
