<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive(
            'preflight',
            'S5PROG-TBL-LEARNING-PROGRESS-SOURCE-BINDINGS',
        );

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Student progress source-binding preflight requires PostgreSQL.');
        }

        foreach (['students', 'student_learning_accounts'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new LogicException($table.' must exist before student progress source-binding materialization.');
            }
        }

        $missingCandidateKey = DB::selectOne(<<<'SQL'
SELECT 1
FROM pg_constraint c
JOIN pg_class t ON t.oid = c.conrelid
JOIN pg_namespace ns ON ns.oid = t.relnamespace
WHERE ns.nspname = current_schema()
  AND t.relname = 'student_learning_accounts'
  AND c.contype = 'u'
  AND c.conname = 'student_learning_account_candidate_key_org_id_student'
LIMIT 1
SQL);

        if ($missingCandidateKey === null) {
            throw new LogicException('Exact StudentLearningAccount tenant/student candidate key must exist before progress binding materialization.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for the student progress corrective.');
    }
};
