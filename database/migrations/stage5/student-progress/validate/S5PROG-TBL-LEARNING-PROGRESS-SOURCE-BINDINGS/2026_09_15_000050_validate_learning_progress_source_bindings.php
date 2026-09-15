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
            'S5PROG-TBL-LEARNING-PROGRESS-SOURCE-BINDINGS',
        );

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Student progress source-binding validation requires PostgreSQL.');
        }

        if (! Schema::hasTable('learning_progress_source_bindings')) {
            throw new LogicException('learning_progress_source_bindings is missing during validation.');
        }

        $expectedColumns = [
            'id',
            'organization_id',
            'student_id',
            'student_learning_account_id',
            'source_system',
            'source_subject_ref',
            'source_access_ref',
            'status',
            'version',
            'bound_at',
            'revoked_at',
            'created_at',
            'updated_at',
        ];

        if (Schema::getColumnListing('learning_progress_source_bindings') !== $expectedColumns) {
            throw new LogicException('Student progress source-binding column shape mismatch.');
        }

        $requiredConstraints = [
            'learning_progress_source_bindings_pkey',
            'learning_progress_source_bindings_account_unique',
            'learning_progress_source_bindings_source_access_unique',
            'learning_progress_source_bindings_exact_account_key',
            'learning_progress_source_bindings_account_fk',
            'learning_progress_source_bindings_status_check',
            'learning_progress_source_bindings_version_check',
            'learning_progress_source_bindings_source_system_nonblank',
            'learning_progress_source_bindings_source_subject_nonblank',
            'learning_progress_source_bindings_source_access_nonblank',
        ];

        $actualConstraints = DB::table('pg_constraint as c')
            ->join('pg_class as t', 't.oid', '=', 'c.conrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 't.relnamespace')
            ->whereRaw('ns.nspname = current_schema()')
            ->where('t.relname', 'learning_progress_source_bindings')
            ->pluck('c.conname')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        sort($requiredConstraints);
        sort($actualConstraints);
        if ($actualConstraints !== $requiredConstraints) {
            throw new LogicException('Student progress source-binding exact constraints mismatch.');
        }

        if (DB::table('learning_progress_source_bindings')->exists()) {
            throw new LogicException('Student progress corrective may not infer or backfill legacy source bindings.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for the student progress corrective.');
    }
};
