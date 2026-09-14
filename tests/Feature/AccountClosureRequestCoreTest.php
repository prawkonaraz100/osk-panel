<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class AccountClosureRequestCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FoundationSchema::reset();
    }

    public function test_global_account_closure_request_requires_only_authenticated_user_and_writes_atomic_global_audit_outbox(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('organization_memberships')
            ->where('id', $actor['membership_id'])
            ->update(['status' => 'suspended']);

        $response = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/auth/account-closure-requests', [
                'reason' => 'Proszę o zamknięcie konta.',
            ])
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending');

        $closureId = (string) $response->json('id');
        $this->assertNotSame('', $closureId);

        $closure = DB::table('account_closure_requests')->where('id', $closureId)->firstOrFail();
        $this->assertSame($actor['user_id'], (string) $closure->user_id);
        $this->assertNull($closure->organization_id);
        $this->assertSame('pending', (string) $closure->status);
        $this->assertSame('Proszę o zamknięcie konta.', (string) $closure->reason);

        $audit = DB::table('audit_logs')->where('entity_id', $closureId)->firstOrFail();
        $this->assertSame('platform_global', (string) $audit->audit_scope);
        $this->assertSame('global_user', (string) $audit->actor_kind);
        $this->assertNull($audit->organization_id);
        $this->assertNull($audit->actor_organization_membership_id);
        $this->assertSame($actor['user_id'], (string) $audit->actor_user_id);
        $this->assertSame('auth.account_closure.requested', (string) $audit->action);
        $this->assertSame(['state' => 'absent'], json_decode((string) $audit->before_redacted_json, true));
        $this->assertSame(['state' => 'pending'], json_decode((string) $audit->after_redacted_json, true));
        $this->assertNull($audit->reason);

        $event = DB::table('domain_events')->where('required_audit_log_id', $audit->id)->firstOrFail();
        $this->assertSame('platform_global', (string) $event->event_scope);
        $this->assertNull($event->organization_id);

        $outbox = DB::table('outbox_messages')->where('domain_event_id', $event->id)->firstOrFail();
        $this->assertSame('platform_global', (string) $outbox->event_scope);
        $this->assertNull($outbox->organization_id);
        $this->assertSame('pending', (string) $outbox->publication_state);
    }

    public function test_account_closure_is_idempotent_per_request_key_and_reuses_existing_pending_global_request(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('auth_sessions')
            ->where('id', $actor['session_id'])
            ->update(['organization_membership_id' => null]);

        $firstKey = (string) Str::uuid7();
        $first = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $firstKey)
            ->postJson('/api/v1/auth/account-closure-requests', ['reason' => 'Global close'])
            ->assertStatus(202);

        $closureId = (string) $first->json('id');

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $firstKey)
            ->postJson('/api/v1/auth/account-closure-requests', ['reason' => 'Global close'])
            ->assertStatus(202)
            ->assertJsonPath('id', $closureId);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/auth/account-closure-requests', ['reason' => 'Another retry'])
            ->assertStatus(202)
            ->assertJsonPath('id', $closureId);

        $this->assertSame(1, DB::table('account_closure_requests')
            ->where('user_id', $actor['user_id'])
            ->whereNull('organization_id')
            ->where('status', 'pending')
            ->count());
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('domain_events', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertSame(2, DB::table('idempotency_records')
            ->whereNull('organization_id')
            ->where('operation_key', 'auth.account_closure_request')
            ->count());
    }

    public function test_account_closure_idempotency_key_cannot_be_reused_with_different_payload(): void
    {
        $actor = FoundationSchema::actor();
        $key = (string) Str::uuid7();

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/auth/account-closure-requests', ['reason' => 'First'])
            ->assertStatus(202);

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/auth/account-closure-requests', ['reason' => 'Different'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD');

        $this->assertDatabaseCount('account_closure_requests', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_account_closure_requires_live_authenticated_session_and_uuid_idempotency_key(): void
    {
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/auth/account-closure-requests')
            ->assertUnauthorized();

        $actor = FoundationSchema::actor();

        $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', 'not-a-uuid')
            ->postJson('/api/v1/auth/account-closure-requests')
            ->assertUnprocessable();

        $this->assertDatabaseCount('account_closure_requests', 0);
    }
}
