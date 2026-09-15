<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-COURSE_REQUIREMENT_OVERRIDE_DECISIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-COURSE_REQUIREMENT_OVERRIDE_DECISIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('course_requirement_override_decisions')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE course_requirement_override_decisions (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    override_payload jsonb NOT NULL,
    reason text NOT NULL,
    evidence_reference varchar(255) NULL,
    approved_by_user_id uuid NOT NULL,
    created_at timestamptz NOT NULL,
    revoked_at timestamptz NULL,
    revoked_by_user_id uuid NULL,
    revocation_reason text NULL
)
SQL);

        if (! Schema::hasTable('course_requirement_override_decisions')) {
            throw new LogicException('MIG-TBL-COURSE_REQUIREMENT_OVERRIDE_DECISIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
