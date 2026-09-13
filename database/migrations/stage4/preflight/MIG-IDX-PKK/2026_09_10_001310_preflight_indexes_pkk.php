<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-IDX-PKK');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-IDX-PKK preflight requires PostgreSQL.');
        }

        $this->assertNoDuplicate('pkk_profiles', 'pkk_one_current_profile_per_course', ['organization_id', 'course_enrollment_id'], 'superseded_at IS NULL');
        $this->assertNoDuplicate('pkk_operations', 'pkk_one_active_operation_per_profile', ['organization_id', 'pkk_profile_id'], "business_status IN ('draft', 'pending', 'requires_signature', 'submitted')");
        $this->assertNoDuplicate('pkk_operations', 'pkk_operation_course_sequence_unique', ['organization_id', 'course_enrollment_id', 'course_operation_sequence']);
        $this->assertNoDuplicate('pkk_operation_attempts', 'pkk_provider_attempt_idempotency_unique', ['organization_id', 'command_idempotency_record_id'], 'command_idempotency_record_id IS NOT NULL');
        $this->assertNoDuplicate('pkk_operation_attempts', 'pkk_operation_attempt_sequence_unique', ['organization_id', 'pkk_operation_id', 'attempt_no']);
        $this->assertNoDuplicate('pkk_operation_attempts', 'pkk_one_replay_blocking_attempt_per_operation', ['organization_id', 'pkk_operation_id'], "attempt_dispatch_status IN ('prepared', 'dispatching', 'effect_unknown')");
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertNoDuplicate(string $table, string $name, array $columns, ?string $where = null): void
    {
        if (! Schema::hasTable($table)) {
            throw new LogicException("MIG-IDX-PKK preflight missing table {$table}.");
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new LogicException("MIG-IDX-PKK preflight missing {$table}.{$column} for {$name}.");
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
            throw new LogicException("MIG-IDX-PKK preflight found duplicate rows for {$name}; reviewed remediation is required before write-fence.");
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
