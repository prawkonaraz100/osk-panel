<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-IDX-IDENTITY');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-IDX-IDENTITY preflight requires PostgreSQL.');
        }

        $this->assertNoDuplicate('auth_login_identifiers', 'auth_login_identifier_global_current_unique', ['identifier_normalized'], 'revoked_at IS NULL');
        $this->assertNoDuplicate('auth_login_identifiers', 'auth_login_identifier_primary_current_unique_per_user_type', ['user_id', 'identifier_type'], 'revoked_at IS NULL AND is_primary_for_type = true');
        $this->assertNoDuplicate('organization_memberships', 'organization_membership_unique_org_user', ['organization_id', 'user_id']);
        $this->assertNoDuplicate('membership_permissions', 'membership_permission_unique', ['membership_id', 'permission_code']);
        $this->assertNoDuplicate('membership_permission_scopes', 'membership_permission_scope_unique', ['membership_id', 'permission_code', 'scope_code']);
        $this->assertNoDuplicate('account_closure_requests', 'account_closure_pending_unique_organization_scope', ['user_id', 'organization_id'], "status = 'pending' AND organization_id IS NOT NULL");
        $this->assertNoDuplicate('account_closure_requests', 'account_closure_pending_unique_global_scope', ['user_id'], "status = 'pending' AND organization_id IS NULL");
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertNoDuplicate(string $table, string $name, array $columns, ?string $where = null): void
    {
        if (! Schema::hasTable($table)) {
            throw new LogicException("MIG-IDX-IDENTITY preflight missing table {$table}.");
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new LogicException("MIG-IDX-IDENTITY preflight missing {$table}.{$column} for {$name}.");
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
            throw new LogicException("MIG-IDX-IDENTITY preflight found duplicate rows for {$name}; reviewed remediation is required before write-fence.");
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
