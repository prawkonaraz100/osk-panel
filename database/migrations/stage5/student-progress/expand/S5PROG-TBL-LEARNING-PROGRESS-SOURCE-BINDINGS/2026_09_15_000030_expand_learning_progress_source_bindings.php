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
            'S5PROG-TBL-LEARNING-PROGRESS-SOURCE-BINDINGS',
        );

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Student progress source-binding expand requires PostgreSQL.');
        }

        if (Schema::hasTable('learning_progress_source_bindings')) {
            throw new LogicException('learning_progress_source_bindings already exists; refusing to adopt an unregistered table.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE learning_progress_source_bindings (
    id uuid NOT NULL,
    organization_id uuid NOT NULL,
    student_id uuid NOT NULL,
    student_learning_account_id uuid NOT NULL,
    source_system varchar(64) NOT NULL,
    source_subject_ref varchar(191) NOT NULL,
    source_access_ref varchar(191) NOT NULL,
    status varchar(32) NOT NULL DEFAULT 'active',
    version bigint NOT NULL DEFAULT 1,
    bound_at timestamptz NOT NULL,
    revoked_at timestamptz NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    CONSTRAINT learning_progress_source_bindings_pkey PRIMARY KEY (id),
    CONSTRAINT learning_progress_source_bindings_account_unique UNIQUE (organization_id, student_learning_account_id),
    CONSTRAINT learning_progress_source_bindings_source_access_unique UNIQUE (source_system, source_access_ref),
    CONSTRAINT learning_progress_source_bindings_exact_account_key UNIQUE (organization_id, id, student_learning_account_id, student_id),
    CONSTRAINT learning_progress_source_bindings_account_fk
        FOREIGN KEY (organization_id, student_learning_account_id, student_id)
        REFERENCES student_learning_accounts (organization_id, id, student_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT learning_progress_source_bindings_status_check CHECK (status IN ('active', 'revoked')),
    CONSTRAINT learning_progress_source_bindings_version_check CHECK (version >= 1),
    CONSTRAINT learning_progress_source_bindings_source_system_nonblank CHECK (NULLIF(BTRIM(source_system), '') IS NOT NULL),
    CONSTRAINT learning_progress_source_bindings_source_subject_nonblank CHECK (NULLIF(BTRIM(source_subject_ref), '') IS NOT NULL),
    CONSTRAINT learning_progress_source_bindings_source_access_nonblank CHECK (NULLIF(BTRIM(source_access_ref), '') IS NOT NULL)
)
SQL);

        if (! Schema::hasTable('learning_progress_source_bindings')) {
            throw new LogicException('Student progress source-binding expand postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for the student progress corrective.');
    }
};
