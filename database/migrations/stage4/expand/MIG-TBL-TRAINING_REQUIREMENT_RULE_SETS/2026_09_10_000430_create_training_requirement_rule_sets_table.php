<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-TRAINING_REQUIREMENT_RULE_SETS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-TRAINING_REQUIREMENT_RULE_SETS requires PostgreSQL.');
        }

        if (Schema::hasTable('training_requirement_rule_sets')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE training_requirement_rule_sets (
    version varchar(64) PRIMARY KEY,
    jurisdiction varchar(16) NOT NULL,
    content_hash char(64) NOT NULL,
    source_reference varchar(255) NULL,
    effective_from timestamptz NULL,
    published_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('training_requirement_rule_sets')) {
            throw new LogicException('MIG-TBL-TRAINING_REQUIREMENT_RULE_SETS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
