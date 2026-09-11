<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-INTERNAL_EXAM_CAPABILITIES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_CAPABILITIES requires PostgreSQL.');
        }

        if (Schema::hasTable('internal_exam_capabilities')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE internal_exam_capabilities (
    id uuid PRIMARY KEY,
    driving_category_id uuid NOT NULL,
    exam_part varchar(16) NOT NULL,
    language_code varchar(16) NOT NULL,
    enabled_at timestamptz NOT NULL,
    disabled_at timestamptz NULL,
    source_reference varchar(255) NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('internal_exam_capabilities')) {
            throw new LogicException('MIG-TBL-INTERNAL_EXAM_CAPABILITIES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
