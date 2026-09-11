<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-AVAILABILITY_SLOTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-AVAILABILITY_SLOTS requires PostgreSQL.');
        }

        if (Schema::hasTable('availability_slots')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE availability_slots (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    instructor_id uuid NULL,
    vehicle_id uuid NULL,
    location_id uuid NULL,
    starts_at timestamptz NOT NULL,
    ends_at timestamptz NOT NULL,
    status varchar(32) NOT NULL DEFAULT 'available',
    booked_student_id uuid NULL,
    booked_at timestamptz NULL,
    training_session_id uuid NULL,
    version bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('availability_slots')) {
            throw new LogicException('MIG-TBL-AVAILABILITY_SLOTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
