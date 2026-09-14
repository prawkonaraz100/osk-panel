<?php

namespace Tests\Feature;

use App\Modules\IdentityTenant\AuthSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class AuthSessionLifecycleCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
        RateLimiter::clear('auth-login:'.hash('sha256', '127.0.0.1|owner@example.test'));
        RateLimiter::clear('auth-login:'.hash('sha256', '127.0.0.1|member@example.test'));
    }

    public function test_login_materializes_hashed_framework_session_and_selects_only_unambiguous_membership(): void
    {
        $actor = $this->credentialedActor();

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => '  OWNER@EXAMPLE.TEST ',
            'password' => 'Correct-Horse-42!',
            'return_url' => '/kursanci?sort=name',
        ]);

        $response->assertOk()
            ->assertJsonPath('current', true)
            ->assertJsonPath('return_url', '/kursanci?sort=name');

        $authSessionId = (string) $response->json('id');
        $response->assertSessionHas('auth_session_id', $authSessionId);

        $row = DB::table('auth_sessions')->where('id', $authSessionId)->firstOrFail();

        $this->assertSame($actor['user_id'], (string) $row->user_id);
        $this->assertSame($actor['membership_id'], (string) $row->organization_membership_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $row->token_or_framework_session_hash);
        $this->assertNotSame($authSessionId, (string) $row->token_or_framework_session_hash);
        $this->assertNotNull(DB::table('users')->where('id', $actor['user_id'])->value('last_login_at'));
    }

    public function test_auth_session_storage_hashes_framework_reference_instead_of_persisting_raw_secret(): void
    {
        $actor = FoundationSchema::actor();
        $frameworkSessionId = 'framework-session-secret-fixture';

        $session = app(AuthSessionService::class)->startSession(
            $actor['user_id'],
            $actor['membership_id'],
            $frameworkSessionId,
            '127.0.0.1',
            'Synthetic Browser',
        );

        $stored = DB::table('auth_sessions')->where('id', $session['id'])->firstOrFail();
        $this->assertSame(hash('sha256', $frameworkSessionId), (string) $stored->token_or_framework_session_hash);
        $this->assertNotSame($frameworkSessionId, (string) $stored->token_or_framework_session_hash);
        $this->assertSame(hash('sha256', '127.0.0.1'), (string) $stored->ip_hash);
    }

    public function test_invalid_or_revoked_login_identifier_fails_without_materializing_a_session(): void
    {
        $actor = $this->credentialedActor();
        $before = DB::table('auth_sessions')->where('user_id', $actor['user_id'])->count();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'owner@example.test',
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $this->assertSame($before, DB::table('auth_sessions')->where('user_id', $actor['user_id'])->count());

        DB::table('auth_login_identifiers')
            ->where('user_id', $actor['user_id'])
            ->update(['revoked_at' => now()]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'owner@example.test',
            'password' => 'Correct-Horse-42!',
        ])->assertUnauthorized();

        $this->assertSame($before, DB::table('auth_sessions')->where('user_id', $actor['user_id'])->count());
    }

    public function test_login_rate_limit_is_identifier_and_ip_scoped(): void
    {
        $this->credentialedActor();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'identifier' => 'owner@example.test',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'owner@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_external_return_url_is_rejected_before_session_creation(): void
    {
        $actor = $this->credentialedActor();
        $before = DB::table('auth_sessions')->where('user_id', $actor['user_id'])->count();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'owner@example.test',
            'password' => 'Correct-Horse-42!',
            'return_url' => 'https://example.invalid/phish',
        ])->assertUnprocessable();

        $this->assertSame($before, DB::table('auth_sessions')->where('user_id', $actor['user_id'])->count());
    }

    public function test_session_list_and_revoke_are_own_scoped_and_cross_user_target_is_hidden(): void
    {
        $actor = $this->credentialedActor();
        FoundationSchema::grant($actor['membership_id'], 'sessions.manage.own', ['own']);

        $login = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'owner@example.test',
            'password' => 'Correct-Horse-42!',
        ])->assertOk();

        $currentSessionId = (string) $login->json('id');
        $list = $this->getJson('/api/v1/auth/sessions')->assertOk();
        $this->assertCount(2, $list->json());
        $this->assertSame(1, collect($list->json())->where('current', true)->count());

        $this->deleteJson('/api/v1/auth/sessions/'.$actor['session_id'])->assertNoContent();
        $this->assertNotNull(DB::table('auth_sessions')->where('id', $actor['session_id'])->value('revoked_at'));
        $this->assertNull(DB::table('auth_sessions')->where('id', $currentSessionId)->value('revoked_at'));

        $foreign = FoundationSchema::actor();
        $this->deleteJson('/api/v1/auth/sessions/'.$foreign['session_id'])->assertNotFound();
        $this->assertNull(DB::table('auth_sessions')->where('id', $foreign['session_id'])->value('revoked_at'));
    }

    public function test_logout_revokes_current_auth_session_even_after_membership_is_suspended(): void
    {
        $owner = FoundationSchema::actor();
        $membershipId = FoundationSchema::member($owner['organization_id']);
        $userId = (string) DB::table('organization_memberships')->where('id', $membershipId)->value('user_id');
        $this->attachCredentials($userId, 'member@example.test');

        $login = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'member@example.test',
            'password' => 'Correct-Horse-42!',
        ])->assertOk();
        $sessionId = (string) $login->json('id');

        DB::table('organization_memberships')->where('id', $membershipId)->update([
            'status' => 'suspended',
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertNotNull(DB::table('auth_sessions')->where('id', $sessionId)->value('revoked_at'));
        $this->assertSame('logout', DB::table('auth_sessions')->where('id', $sessionId)->value('revoke_reason'));

        $this->getJson('/api/v1/auth/sessions')->assertUnauthorized();
    }

    public function test_multiple_active_memberships_do_not_get_an_arbitrary_tenant_context(): void
    {
        $actor = $this->credentialedActor();
        $secondOrganizationOwner = FoundationSchema::actor();

        DB::table('organization_memberships')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $secondOrganizationOwner['organization_id'],
            'user_id' => $actor['user_id'],
            'status' => 'active',
            'is_owner' => false,
            'version' => 1,
            'authorization_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'owner@example.test',
            'password' => 'Correct-Horse-42!',
        ])->assertOk();

        $this->assertNull(DB::table('auth_sessions')
            ->where('id', (string) $login->json('id'))
            ->value('organization_membership_id'));
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function credentialedActor(): array
    {
        $actor = FoundationSchema::actor();
        $this->attachCredentials($actor['user_id'], 'owner@example.test');

        return $actor;
    }

    private function attachCredentials(string $userId, string $identifier): void
    {
        DB::table('user_password_management')->insert([
            'user_id' => $userId,
            'management_mode' => 'self_service',
            'managing_organization_id' => null,
            'credential_version' => 1,
            'password_changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->where('id', $userId)->update([
            'password_hash' => Hash::make('Correct-Horse-42!'),
            'updated_at' => now(),
        ]);
        DB::table('auth_login_identifiers')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'identifier_type' => 'email',
            'identifier_normalized' => $identifier,
            'is_primary_for_type' => true,
            'verified_at' => now(),
            'created_at' => now(),
            'revoked_at' => null,
        ]);
    }
}
