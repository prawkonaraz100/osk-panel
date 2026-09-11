<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-STUDENT_ACCESS_HANDOFFS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-STUDENT_ACCESS_HANDOFFS requires PostgreSQL.');
        }

        if (Schema::hasTable('student_access_handoffs')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE student_access_handoffs (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    student_learning_account_id uuid NOT NULL,
    handoff_type varchar(32) NOT NULL,
    generated_by_user_id uuid NOT NULL,
    document_asset_id uuid NULL,
    credential_version_snapshot bigint NOT NULL DEFAULT 0,
    contains_fresh_secret boolean NOT NULL DEFAULT false,
    fresh_secret_issued_at timestamptz NULL,
    batch_id uuid NULL,
    batch_ordinal integer NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('student_access_handoffs')) {
            throw new LogicException('MIG-TBL-STUDENT_ACCESS_HANDOFFS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
