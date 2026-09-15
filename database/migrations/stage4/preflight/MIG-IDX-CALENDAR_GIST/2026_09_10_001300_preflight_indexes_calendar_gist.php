<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-IDX-CALENDAR_GIST');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-IDX-CALENDAR_GIST preflight requires PostgreSQL.');
        }

        $this->assertExtension('btree_gist');
        $this->assertNoOverlap('student_id', 'calendar_claim_student_no_overlap');
        $this->assertNoOverlap('instructor_id', 'calendar_claim_instructor_no_overlap');
        $this->assertNoOverlap('vehicle_id', 'calendar_claim_vehicle_no_overlap');
        $this->assertNoOverlap('location_id', 'calendar_claim_location_no_overlap');
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertNoDuplicate(string $table, string $name, array $columns, ?string $where = null): void
    {
        if (! Schema::hasTable($table)) {
            throw new LogicException("MIG-IDX-CALENDAR_GIST preflight missing table {$table}.");
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new LogicException("MIG-IDX-CALENDAR_GIST preflight missing {$table}.{$column} for {$name}.");
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
            throw new LogicException("MIG-IDX-CALENDAR_GIST preflight found duplicate rows for {$name}; reviewed remediation is required before write-fence.");
        }
    }

    private function assertExtension(string $extension): void
    {
        $row = DB::selectOne('SELECT 1 AS extension_found FROM pg_extension WHERE extname = ?', [$extension]);

        if ($row === null) {
            throw new LogicException("MIG-IDX-CALENDAR_GIST preflight missing required PostgreSQL extension {$extension}.");
        }
    }

    private function assertNoOverlap(string $resourceColumn, string $name): void
    {
        if (! Schema::hasTable('calendar_resource_claims')) {
            throw new LogicException('MIG-IDX-CALENDAR_GIST preflight missing table calendar_resource_claims.');
        }

        foreach (['id', 'organization_id', $resourceColumn, 'occupied_during'] as $column) {
            if (! Schema::hasColumn('calendar_resource_claims', $column)) {
                throw new LogicException("MIG-IDX-CALENDAR_GIST preflight missing calendar_resource_claims.{$column} for {$name}.");
            }
        }

        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable('calendar_resource_claims');
        $resource = $grammar->wrap($resourceColumn);

        $overlap = DB::selectOne(
            "SELECT 1 AS overlap_found FROM {$table} AS a JOIN {$table} AS b ON a.organization_id = b.organization_id AND a.{$resource} = b.{$resource} AND a.id < b.id AND a.occupied_during && b.occupied_during WHERE a.{$resource} IS NOT NULL LIMIT 1",
        );

        if ($overlap !== null) {
            throw new LogicException("MIG-IDX-CALENDAR_GIST preflight found overlapping {$name}; reviewed remediation is required before write-fence.");
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
