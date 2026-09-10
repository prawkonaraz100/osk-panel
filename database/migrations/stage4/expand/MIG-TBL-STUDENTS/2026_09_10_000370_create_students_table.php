<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-STUDENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-STUDENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('students')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE students (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    first_name varchar(120) NOT NULL,
    last_name varchar(120) NOT NULL,
    birth_date date NULL,
    no_pesel_declared boolean NOT NULL DEFAULT false,
    pesel_ciphertext text NULL,
    pesel_lookup_hash char(64) NULL,
    contact_email_normalized varchar(320) NULL,
    phone varchar(40) NULL,
    default_location_id uuid NULL,
    archived_at timestamptz NULL,
    archived_by_user_id uuid NULL,
    version bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('students')) {
            throw new LogicException('MIG-TBL-STUDENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
