<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-CK-IDENTITY');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-CK-IDENTITY preflight requires PostgreSQL.');
        }

        $this->assertCandidateKey('organization_memberships', 'organization_membership_candidate_key_id_user', ['id', 'user_id'], false);
        $this->assertCandidateKey('organization_memberships', 'organization_membership_candidate_key_org_id', ['organization_id', 'id'], false);
        $this->assertCandidateKey('organization_memberships', 'organization_membership_candidate_key_org_id_user', ['organization_id', 'id', 'user_id'], false);
        $this->assertCandidateKey('auth_login_identifiers', 'auth_login_identifier_candidate_key_id_user', ['id', 'user_id'], false);
    }

    /**
     * @param list<string> $columns
     */
    private function assertCandidateKey(string $table, string $name, array $columns, bool $allowKnownMissingLicenseProductColumn = false): void
    {
        if (! Schema::hasTable($table)) {
            throw new LogicException("MIG-CK-IDENTITY preflight missing table {$table}.");
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                if ($allowKnownMissingLicenseProductColumn && $table === 'order_items' && $column === 'license_product_id') {
                    return;
                }

                throw new LogicException("MIG-CK-IDENTITY preflight missing {$table}.{$column} for {$name}.");
            }
        }

        $group = implode(', ', array_map(
            static fn (string $column): string => DB::connection()->getQueryGrammar()->wrap($column),
            $columns,
        ));
        $wrappedTable = DB::connection()->getQueryGrammar()->wrapTable($table);

        $duplicate = DB::selectOne("SELECT 1 AS duplicate_found FROM {$wrappedTable} GROUP BY {$group} HAVING COUNT(*) > 1 LIMIT 1");
        if ($duplicate !== null) {
            throw new LogicException("MIG-CK-IDENTITY preflight found duplicate rows for {$name}; reviewed remediation is required before write-fence.");
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
