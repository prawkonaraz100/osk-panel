<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('preflight', 'MIG-CK-TRAINING');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-CK-TRAINING preflight requires PostgreSQL.');
        }

        $this->assertCandidateKey('students', 'student_candidate_key_org_id', ['organization_id', 'id'], false);
        $this->assertCandidateKey('student_learning_accounts', 'student_learning_account_candidate_key_org_id', ['organization_id', 'id'], false);
        $this->assertCandidateKey('student_learning_accounts', 'student_learning_account_candidate_key_org_id_student', ['organization_id', 'id', 'student_id'], false);
        $this->assertCandidateKey('course_enrollments', 'course_enrollment_candidate_key_org_id', ['organization_id', 'id'], false);
        $this->assertCandidateKey('course_enrollments', 'course_enrollment_candidate_key_org_id_student', ['organization_id', 'id', 'student_id'], false);
        $this->assertCandidateKey('training_sessions', 'training_session_candidate_key_org_id', ['organization_id', 'id'], false);
    }

    /**
     * @param list<string> $columns
     */
    private function assertCandidateKey(string $table, string $name, array $columns, bool $allowKnownMissingLicenseProductColumn = false): void
    {
        if (! Schema::hasTable($table)) {
            throw new LogicException("MIG-CK-TRAINING preflight missing table {$table}.");
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                if ($allowKnownMissingLicenseProductColumn && $table === 'order_items' && $column === 'license_product_id') {
                    return;
                }

                throw new LogicException("MIG-CK-TRAINING preflight missing {$table}.{$column} for {$name}.");
            }
        }

        $group = implode(', ', array_map(
            static fn (string $column): string => DB::connection()->getQueryGrammar()->wrap($column),
            $columns,
        ));
        $wrappedTable = DB::connection()->getQueryGrammar()->wrapTable($table);

        $duplicate = DB::selectOne("SELECT 1 AS duplicate_found FROM {$wrappedTable} GROUP BY {$group} HAVING COUNT(*) > 1 LIMIT 1");
        if ($duplicate !== null) {
            throw new LogicException("MIG-CK-TRAINING preflight found duplicate rows for {$name}; reviewed remediation is required before write-fence.");
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
