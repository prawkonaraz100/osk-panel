<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-INTERNAL_EXAM_RESULTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_RESULTS requires PostgreSQL.');
        }

        if (Schema::hasTable('internal_exam_results')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE internal_exam_results (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    internal_exam_attempt_id uuid NOT NULL,
    evidence_schema_version integer NOT NULL,
    score integer NULL,
    max_score integer NULL,
    pass_threshold_snapshot integer NULL,
    passed boolean NOT NULL,
    scoring_policy_snapshot jsonb NOT NULL,
    question_set_hash char(64) NULL,
    evidence_bundle_hash char(64) NOT NULL,
    answer_sheet_template_binding_snapshot jsonb NULL,
    result_snapshot jsonb NOT NULL,
    result_snapshot_hash char(64) NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('internal_exam_results')) {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_RESULTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
