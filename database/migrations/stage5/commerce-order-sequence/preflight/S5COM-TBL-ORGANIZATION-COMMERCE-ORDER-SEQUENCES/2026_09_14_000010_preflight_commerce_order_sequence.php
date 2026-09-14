<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive(
            'preflight',
            'S5COM-TBL-ORGANIZATION-COMMERCE-ORDER-SEQUENCES',
        );

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Commerce order sequence preflight requires PostgreSQL.');
        }

        foreach (['organizations', 'orders'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new LogicException("{$table} must exist before commerce order sequence preflight.");
            }
        }

        if (DB::table('orders')->whereNull('order_sequence')->exists()) {
            throw new LogicException('Existing order_sequence values must be non-null before allocator materialization.');
        }

        if (DB::table('orders')->where('order_sequence', '<', 1)->exists()) {
            throw new LogicException('Existing order_sequence values must be positive before allocator materialization.');
        }

        $duplicate = DB::selectOne(<<<'SQL'
SELECT organization_id, order_sequence, COUNT(*) AS duplicate_count
FROM orders
GROUP BY organization_id, order_sequence
HAVING COUNT(*) > 1
LIMIT 1
SQL);

        if ($duplicate !== null) {
            throw new LogicException('Existing order_sequence values must be unique per organization before allocator materialization.');
        }

        $orphan = DB::selectOne(<<<'SQL'
SELECT o.id
FROM orders o
LEFT JOIN organizations org ON org.id = o.organization_id
WHERE org.id IS NULL
LIMIT 1
SQL);

        if ($orphan !== null) {
            throw new LogicException('Existing orders must reference an existing organization before allocator materialization.');
        }

        $overflow = DB::selectOne(<<<'SQL'
SELECT organization_id
FROM orders
GROUP BY organization_id
HAVING MAX(order_sequence) >= 9223372036854775807
LIMIT 1
SQL);

        if ($overflow !== null) {
            throw new LogicException('Existing order sequence history leaves no bigint value for the next allocator pointer.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for the commerce order sequence corrective.');
    }
};
