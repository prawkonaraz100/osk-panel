<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-ORGANIZATION_SETTINGS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-ORGANIZATION_SETTINGS requires PostgreSQL.');
        }

        if (Schema::hasTable('organization_settings')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE organization_settings (
    organization_id uuid PRIMARY KEY,
    default_language_code varchar(16) NULL,
    preferences jsonb NULL,
    version integer NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('organization_settings')) {
            throw new LogicException('MIG-TBL-ORGANIZATION_SETTINGS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
