<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-TERMS_ACCEPTANCES');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-TERMS_ACCEPTANCES requires PostgreSQL.');
        }

        if (Schema::hasTable('terms_acceptances')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE terms_acceptances (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    user_id uuid NOT NULL,
    legal_document_id uuid NOT NULL,
    accepted_at timestamptz NOT NULL,
    ip_hash varchar(128) NULL,
    user_agent varchar(512) NULL,
    request_id varchar(64) NOT NULL
)
SQL);

        if (! Schema::hasTable('terms_acceptances')) {
            throw new LogicException('MIG-TBL-TERMS_ACCEPTANCES postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
