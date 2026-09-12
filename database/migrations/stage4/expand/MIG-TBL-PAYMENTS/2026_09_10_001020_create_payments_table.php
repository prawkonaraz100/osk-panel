<?php

use App\Support\Migrations\ControlledMigrationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', 'MIG-TBL-PAYMENTS');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('MIG-TBL-PAYMENTS requires PostgreSQL.');
        }

        if (Schema::hasTable('payments')) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TABLE payments (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    order_id uuid NOT NULL,
    provider varchar(64) NOT NULL,
    provider_payment_id varchar(255) NULL,
    public_payment_reference varchar(128) NOT NULL,
    status varchar(32) NOT NULL DEFAULT 'pending',
    amount_minor bigint NOT NULL,
    currency char(3) NOT NULL,
    confirmed_at timestamptz NULL,
    failed_at timestamptz NULL,
    created_at timestamptz NOT NULL
)
SQL);

        if (! Schema::hasTable('payments')) {
            throw new LogicException('MIG-TBL-PAYMENTS postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
