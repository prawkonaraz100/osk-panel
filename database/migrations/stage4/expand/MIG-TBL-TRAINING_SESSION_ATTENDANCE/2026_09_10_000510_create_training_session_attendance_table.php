<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-TRAINING_SESSION_ATTENDANCE');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-TRAINING_SESSION_ATTENDANCE requires PostgreSQL.');
        }

        if (Schema::hasTable('training_session_attendance')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE training_session_attendance (
    organization_id uuid NOT NULL,
    training_session_id uuid NOT NULL,
    course_enrollment_id uuid NOT NULL,
    student_id uuid NOT NULL,
    status varchar(32) NOT NULL,
    confirmed_by_user_id uuid NULL,
    confirmed_at timestamptz NULL
)
SQL);

        if (! Schema::hasTable('training_session_attendance')) {
            throw new LogicException('MIG-TBL-TRAINING_SESSION_ATTENDANCE postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
