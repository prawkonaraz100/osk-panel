<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-VEHICLE_DOCUMENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-VEHICLE_DOCUMENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('vehicle_documents')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE vehicle_documents (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    vehicle_id uuid NOT NULL,
    document_type varchar(64) NOT NULL,
    valid_until date NULL,
    asset_id uuid NULL,
    created_at timestamptz NOT NULL,
    created_by_user_id uuid NULL,
    superseded_at timestamptz NULL,
    superseded_by_user_id uuid NULL,
    supersession_reason text NULL
)
SQL);

        if (! Schema::hasTable('vehicle_documents')) {
            throw new LogicException('MIG-TBL-VEHICLE_DOCUMENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
