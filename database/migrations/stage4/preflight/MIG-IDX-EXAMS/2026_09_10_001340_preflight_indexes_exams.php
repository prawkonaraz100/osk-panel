<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-IDX-EXAMS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-IDX-EXAMS preflight requires PostgreSQL.');
        }

        $this->assertNoDuplicate('internal_exam_inventory_ledger_entries', 'internal_exam_inventory_ledger_sequence_unique', ['organization_id', 'internal_exam_inventory_entry_id', 'event_sequence']);
        $this->assertNoDuplicate('internal_exam_attempts', 'internal_exam_course_attempt_sequence_unique', ['organization_id', 'course_enrollment_id', 'course_attempt_sequence']);
        $this->assertNoDuplicate('internal_exam_reservations', 'internal_exam_one_active_reservation_per_attempt', ['organization_id', 'internal_exam_attempt_id'], "status = 'reserved'");
        $this->assertNoDuplicate('internal_exam_reservations', 'internal_exam_one_consumed_reservation_per_attempt', ['organization_id', 'internal_exam_attempt_id'], "status = 'consumed'");
        $this->assertNoDuplicate('internal_exam_accesses', 'internal_exam_one_nonterminal_access_per_attempt', ['organization_id', 'internal_exam_attempt_id'], "status IN ('draft', 'ready', 'delivered_or_assigned', 'opened', 'started')");
        $this->assertNoDuplicate('internal_exam_accesses', 'internal_exam_one_started_access_per_attempt', ['organization_id', 'internal_exam_attempt_id'], 'started_at IS NOT NULL');
        $this->assertNoDuplicate('internal_exam_station_sessions', 'internal_exam_one_active_station_session_per_attempt', ['organization_id', 'internal_exam_attempt_id'], 'ended_at IS NULL');
        $this->assertNoDuplicate('internal_exam_station_sessions', 'internal_exam_one_active_station_session_per_station', ['organization_id', 'exam_station_id'], 'ended_at IS NULL');
        $this->assertNoDuplicate('internal_exam_station_sessions', 'internal_exam_station_session_sequence_unique', ['organization_id', 'internal_exam_attempt_id', 'session_sequence']);
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertNoDuplicate(string $table, string $name, array $columns, ?string $where = null): void
    {
        if (! Schema::hasTable($table)) {
            throw new LogicException("MIG-IDX-EXAMS preflight missing table {$table}.");
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new LogicException("MIG-IDX-EXAMS preflight missing {$table}.{$column} for {$name}.");
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
            throw new LogicException("MIG-IDX-EXAMS preflight found duplicate rows for {$name}; reviewed remediation is required before write-fence.");
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
