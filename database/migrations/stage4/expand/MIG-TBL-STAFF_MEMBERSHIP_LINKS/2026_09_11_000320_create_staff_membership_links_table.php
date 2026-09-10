<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-STAFF_MEMBERSHIP_LINKS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-STAFF_MEMBERSHIP_LINKS requires PostgreSQL.');
        }

        if (Schema::hasTable('staff_membership_links')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE staff_membership_links (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    staff_profile_id uuid NOT NULL,
    organization_membership_id uuid NOT NULL,
    linked_at timestamptz NOT NULL,
    linked_by_user_id uuid NULL,
    unlinked_at timestamptz NULL,
    unlinked_by_user_id uuid NULL
)
SQL);

        if (! Schema::hasTable('staff_membership_links')) {
            throw new LogicException('MIG-TBL-STAFF_MEMBERSHIP_LINKS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
