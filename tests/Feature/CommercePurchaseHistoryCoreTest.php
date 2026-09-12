<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class CommercePurchaseHistoryCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_purchase_history_is_tenant_scoped_and_preserves_multi_item_snapshots(): void
    {
        $actor = $this->commerceActor();
        $order = $this->order($actor['organization_id'], 1, [
            ['kind' => 'license', 'name' => 'Licencja 31 dni', 'quantity' => 2, 'unit' => 1200],
            ['kind' => 'internal_exam', 'name' => 'Egzamin wewnętrzny', 'quantity' => 1, 'unit' => 600],
        ]);
        $other = $this->commerceActor();
        $this->order($other['organization_id'], 1, [
            ['kind' => 'internal_exam', 'name' => 'Obcy egzamin', 'quantity' => 1, 'unit' => 999],
        ]);

        $response = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/purchase-history?per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $order)
            ->assertJsonPath('data.0.display_number', '1')
            ->assertJsonPath('data.0.status', 'unpaid')
            ->assertJsonPath('data.0.booked_at', null)
            ->assertJsonCount(2, 'data.0.items');

        $this->assertSame('Licencja 31 dni', $response->json('data.0.items.0.display_name_snapshot'));
        $this->assertSame('Egzamin wewnętrzny', $response->json('data.0.items.1.display_name_snapshot'));
    }

    public function test_payment_start_is_idempotent_opaque_audited_and_does_not_mark_order_paid(): void
    {
        $actor = $this->commerceActor();
        $order = $this->order($actor['organization_id'], 1, [
            ['kind' => 'internal_exam', 'name' => 'Egzamin wewnętrzny', 'quantity' => 2, 'unit' => 1500],
        ]);
        $key = (string) Str::uuid7();
        $payload = ['method' => 'bank_transfer'];

        $first = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/orders/{$order}/payments", $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('provider', 'bank_transfer');
        $paymentId = (string) $first->json('id');
        $publicReference = (string) $first->json('public_payment_reference');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $publicReference);
        $this->assertNotSame('1', $publicReference);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/orders/{$order}/payments", $payload)
            ->assertCreated()
            ->assertJsonPath('id', $paymentId)
            ->assertJsonPath('public_payment_reference', $publicReference);

        $this->assertSame(1, DB::table('payments')->where('order_id', $order)->count());
        $this->assertSame(0, DB::table('order_payment_settlements')->where('order_id', $order)->count());
        $this->assertNull(DB::table('orders')->where('id', $order)->value('booked_at'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'commerce.payment.started')->where('entity_id', $paymentId)->count());
        $this->assertSame(1, DB::table('domain_events')->where('event_type', 'commerce.payment.started')->where('aggregate_id', $paymentId)->count());
        $this->assertSame(1, DB::table('outbox_messages')->where('event_type', 'commerce.payment.started')->where('aggregate_id', $paymentId)->count());

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson("/api/v1/orders/{$order}")
            ->assertOk()
            ->assertJsonPath('status', 'payment_pending');
    }

    public function test_purchase_status_is_derived_from_settlement_and_fulfillment_not_raw_payment_label(): void
    {
        $actor = $this->commerceActor();
        $order = $this->order($actor['organization_id'], 1, [
            ['kind' => 'generic_service', 'name' => 'Usługa', 'quantity' => 1, 'unit' => 5000],
        ]);
        $payment = (string) Str::uuid7();
        $now = now();
        DB::table('payments')->insert([
            'id' => $payment,
            'organization_id' => $actor['organization_id'],
            'order_id' => $order,
            'provider' => 'verified_test_rail',
            'provider_payment_id' => 'provider-test-1',
            'public_payment_reference' => bin2hex(random_bytes(32)),
            'status' => 'confirmed',
            'amount_minor' => 5000,
            'currency' => 'PLN',
            'confirmed_at' => $now,
            'failed_at' => null,
            'created_at' => $now,
        ]);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson("/api/v1/orders/{$order}")
            ->assertOk()
            ->assertJsonPath('status', 'unpaid');

        DB::table('order_payment_settlements')->insert([
            'organization_id' => $actor['organization_id'],
            'order_id' => $order,
            'payment_id' => $payment,
            'settled_at' => $now,
            'confirmation_source' => 'reconciliation',
            'source_payment_event_id' => null,
            'reconciled_by_user_id' => $actor['user_id'],
            'reconciliation_reason' => 'Synthetic trusted evidence',
            'created_at' => $now,
        ]);
        DB::table('orders')->where('id', $order)->update(['booked_at' => $now]);
        DB::table('order_fulfillments')->insert([
            'organization_id' => $actor['organization_id'],
            'order_id' => $order,
            'source_kind' => 'payment_settlement',
            'settlement_payment_id' => $payment,
            'state' => 'pending',
            'created_at' => $now,
            'fulfilled_at' => null,
            'requires_reconciliation_at' => null,
            'reconciliation_reason' => null,
        ]);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson("/api/v1/orders/{$order}")
            ->assertOk()
            ->assertJsonPath('status', 'paid_processing');

        DB::table('order_fulfillments')
            ->where('organization_id', $actor['organization_id'])
            ->where('order_id', $order)
            ->update(['state' => 'fulfilled', 'fulfilled_at' => now()]);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson("/api/v1/orders/{$order}")
            ->assertOk()
            ->assertJsonPath('status', 'completed');
    }

    public function test_cross_tenant_order_is_not_visible_and_purchase_permissions_fail_closed(): void
    {
        $owner = $this->commerceActor();
        $foreign = $this->commerceActor();
        $foreignOrder = $this->order($foreign['organization_id'], 1, [
            ['kind' => 'internal_exam', 'name' => 'Obcy egzamin', 'quantity' => 1, 'unit' => 1000],
        ]);

        $this->withSession(['auth_session_id' => $owner['session_id']])
            ->getJson("/api/v1/orders/{$foreignOrder}")
            ->assertNotFound();

        $this->withSession(['auth_session_id' => $owner['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/orders/{$foreignOrder}/payments", ['method' => 'bank_transfer'])
            ->assertNotFound();

        $denied = FoundationSchema::actor();
        $this->withSession(['auth_session_id' => $denied['session_id']])
            ->getJson('/api/v1/purchase-history')
            ->assertForbidden();
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function commerceActor(): array
    {
        $actor = FoundationSchema::actor();
        foreach (['purchases.view', 'purchases.create', 'purchases.pay'] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        return $actor;
    }

    /**
     * @param  list<array{kind:string,name:string,quantity:int,unit:int}>  $items
     */
    private function order(string $organizationId, int $sequence, array $items): string
    {
        $orderId = (string) Str::uuid7();
        $total = 0;
        foreach ($items as $item) {
            $total += $item['quantity'] * $item['unit'];
        }
        $now = now();

        DB::table('orders')->insert([
            'id' => $orderId,
            'organization_id' => $organizationId,
            'order_sequence' => $sequence,
            'ordered_at' => $now,
            'booked_at' => null,
            'zero_total_settled_at' => null,
            'total_amount_minor' => $total,
            'currency' => 'PLN',
            'created_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($items as $index => $item) {
            $catalogId = (string) Str::uuid7();
            $code = strtoupper($item['kind']).'-'.$catalogId;
            DB::table('commerce_catalog_items')->insert([
                'id' => $catalogId,
                'code' => $code,
                'product_kind' => $item['kind'],
                'license_product_id' => null,
                'active' => true,
                'created_at' => $now,
            ]);
            $productSnapshot = [
                'catalog_item_id' => $catalogId,
                'stable_catalog_code' => $code,
                'product_kind' => $item['kind'],
                'display_name_at_order_time' => $item['name'],
            ];
            $pricingSnapshot = [
                'list_unit_amount_minor' => $item['unit'],
                'charged_unit_amount_minor' => $item['unit'],
                'unit_discount_amount_minor' => 0,
                'vat_rate_basis_points' => 2300,
                'pricing_resolution_time' => $now->toIso8601String(),
            ];
            $snapshotJson = json_encode($productSnapshot, JSON_THROW_ON_ERROR);
            DB::table('order_items')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organizationId,
                'order_id' => $orderId,
                'commerce_catalog_item_id' => $catalogId,
                'product_kind' => $item['kind'],
                'quantity' => $item['quantity'],
                'currency' => 'PLN',
                'list_unit_amount_minor' => $item['unit'],
                'unit_amount_minor' => $item['unit'],
                'unit_discount_amount_minor' => 0,
                'vat_rate_basis_points' => 2300,
                'total_amount_minor' => $item['quantity'] * $item['unit'],
                'product_snapshot' => $snapshotJson,
                'pricing_snapshot' => json_encode($pricingSnapshot, JSON_THROW_ON_ERROR),
                'snapshot_hash' => hash('sha256', $snapshotJson),
                'created_at' => $now->copy()->addMicroseconds($index),
            ]);
        }

        return $orderId;
    }
}
