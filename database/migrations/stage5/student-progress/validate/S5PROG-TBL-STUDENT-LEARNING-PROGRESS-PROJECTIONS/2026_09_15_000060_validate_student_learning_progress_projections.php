<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive(
            'validate',
            'S5PROG-TBL-STUDENT-LEARNING-PROGRESS-PROJECTIONS',
        );

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Student learning progress projection validation requires PostgreSQL.');
        }

        if (! Schema::hasTable('student_learning_progress_projections')) {
            throw new LogicException('student_learning_progress_projections is missing during validation.');
        }

        $expectedColumns = [
            'id',
            'organization_id',
            'student_id',
            'student_learning_account_id',
            'learning_progress_source_binding_id',
            'driving_category_id',
            'learning_account_version',
            'source_snapshot_ref',
            'source_observed_at',
            'projected_at',
            'projection_version',
            'tests_json',
            'questions_json',
            'handbook_json',
            'lectures_json',
            'topics_json',
            'snapshot_hash',
            'created_at',
            'updated_at',
        ];

        if (Schema::getColumnListing('student_learning_progress_projections') !== $expectedColumns) {
            throw new LogicException('Student learning progress projection column shape mismatch.');
        }

        $requiredConstraints = [
            'student_learning_progress_projections_pkey',
            'student_learning_progress_projections_current_unique',
            'student_learning_progress_projections_account_fk',
            'student_learning_progress_projections_binding_fk',
            'student_learning_progress_projections_category_fk',
            'student_learning_progress_projections_account_version_check',
            'student_learning_progress_projections_projection_version_check',
            'student_learning_progress_projections_snapshot_ref_nonblank',
            'student_learning_progress_projections_snapshot_hash_check',
        ];

        $actualConstraints = DB::table('pg_constraint as c')
            ->join('pg_class as t', 't.oid', '=', 'c.conrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 't.relnamespace')
            ->whereRaw('ns.nspname = current_schema()')
            ->where('t.relname', 'student_learning_progress_projections')
            ->pluck('c.conname')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        sort($requiredConstraints);
        sort($actualConstraints);
        if ($actualConstraints !== $requiredConstraints) {
            throw new LogicException('Student learning progress projection exact constraints mismatch.');
        }

        if (DB::table('student_learning_progress_projections')->exists()) {
            throw new LogicException('Student progress corrective may not backfill fake zero projections.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for the student progress corrective.');
    }
};
