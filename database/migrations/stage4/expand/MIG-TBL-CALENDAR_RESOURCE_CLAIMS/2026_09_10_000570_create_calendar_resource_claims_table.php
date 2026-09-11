<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-CALENDAR_RESOURCE_CLAIMS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-CALENDAR_RESOURCE_CLAIMS requires PostgreSQL.');
        }

        if (Schema::hasTable('calendar_resource_claims')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE calendar_resource_claims (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    claim_owner_kind varchar(32) NOT NULL,
    claim_owner_id uuid NOT NULL,
    student_id uuid NULL,
    instructor_id uuid NULL,
    vehicle_id uuid NULL,
    location_id uuid NULL,
    starts_at timestamptz NOT NULL,
    ends_at timestamptz NOT NULL,
    occupied_during tstzrange GENERATED ALWAYS AS (tstzrange(starts_at, ends_at, '[)')) STORED,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('calendar_resource_claims')) {
            throw new LogicException('MIG-TBL-CALENDAR_RESOURCE_CLAIMS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
