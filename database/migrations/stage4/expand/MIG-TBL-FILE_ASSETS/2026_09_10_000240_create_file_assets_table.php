<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-FILE_ASSETS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-FILE_ASSETS requires PostgreSQL.');
        }

        if (Schema::hasTable('file_assets')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE file_assets (
    id uuid PRIMARY KEY,
    organization_id uuid NULL,
    storage_disk varchar(64) NOT NULL,
    storage_key varchar(512) NOT NULL,
    original_filename varchar(255) NULL,
    mime_type_declared varchar(128) NULL,
    mime_type_detected varchar(128) NULL,
    size_bytes bigint NOT NULL,
    sha256 char(64) NULL,
    purpose varchar(64) NOT NULL,
    status varchar(32) NOT NULL,
    created_by_user_id uuid NULL,
    created_at timestamptz NOT NULL,
    ready_at timestamptz NULL,
    deleted_at timestamptz NULL
)
SQL);

        if (! Schema::hasTable('file_assets')) {
            throw new LogicException('MIG-TBL-FILE_ASSETS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
