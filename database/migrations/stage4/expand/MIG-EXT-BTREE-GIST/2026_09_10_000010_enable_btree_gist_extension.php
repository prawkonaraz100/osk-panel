<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-EXT-BTREE-GIST');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-EXT-BTREE-GIST requires PostgreSQL.');
        }

        $enabled = DB::scalar("select exists (select 1 from pg_extension where extname = 'btree_gist')");
        if (filter_var($enabled, FILTER_VALIDATE_BOOL)) {
            return;
        }

        DB::statement('CREATE EXTENSION btree_gist');
        $enabled = DB::scalar("select exists (select 1 from pg_extension where extname = 'btree_gist')");
        if (! filter_var($enabled, FILTER_VALIDATE_BOOL)) {
            throw new LogicException('MIG-EXT-BTREE-GIST postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
