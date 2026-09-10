<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-ORGANIZATION_MEMBERSHIPS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-ORGANIZATION_MEMBERSHIPS requires PostgreSQL.');
        }

        if (Schema::hasTable('organization_memberships')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE organization_memberships (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    user_id uuid NOT NULL,
    status varchar(32) NOT NULL,
    is_owner boolean NOT NULL DEFAULT false,
    role_template_code varchar(64) NULL,
    role_template_catalog_version varchar(64) NULL,
    version bigint NOT NULL DEFAULT 1,
    authorization_version bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('organization_memberships')) {
            throw new LogicException('MIG-TBL-ORGANIZATION_MEMBERSHIPS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
