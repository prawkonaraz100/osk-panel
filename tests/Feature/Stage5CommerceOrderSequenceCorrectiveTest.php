<?php

namespace Tests\Feature;

use App\Support\Migrations\Stage5CommerceOrderSequenceMigrationPlan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage5CommerceOrderSequenceCorrectiveTest extends TestCase
{
    private const MIGRATIONS = [
        '2026_09_14_000010_preflight_commerce_order_sequence',
        '2026_09_14_000020_expand_commerce_order_sequence',
        '2026_09_14_000030_backfill_commerce_order_sequence',
        '2026_09_14_000040_validate_commerce_order_sequence',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_allocator_plan_preserves_all_frozen_migration_identities(): void
    {
        $summary = app(Stage5CommerceOrderSequenceMigrationPlan::class)->summary();

        self::assertSame('6d03c38e47ba30d07a3a090d514ac09384e1ea5e668947b95eeaaa6fce49c019', $summary['plan_identity']);
        self::assertSame('4dc74dce0acaf9904a013a08c7f85d0539ccb8af6fcff0cc690aae2629100554', $summary['execution_identity']);
        self::assertSame('d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10', $summary['stage4_plan_identity']);
        self::assertSame('82da84d3efb312d78b432ad6081491c04b03569a0f250722d6befc11ca233712', $summary['stage4_execution_identity']);
        self::assertSame('34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99', $summary['preserved_formal_documents_plan_identity']);
        self::assertSame('31704fcab61761aa9a952d824dc543a6f46e7a3717792d0a9349cefaaf57651f', $summary['preserved_formal_documents_execution_identity']);
        self::assertSame('85319b7c91cf9046e0ac8c00772aad141462e3897363b32d0ba222a50f73fe4d', $summary['preserved_social_identity_plan_identity']);
        self::assertSame('b4a73588390d837fbb460d374114585064445462ddcba667d3919c7052f76cd0', $summary['preserved_social_identity_execution_identity']);
        self::assertSame(1, $summary['nodes']);
        self::assertSame(1, $summary['implemented_nodes']);
        self::assertSame(4, $summary['implemented_steps']);
    }

    public function test_catalog_has_exact_allocator_shape(): void
    {
        self::assertTrue(Schema::hasTable('organization_commerce_order_sequences'));
        self::assertSame(
            ['organization_id', 'next_order_sequence'],
            Schema::getColumnListing('organization_commerce_order_sequences'),
        );

        $constraints = DB::select(<<<'SQL'
SELECT c.conname, c.contype, c.confupdtype, c.confdeltype, ref.relname AS referenced_table
FROM pg_constraint c
JOIN pg_class t ON t.oid = c.conrelid
JOIN pg_namespace ns ON ns.oid = t.relnamespace
LEFT JOIN pg_class ref ON ref.oid = c.confrelid
WHERE ns.nspname = current_schema()
  AND t.relname = 'organization_commerce_order_sequences'
ORDER BY c.conname
SQL);

        self::assertCount(3, $constraints);
        $byName = [];
        foreach ($constraints as $constraint) {
            $byName[(string) $constraint->conname] = $constraint;
        }

        self::assertSame('p', $byName['organization_commerce_order_sequences_pkey']->contype);
        self::assertSame('c', $byName['organization_commerce_order_sequences_next_order_sequence_gte_1']->contype);
        self::assertSame('f', $byName['organization_commerce_order_sequences_organization_id_fk']->contype);
        self::assertSame('organizations', $byName['organization_commerce_order_sequences_organization_id_fk']->referenced_table);
        self::assertSame('r', $byName['organization_commerce_order_sequences_organization_id_fk']->confupdtype);
        self::assertSame('r', $byName['organization_commerce_order_sequences_organization_id_fk']->confdeltype);
    }

    public function test_backfill_uses_existing_order_history_once_without_rewriting_orders(): void
    {
        $actor = FoundationSchema::actor();
        $emptyOrganization = FoundationSchema::actor();

        foreach ([3, 7] as $sequence) {
            DB::table('orders')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $actor['organization_id'],
                'order_sequence' => $sequence,
                'ordered_at' => now(),
                'booked_at' => null,
                'zero_total_settled_at' => now(),
                'total_amount_minor' => 0,
                'currency' => 'PLN',
                'created_by_user_id' => $actor['user_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::statement('DROP TABLE organization_commerce_order_sequences');
        DB::table('migrations')->whereIn('migration', self::MIGRATIONS)->delete();

        $plan = app(Stage5CommerceOrderSequenceMigrationPlan::class);
        foreach (['preflight', 'expand', 'backfill', 'validate'] as $phase) {
            $exit = Artisan::call('migration:stage5:commerce-order-sequence:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => $phase,
                '--force' => true,
            ]);

            self::assertSame(0, $exit, Artisan::output());
        }

        self::assertSame(
            8,
            (int) DB::table('organization_commerce_order_sequences')
                ->where('organization_id', $actor['organization_id'])
                ->value('next_order_sequence'),
        );
        self::assertSame(
            1,
            (int) DB::table('organization_commerce_order_sequences')
                ->where('organization_id', $emptyOrganization['organization_id'])
                ->value('next_order_sequence'),
        );
        self::assertSame(
            [3, 7],
            DB::table('orders')
                ->where('organization_id', $actor['organization_id'])
                ->orderBy('order_sequence')
                ->pluck('order_sequence')
                ->map(static fn ($value): int => (int) $value)
                ->all(),
        );
    }
}
