<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-IDX-FINANCE');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-IDX-FINANCE preflight requires PostgreSQL.');
        }

        $this->assertLegacyPaymentIdempotencyIfPresent();
        $this->assertNoDuplicate('course_cost_charge_origins', 'course_cost_charge_origin_unique_per_course', ['organization_id', 'course_enrollment_id']);
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertNoDuplicate(string $table, string $name, array $columns, ?string $where = null): void
    {
        if (! Schema::hasTable($table)) {
            throw new LogicException("MIG-IDX-FINANCE preflight missing table {$table}.");
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new LogicException("MIG-IDX-FINANCE preflight missing {$table}.{$column} for {$name}.");
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
            throw new LogicException("MIG-IDX-FINANCE preflight found duplicate rows for {$name}; reviewed remediation is required before write-fence.");
        }
    }

    private function assertLegacyPaymentIdempotencyIfPresent(): void
    {
        if (! Schema::hasTable('student_payments')) {
            throw new LogicException('MIG-IDX-FINANCE preflight missing table student_payments.');
        }

        if (! Schema::hasColumn('student_payments', 'idempotency_key')) {
            return;
        }

        $this->assertNoDuplicate(
            'student_payments',
            'student_payment_idempotency_unique_legacy_compatibility_only',
            ['organization_id', 'idempotency_key'],
            'idempotency_key IS NOT NULL',
        );
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
