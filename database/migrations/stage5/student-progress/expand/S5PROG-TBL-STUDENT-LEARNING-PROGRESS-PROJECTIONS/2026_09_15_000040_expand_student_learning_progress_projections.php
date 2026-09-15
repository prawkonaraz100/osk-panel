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
            'expand',
            'S5PROG-TBL-STUDENT-LEARNING-PROGRESS-PROJECTIONS',
        );

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Student learning progress projection expand requires PostgreSQL.');
        }

        if (! Schema::hasTable('learning_progress_source_bindings')) {
            throw new LogicException('Canonical learning progress source-binding table must exist before projection expand.');
        }

        if (Schema::hasTable('student_learning_progress_projections')) {
            throw new LogicException('student_learning_progress_projections already exists; refusing to adopt an unregistered table.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE student_learning_progress_projections (
    id uuid NOT NULL,
    organization_id uuid NOT NULL,
    student_id uuid NOT NULL,
    student_learning_account_id uuid NOT NULL,
    learning_progress_source_binding_id uuid NOT NULL,
    driving_category_id uuid NOT NULL,
    learning_account_version bigint NOT NULL,
    source_snapshot_ref varchar(191) NOT NULL,
    source_observed_at timestamptz NOT NULL,
    projected_at timestamptz NOT NULL,
    projection_version bigint NOT NULL DEFAULT 1,
    tests_json jsonb NOT NULL,
    questions_json jsonb NOT NULL,
    handbook_json jsonb NOT NULL,
    lectures_json jsonb NOT NULL,
    topics_json jsonb NOT NULL,
    snapshot_hash char(64) NOT NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    CONSTRAINT student_learning_progress_projections_pkey PRIMARY KEY (id),
    CONSTRAINT student_learning_progress_projections_current_unique
        UNIQUE (organization_id, student_learning_account_id, driving_category_id),
    CONSTRAINT student_learning_progress_projections_account_fk
        FOREIGN KEY (organization_id, student_learning_account_id, student_id)
        REFERENCES student_learning_accounts (organization_id, id, student_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT student_learning_progress_projections_binding_fk
        FOREIGN KEY (organization_id, learning_progress_source_binding_id, student_learning_account_id, student_id)
        REFERENCES learning_progress_source_bindings (organization_id, id, student_learning_account_id, student_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT student_learning_progress_projections_category_fk
        FOREIGN KEY (driving_category_id)
        REFERENCES driving_categories (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT student_learning_progress_projections_account_version_check CHECK (learning_account_version >= 1),
    CONSTRAINT student_learning_progress_projections_projection_version_check CHECK (projection_version >= 1),
    CONSTRAINT student_learning_progress_projections_snapshot_ref_nonblank CHECK (NULLIF(BTRIM(source_snapshot_ref), '') IS NOT NULL),
    CONSTRAINT student_learning_progress_projections_snapshot_hash_check CHECK (snapshot_hash ~ '^[0-9a-f]{64}$')
)
SQL);

        if (! Schema::hasTable('student_learning_progress_projections')) {
            throw new LogicException('Student learning progress projection expand postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for the student progress corrective.');
    }
};
