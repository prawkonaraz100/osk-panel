<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-EXAM_STATION_CREDENTIALS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-EXAM_STATION_CREDENTIALS requires PostgreSQL.');
        }

        if (Schema::hasTable('exam_station_credentials')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE exam_station_credentials (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    exam_station_id uuid NOT NULL,
    credential_sequence bigint NOT NULL,
    lookup_id uuid NOT NULL,
    secret_verifier char(64) NOT NULL,
    verifier_key_version integer NOT NULL,
    issued_at timestamptz NOT NULL,
    revoked_at timestamptz NULL,
    revoke_reason_code varchar(64) NULL,
    issued_by_user_id uuid NOT NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('exam_station_credentials')) {
            throw new LogicException('MIG-TBL-EXAM_STATION_CREDENTIALS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
