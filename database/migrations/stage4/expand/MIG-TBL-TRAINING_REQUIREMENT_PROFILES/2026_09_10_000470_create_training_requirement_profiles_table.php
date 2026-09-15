<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-TRAINING_REQUIREMENT_PROFILES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-TRAINING_REQUIREMENT_PROFILES requires PostgreSQL.');
        }

        if (Schema::hasTable('training_requirement_profiles')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE training_requirement_profiles (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    requirements_revision bigint NOT NULL,
    course_version_after bigint NOT NULL,
    rule_set_version varchar(64) NOT NULL,
    trigger_code varchar(64) NOT NULL,
    calculation_reason text NULL,
    calculated_by_user_id uuid NULL,
    input_snapshot jsonb NOT NULL,
    base_output_snapshot jsonb NOT NULL,
    effective_output_snapshot jsonb NOT NULL,
    manual_override_decision_id uuid NULL,
    theory_training_required boolean NOT NULL,
    minimum_theory_minutes integer NOT NULL,
    internal_theory_exam_required boolean NOT NULL,
    practical_training_required boolean NOT NULL,
    minimum_practical_minutes integer NOT NULL,
    internal_practical_exam_required boolean NOT NULL,
    exemption_basis_code varchar(128) NULL,
    calculated_at timestamptz NOT NULL,
    superseded_at timestamptz NULL
)
SQL);

        if (! Schema::hasTable('training_requirement_profiles')) {
            throw new LogicException('MIG-TBL-TRAINING_REQUIREMENT_PROFILES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
