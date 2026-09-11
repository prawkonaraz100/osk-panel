<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-STUDENT_LEARNING_ACCOUNTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-STUDENT_LEARNING_ACCOUNTS requires PostgreSQL.');
        }

        if (Schema::hasTable('student_learning_accounts')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE student_learning_accounts (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    student_id uuid NOT NULL,
    user_id uuid NOT NULL,
    auth_login_identifier_id uuid NOT NULL,
    language_code varchar(16) NOT NULL,
    status varchar(32) NOT NULL DEFAULT 'active',
    version bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('student_learning_accounts')) {
            throw new LogicException('MIG-TBL-STUDENT_LEARNING_ACCOUNTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
