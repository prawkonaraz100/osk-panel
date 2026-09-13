<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-IDX-EVENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-IDX-EVENTS preflight requires PostgreSQL.');
        }

        $this->assertNoDuplicate('outbox_messages', 'outbox_domain_event_unique', ['domain_event_id']);
        $this->assertNoDuplicate('organization_activity_events', 'activity_projection_source_event_unique', ['organization_id', 'source_event_id']);
        $this->assertNoDuplicate('notifications', 'notification_source_recipient_unique', ['organization_id', 'source_event_id', 'organization_membership_id']);
        $this->assertNoDuplicate('event_projection_migration_cases', 'event_projection_migration_case_unique', ['source_table', 'source_row_id', 'issue_code']);
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertNoDuplicate(string $table, string $name, array $columns, ?string $where = null): void
    {
        if (! Schema::hasTable($table)) {
            throw new LogicException("MIG-IDX-EVENTS preflight missing table {$table}.");
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new LogicException("MIG-IDX-EVENTS preflight missing {$table}.{$column} for {$name}.");
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
            throw new LogicException("MIG-IDX-EVENTS preflight found duplicate rows for {$name}; reviewed remediation is required before write-fence.");
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
