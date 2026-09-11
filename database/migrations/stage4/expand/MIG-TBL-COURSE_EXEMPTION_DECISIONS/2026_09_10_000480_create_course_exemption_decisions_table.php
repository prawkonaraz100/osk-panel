<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-COURSE_EXEMPTION_DECISIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-COURSE_EXEMPTION_DECISIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('course_exemption_decisions')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE course_exemption_decisions (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    basis_code varchar(128) NOT NULL,
    evidence_reference varchar(255) NULL,
    reason text NULL,
    approved_by_user_id uuid NOT NULL,
    rule_set_version varchar(64) NOT NULL,
    created_at timestamptz NOT NULL,
    revoked_at timestamptz NULL,
    revoked_by_user_id uuid NULL,
    revocation_reason text NULL
)
SQL);

        if (! Schema::hasTable('course_exemption_decisions')) {
            throw new LogicException('MIG-TBL-COURSE_EXEMPTION_DECISIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
