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
            'backfill',
            'S5COM-TBL-ORGANIZATION-COMMERCE-ORDER-SEQUENCES',
        );

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Commerce order sequence backfill requires PostgreSQL.');
        }

        if (! Schema::hasTable('organization_commerce_order_sequences')) {
            throw new LogicException('Allocator table must exist before commerce order sequence backfill.');
        }

        DB::statement(<<<'SQL'
INSERT INTO organization_commerce_order_sequences (organization_id, next_order_sequence)
SELECT
    org.id,
    COALESCE(MAX(o.order_sequence), 0) + 1
FROM organizations org
LEFT JOIN orders o ON o.organization_id = org.id
LEFT JOIN organization_commerce_order_sequences seq ON seq.organization_id = org.id
WHERE seq.organization_id IS NULL
GROUP BY org.id
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden for the commerce order sequence corrective.');
    }
};
