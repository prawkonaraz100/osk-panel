<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-IDX-TRAINING');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-IDX-TRAINING preflight requires PostgreSQL.');
        }

        $this->assertNoDuplicate('students', 'student_pesel_unique_per_organization_including_archived', ['organization_id', 'pesel_lookup_hash'], 'pesel_lookup_hash IS NOT NULL');
        $this->assertNoDuplicate('training_requirement_profiles', 'training_requirement_profile_current_unique', ['organization_id', 'course_enrollment_id'], 'superseded_at IS NULL');
        $this->assertNoDuplicate('recognized_external_training', 'recognized_external_training_one_successor_per_source', ['organization_id', 'supersedes_record_id'], 'supersedes_record_id IS NOT NULL');
        $this->assertNoDuplicate('recognized_external_training', 'recognized_external_training_current_course_form_projection_unique', ['organization_id', 'course_enrollment_id', 'training_part'], "record_role = 'course_form_projection' AND superseded_at IS NULL AND revoked_at IS NULL");
        $this->assertNoDuplicate('training_hour_ledger_entries', 'training_hour_ledger_base_credit_unique', ['organization_id', 'training_session_id'], "entry_type = 'credit'");
        $this->assertNoDuplicate('training_hour_ledger_entries', 'training_hour_ledger_opening_balance_unique', ['organization_id', 'course_enrollment_id', 'training_part'], "entry_type = 'opening_balance'");
        $this->assertNoDuplicate('training_hour_ledger_entries', 'training_hour_ledger_reversal_unique', ['organization_id', 'source_entry_id'], "entry_type = 'reversal'");
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertNoDuplicate(string $table, string $name, array $columns, ?string $where = null): void
    {
        if (! Schema::hasTable($table)) {
            throw new LogicException("MIG-IDX-TRAINING preflight missing table {$table}.");
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new LogicException("MIG-IDX-TRAINING preflight missing {$table}.{$column} for {$name}.");
            }
        }

        $grammar = DB::connection()->getQueryGrammar();
        $wrappedColumns = array_map(
            static fn (string $column): string => $grammar->wrap($column),
            $columns,
        );
        $group = implode(', ', $wrappedColumns);
        $wrappedTable = $grammar->wrapTable($table);
        $predicate = $where ?? 'TRUE';

        $duplicate = DB::selectOne(
            "SELECT 1 AS duplicate_found FROM {$wrappedTable} WHERE {$predicate} GROUP BY {$group} HAVING COUNT(*) > 1 LIMIT 1",
        );
        if ($duplicate !== null) {
            throw new LogicException("MIG-IDX-TRAINING preflight found duplicate rows for {$name}; reviewed remediation is required before write-fence.");
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
