<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-LEGAL_DOCUMENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-LEGAL_DOCUMENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('legal_documents')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE legal_documents (
    id uuid PRIMARY KEY,
    document_type varchar(64) NOT NULL,
    version varchar(64) NOT NULL,
    content_hash char(64) NOT NULL,
    storage_asset_id uuid NULL,
    published_at timestamptz NOT NULL,
    effective_from timestamptz NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('legal_documents')) {
            throw new LogicException('MIG-TBL-LEGAL_DOCUMENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
