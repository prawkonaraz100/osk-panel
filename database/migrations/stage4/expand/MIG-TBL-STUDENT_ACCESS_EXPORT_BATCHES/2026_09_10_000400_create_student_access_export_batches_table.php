<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-STUDENT_ACCESS_EXPORT_BATCHES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-STUDENT_ACCESS_EXPORT_BATCHES requires PostgreSQL.');
        }

        if (Schema::hasTable('student_access_export_batches')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE student_access_export_batches (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    requested_by_user_id uuid NOT NULL,
    export_mode varchar(48) NOT NULL,
    selected_account_count integer NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('student_access_export_batches')) {
            throw new LogicException('MIG-TBL-STUDENT_ACCESS_EXPORT_BATCHES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
