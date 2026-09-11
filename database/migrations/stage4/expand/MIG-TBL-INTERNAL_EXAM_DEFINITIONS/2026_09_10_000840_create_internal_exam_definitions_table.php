<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-INTERNAL_EXAM_DEFINITIONS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_DEFINITIONS requires PostgreSQL.');
        }

        if (Schema::hasTable('internal_exam_definitions')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE internal_exam_definitions (
    id uuid PRIMARY KEY,
    driving_category_id uuid NOT NULL,
    exam_part varchar(16) NOT NULL,
    language_code varchar(16) NOT NULL,
    engine_kind varchar(32) NOT NULL,
    definition_version varchar(64) NOT NULL,
    definition_schema_version integer NOT NULL,
    composition_snapshot jsonb NOT NULL,
    scoring_policy_snapshot jsonb NOT NULL,
    definition_content_hash char(64) NOT NULL,
    published_at timestamptz NOT NULL,
    retired_at timestamptz NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('internal_exam_definitions')) {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_DEFINITIONS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
