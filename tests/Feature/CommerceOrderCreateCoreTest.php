<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class CommerceOrderCreateCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
        config()->set('commerce.order_create.pricing_by_catalog_code', []);
        config()->set('commerce.order_create.internal_exam_catalog_code', null);
    }

    public function test_license_order_uses_trusted_pricing_allocator_pending_payment_and_idempotent_replay(): void
    {
        $actor = $this->actor(['licenses.purchase']);
        [$productId, $catalogId, $catalogCode] = $this->licenseProduct();
        config()->set("commerce.order_create.pricing_by_catalog_code.{$catalogCode}", [
            'currency' => 'PLN',
            'list_unit_amount_minor' => 1500,
            'charged_unit_amount_minor' => 1200,
            'vat_rate_basis_points' => 2300,
            'display_name' => 'Licencja testowa 31 dni',
            'pricing_revision' => 'pricing-2026-09-15-a',
        ]);

        $key = (string) Str::uuid7();
        $payload = [
            'items' => [['product_id' => $productId, 'quantity' => 2]],
            'payment_method' => ' BANK_TRANSFER ',
        ];

        $first = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/license-orders', $payload)
            ->assertCreated()
            ->assertJsonPath('order_sequence', 1)
            ->assertJsonPath('display_number', '1')
            ->assertJsonPath('status', 'payment_pending')
            ->assertJsonPath('total.amount_minor', 2400)
            ->assertJsonPath('total.currency', 'PLN')
            ->assertJsonPath('items.0.product_kind', 'license')
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('items.0.list_unit_amount_minor', 1500)
            ->assertJsonPath('items.0.unit_amount_minor', 1200)
            ->assertJsonPath('items.0.unit_discount_amount_minor', 300);

        $orderId = (string) $first->json('id');
        $item = DB::table('order_items')->where('order_id', $orderId)->firstOrFail();
        $this->assertSame($catalogId, (string) $item->commerce_catalog_item_id);
        $this->assertSame($productId, (string) $item->license_product_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $item->snapshot_hash);
        $pricingSnapshot = json_decode((string) $item->pricing_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $productSnapshot = json_decode((string) $item->product_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pricing-2026-09-15-a', $pricingSnapshot['pricing_revision']);
        $this->assertSame('Licencja testowa 31 dni', $productSnapshot['display_name_at_order_time']);

        $payment = DB::table('payments')->where('order_id', $orderId)->firstOrFail();
        $this->assertSame('bank_transfer', (string) $payment->provider);
        $this->assertSame(2400, (int) $payment->amount_minor);
        $this->assertSame('pending', (string) $payment->status);
        $this->assertNull($payment->provider_payment_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $payment->public_payment_reference);
        $this->assertSame(0, DB::table('order_payment_settlements')->where('order_id', $orderId)->count());
        $this->assertSame(2, (int) DB::table('organization_commerce_order_sequences')
            ->where('organization_id', $actor['organization_id'])
            ->value('next_order_sequence'));

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/license-orders', [
                'items' => [['product_id' => $productId, 'quantity' => 2]],
                'payment_method' => 'bank_transfer',
            ])
            ->assertCreated()
            ->assertJsonPath('id', $orderId);

        $this->assertSame(1, DB::table('orders')->where('organization_id', $actor['organization_id'])->count());
        $this->assertSame(1, DB::table('payments')->where('order_id', $orderId)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'commerce.license_order.created')->where('entity_id', $orderId)->count());

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/license-orders', [
                'items' => [['product_id' => $productId, 'quantity' => 3]],
                'payment_method' => 'bank_transfer',
            ])
            ->assertConflict();
    }

    public function test_internal_exam_zero_total_order_is_locally_settled_without_payment_and_sequences_increment(): void
    {
        $actor = $this->actor(['exams.purchase']);
        [$catalogId, $catalogCode] = $this->internalExamCatalog();
        config()->set('commerce.order_create.internal_exam_catalog_code', $catalogCode);
        config()->set("commerce.order_create.pricing_by_catalog_code.{$catalogCode}", [
            'currency' => 'PLN',
            'list_unit_amount_minor' => 900,
            'charged_unit_amount_minor' => 0,
            'vat_rate_basis_points' => 2300,
            'display_name' => 'Egzamin wewnętrzny',
            'pricing_revision' => 'exam-free-2026-09-15',
        ]);

        $first = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/internal-exam/orders', [
                'quantity' => 3,
                'payment_method' => 'card',
            ])
            ->assertCreated()
            ->assertJsonPath('order_sequence', 1)
            ->assertJsonPath('status', 'paid_processing')
            ->assertJsonPath('total.amount_minor', 0)
            ->assertJsonPath('items.0.product_kind', 'internal_exam')
            ->assertJsonPath('items.0.quantity', 3);

        $firstOrderId = (string) $first->json('id');
        $order = DB::table('orders')->where('id', $firstOrderId)->firstOrFail();
        $this->assertNotNull($order->zero_total_settled_at);
        $this->assertSame((string) $order->zero_total_settled_at, (string) $order->booked_at);
        $this->assertSame(0, DB::table('payments')->where('order_id', $firstOrderId)->count());
        $fulfillment = DB::table('order_fulfillments')->where('order_id', $firstOrderId)->firstOrFail();
        $this->assertSame('zero_total', (string) $fulfillment->source_kind);
        $this->assertSame('pending', (string) $fulfillment->state);
        $this->assertNull($fulfillment->settlement_payment_id);
        $this->assertSame($catalogId, (string) DB::table('order_items')->where('order_id', $firstOrderId)->value('commerce_catalog_item_id'));

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/internal-exam/orders', [
                'quantity' => 1,
                'payment_method' => 'card',
            ])
            ->assertCreated()
            ->assertJsonPath('order_sequence', 2);

        $this->assertSame(3, (int) DB::table('organization_commerce_order_sequences')
            ->where('organization_id', $actor['organization_id'])
            ->value('next_order_sequence'));
    }

    public function test_order_create_fails_closed_for_missing_pricing_and_missing_permission(): void
    {
        $allowed = $this->actor(['licenses.purchase']);
        [$productId] = $this->licenseProduct();

        $this->withSession(['auth_session_id' => $allowed['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/license-orders', [
                'items' => [['product_id' => $productId, 'quantity' => 1]],
                'payment_method' => 'card',
            ])
            ->assertConflict();

        $this->assertSame(0, DB::table('orders')->where('organization_id', $allowed['organization_id'])->count());
        $this->assertSame(0, DB::table('organization_commerce_order_sequences')
            ->where('organization_id', $allowed['organization_id'])
            ->count());

        $denied = $this->actor([]);
        [$deniedProduct, , $deniedCode] = $this->licenseProduct();
        config()->set("commerce.order_create.pricing_by_catalog_code.{$deniedCode}", [
            'currency' => 'PLN',
            'list_unit_amount_minor' => 100,
            'charged_unit_amount_minor' => 100,
            'vat_rate_basis_points' => 2300,
            'display_name' => 'Denied product',
            'pricing_revision' => 'denied-test',
        ]);

        $this->withSession(['auth_session_id' => $denied['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/license-orders', [
                'items' => [['product_id' => $deniedProduct, 'quantity' => 1]],
                'payment_method' => 'card',
            ])
            ->assertForbidden();

        $this->assertSame(0, DB::table('orders')->where('organization_id', $denied['organization_id'])->count());
    }

    /** @param list<string> $permissions
     *  @return array{organization_id:string,user_id:string,membership_id:string,session_id:string}
     */
    private function actor(array $permissions): array
    {
        $actor = FoundationSchema::actor();
        foreach ($permissions as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        return $actor;
    }

    /** @return array{0:string,1:string,2:string} */
    private function licenseProduct(): array
    {
        $productId = (string) Str::uuid7();
        $catalogId = (string) Str::uuid7();
        $catalogCode = 'LICENSE-'.$catalogId;
        DB::table('license_products')->insert([
            'id' => $productId,
            'code' => 'PRODUCT-'.$productId,
            'duration_days' => 31,
            'active' => true,
            'activation_mode' => 'manual',
            'metadata' => null,
        ]);
        DB::table('commerce_catalog_items')->insert([
            'id' => $catalogId,
            'code' => $catalogCode,
            'product_kind' => 'license',
            'license_product_id' => $productId,
            'active' => true,
            'created_at' => now(),
        ]);

        return [$productId, $catalogId, $catalogCode];
    }

    /** @return array{0:string,1:string} */
    private function internalExamCatalog(): array
    {
        $catalogId = (string) Str::uuid7();
        $catalogCode = 'INTERNAL-EXAM-'.$catalogId;
        DB::table('commerce_catalog_items')->insert([
            'id' => $catalogId,
            'code' => $catalogCode,
            'product_kind' => 'internal_exam',
            'license_product_id' => null,
            'active' => true,
            'created_at' => now(),
        ]);

        return [$catalogId, $catalogCode];
    }
}
