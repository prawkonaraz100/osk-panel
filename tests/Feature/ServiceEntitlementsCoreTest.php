<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class ServiceEntitlementsCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FoundationSchema::reset();
    }

    public function test_service_entitlements_list_is_tenant_scoped_and_projects_activation_evidence(): void
    {
        $actor = FoundationSchema::actor();
        FoundationSchema::grant($actor['membership_id'], 'purchases.view', ['organization']);

        $foreign = FoundationSchema::actor();
        FoundationSchema::grant($foreign['membership_id'], 'purchases.view', ['organization']);

        $ownId = $this->insertExplicitEntitlement($actor['organization_id'], 'sms_notifications', 'operator:own');
        $this->insertExplicitEntitlement($foreign['organization_id'], 'sms_notifications', 'operator:foreign');

        $response = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson('/api/v1/service-entitlements')
            ->assertOk()
            ->assertJsonCount(1);

        $response->assertJsonPath('0.id', $ownId);
        $response->assertJsonPath('0.service_type', 'sms_notifications');
        $response->assertJsonPath('0.status', 'available');
        $response->assertJsonPath('0.activation_mode', 'explicit');
        $response->assertJsonPath('0.activated_at', null);
        $response->assertJsonPath('0.effective_from', null);
        $response->assertJsonPath('0.effective_to', null);
    }

    public function test_explicit_activation_is_exactly_once_idempotent_and_audited(): void
    {
        $actor = FoundationSchema::actor();
        FoundationSchema::grant($actor['membership_id'], 'purchases.create', ['organization']);

        $entitlementId = $this->insertExplicitEntitlement(
            $actor['organization_id'],
            'premium_reporting',
            'operator:activation',
        );

        $firstKey = (string) Str::uuid7();
        $first = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $firstKey)
            ->postJson('/api/v1/service-entitlements/'.$entitlementId.'/activate')
            ->assertOk()
            ->assertJsonPath('id', $entitlementId)
            ->assertJsonPath('status', 'activated')
            ->assertJsonPath('activation_mode', 'explicit');

        $activatedAt = $first->json('activated_at');
        $this->assertIsString($activatedAt);
        $this->assertNotSame('', $activatedAt);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $firstKey)
            ->postJson('/api/v1/service-entitlements/'.$entitlementId.'/activate')
            ->assertOk()
            ->assertJsonPath('activated_at', $activatedAt);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/service-entitlements/'.$entitlementId.'/activate')
            ->assertOk()
            ->assertJsonPath('activated_at', $activatedAt);

        $this->assertSame(1, DB::table('service_activations')
            ->where('organization_id', $actor['organization_id'])
            ->where('service_entitlement_id', $entitlementId)
            ->count());

        $this->assertSame('activated', DB::table('service_entitlements')
            ->where('organization_id', $actor['organization_id'])
            ->where('id', $entitlementId)
            ->value('status'));

        $this->assertSame(1, DB::table('audit_logs')
            ->where('organization_id', $actor['organization_id'])
            ->where('action', 'commerce.service_entitlement.activated')
            ->where('entity_id', $entitlementId)
            ->count());

        $auditId = (string) DB::table('audit_logs')
            ->where('organization_id', $actor['organization_id'])
            ->where('action', 'commerce.service_entitlement.activated')
            ->where('entity_id', $entitlementId)
            ->value('id');

        $eventId = (string) DB::table('domain_events')
            ->where('organization_id', $actor['organization_id'])
            ->where('required_audit_log_id', $auditId)
            ->value('id');

        $this->assertNotSame('', $eventId);
        $this->assertSame(1, DB::table('outbox_messages')
            ->where('organization_id', $actor['organization_id'])
            ->where('domain_event_id', $eventId)
            ->count());
    }

    public function test_activation_hides_foreign_entitlement_and_rejects_non_explicit_mode(): void
    {
        $actor = FoundationSchema::actor();
        FoundationSchema::grant($actor['membership_id'], 'purchases.create', ['organization']);

        $foreign = FoundationSchema::actor();
        $foreignId = $this->insertExplicitEntitlement(
            $foreign['organization_id'],
            'foreign_service',
            'operator:foreign-activation',
        );

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/service-entitlements/'.$foreignId.'/activate')
            ->assertNotFound();

        $immediateId = (string) Str::uuid7();
        DB::transaction(function () use ($actor, $immediateId): void {
            $now = now();

            DB::table('service_entitlements')->insert([
                'id' => $immediateId,
                'organization_id' => $actor['organization_id'],
                'service_type' => 'immediate_service',
                'source_order_item_id' => null,
                'source_order_item_grant_ordinal' => null,
                'source_grant_reference' => 'operator:immediate',
                'activation_mode' => 'immediate',
                'status' => 'activated',
                'granted_at' => $now,
                'expires_at' => null,
                'created_at' => $now,
            ]);

            DB::table('service_activations')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $actor['organization_id'],
                'service_entitlement_id' => $immediateId,
                'activated_by_user_id' => null,
                'activated_at' => $now,
                'effective_from' => $now,
                'effective_to' => null,
                'created_at' => $now,
            ]);
        });

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/service-entitlements/'.$immediateId.'/activate')
            ->assertStatus(409);

        $this->assertSame(1, DB::table('service_activations')
            ->where('organization_id', $actor['organization_id'])
            ->where('service_entitlement_id', $immediateId)
            ->count());
    }

    private function insertExplicitEntitlement(string $organizationId, string $serviceType, string $reference): string
    {
        $id = (string) Str::uuid7();
        $now = now();

        DB::table('service_entitlements')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'service_type' => $serviceType,
            'source_order_item_id' => null,
            'source_order_item_grant_ordinal' => null,
            'source_grant_reference' => $reference,
            'activation_mode' => 'explicit',
            'status' => 'available',
            'granted_at' => $now,
            'expires_at' => null,
            'created_at' => $now,
        ]);

        return $id;
    }
}
