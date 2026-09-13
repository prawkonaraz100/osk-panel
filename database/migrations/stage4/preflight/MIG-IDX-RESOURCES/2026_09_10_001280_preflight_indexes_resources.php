<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-IDX-RESOURCES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-IDX-RESOURCES preflight requires PostgreSQL.');
        }

        $this->assertNoDuplicate('staff_membership_links', 'staff_active_membership_link_unique_per_profile', ['staff_profile_id'], 'unlinked_at IS NULL');
        $this->assertNoDuplicate('staff_membership_links', 'staff_active_membership_link_unique_per_membership', ['organization_membership_id'], 'unlinked_at IS NULL');
        $this->assertNoDuplicate('staff_profiles', 'staff_pesel_unique_per_organization_including_archived', ['organization_id', 'pesel_lookup_hash'], 'pesel_lookup_hash IS NOT NULL');
        $this->assertNoDuplicate('staff_documents', 'staff_document_current_unique', ['organization_id', 'staff_profile_id', 'document_type'], 'superseded_at IS NULL');
        $this->assertNoDuplicate('vehicles', 'vehicle_vin_unique_per_organization_including_archived', ['organization_id', 'vin_normalized'], 'vin_normalized IS NOT NULL');
        $this->assertNoDuplicate('vehicles', 'vehicle_registration_unique_per_organization_current_fleet', ['organization_id', 'registration_number_normalized'], 'archived_at IS NULL');
        $this->assertNoDuplicate('vehicle_documents', 'vehicle_document_current_unique', ['organization_id', 'vehicle_id', 'document_type'], 'superseded_at IS NULL');
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertNoDuplicate(string $table, string $name, array $columns, ?string $where = null): void
    {
        if (! Schema::hasTable($table)) {
            throw new LogicException("MIG-IDX-RESOURCES preflight missing table {$table}.");
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new LogicException("MIG-IDX-RESOURCES preflight missing {$table}.{$column} for {$name}.");
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
            throw new LogicException("MIG-IDX-RESOURCES preflight found duplicate rows for {$name}; reviewed remediation is required before write-fence.");
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
