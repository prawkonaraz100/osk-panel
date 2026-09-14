<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class AuthSocialCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
        Cache::flush();

        Config::set('services.social.providers.test', [
            'enabled' => true,
            'authorization_url' => 'https://provider.example/oauth/authorize',
            'token_url' => 'https://provider.example/oauth/token',
            'userinfo_url' => 'https://provider.example/oauth/userinfo',
            'redirect_uri' => 'https://osk.example/api/v1/auth/social/test/callback',
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'scopes' => ['openid', 'email'],
        ]);
    }

    public function test_redirect_is_allowlisted_and_uses_pkce_without_provider_network_io(): void
    {
        Http::fake();

        $response = $this->get('/api/v1/auth/social/test/redirect?return_url=%2Fkursanci%3Fsort%3Dname')
            ->assertRedirect();

        $query = $this->redirectQuery($response->headers->get('Location'));

        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('test-client', $query['client_id'] ?? null);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43,}$/', (string) ($query['state'] ?? ''));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', (string) ($query['code_challenge'] ?? ''));
        $this->assertSame('openid email', $query['scope'] ?? null);
        Http::assertNothingSent();

        $this->get('/api/v1/auth/social/test/redirect?return_url=https%3A%2F%2Fevil.example%2F')
            ->assertUnprocessable();

        $this->get('/api/v1/auth/social/unknown/redirect')
            ->assertNotFound();
    }

    public function test_existing_subject_sign_in_starts_existing_session_authority_without_token_persistence(): void
    {
        $actor = FoundationSchema::actor();

        DB::table('auth_social_accounts')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $actor['user_id'],
            'provider' => 'test',
            'provider_subject' => 'subject-existing',
            'created_at' => now(),
            'revoked_at' => null,
        ]);

        $state = $this->begin('/kursanci');

        $this->fakeProvider([
            'sub' => 'subject-existing',
            'email' => 'changed@example.test',
            'email_verified' => true,
        ]);

        $callback = $this->get('/api/v1/auth/social/test/callback?'.http_build_query([
            'state' => $state,
            'code' => 'authorization-code',
        ]));

        $callback->assertRedirect('/kursanci');
        $callback->assertSessionHas('auth_session_id');
        $callback->assertSessionMissing('access_token');
        $callback->assertSessionMissing('provider_token');

        $sessionId = (string) session('auth_session_id');
        $stored = DB::table('auth_sessions')->where('id', $sessionId)->firstOrFail();
        $this->assertSame($actor['user_id'], (string) $stored->user_id);
        $this->assertSame($actor['membership_id'], (string) $stored->organization_membership_id);
        $this->assertSame(1, DB::table('auth_social_accounts')->count());

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://provider.example/oauth/token'
            && is_string($request['code_verifier'] ?? null)
            && ($request['code_verifier'] ?? '') !== '');
    }

    public function test_authenticated_link_preserves_application_session_and_does_not_require_provider_email(): void
    {
        $actor = FoundationSchema::actor();
        $this->passwordManagement($actor['user_id'], 'self_service', null);

        $this->withSession(['auth_session_id' => $actor['session_id']]);
        $state = $this->begin('/');

        $this->fakeProvider([
            'sub' => 'subject-linked',
            'email_verified' => false,
        ]);

        $response = $this->get('/api/v1/auth/social/test/callback?'.http_build_query([
            'state' => $state,
            'code' => 'authorization-code',
        ]));

        $response->assertRedirect('/');
        $response->assertSessionHas('auth_session_id', $actor['session_id']);

        $linked = DB::table('auth_social_accounts')
            ->where('provider', 'test')
            ->where('provider_subject', 'subject-linked')
            ->firstOrFail();

        $this->assertSame($actor['user_id'], (string) $linked->user_id);
        $this->assertNull(DB::table('auth_sessions')->where('id', $actor['session_id'])->value('revoked_at'));
    }

    public function test_unauthenticated_first_link_requires_verified_provider_and_local_email(): void
    {
        $actor = FoundationSchema::actor();
        $this->passwordManagement($actor['user_id'], 'self_service', null);
        $identifierId = (string) Str::uuid7();

        DB::table('auth_login_identifiers')->insert([
            'id' => $identifierId,
            'user_id' => $actor['user_id'],
            'identifier_type' => 'email',
            'identifier_normalized' => 'owner@example.test',
            'is_primary_for_type' => true,
            'verified_at' => null,
            'created_at' => now(),
            'revoked_at' => null,
        ]);

        $state = $this->begin('/kursanci');
        $this->fakeProvider([
            'sub' => 'first-link-subject',
            'email' => 'owner@example.test',
            'email_verified' => true,
        ]);

        $this->get('/api/v1/auth/social/test/callback?'.http_build_query([
            'state' => $state,
            'code' => 'authorization-code',
        ]))->assertRedirect('/kursanci?social_auth=error');

        $this->assertSame(0, DB::table('auth_social_accounts')->count());
        $this->assertSame(1, DB::table('users')->count());

        DB::table('auth_login_identifiers')->where('id', $identifierId)->update(['verified_at' => now()]);

        $state = $this->begin('/kursanci');
        $this->fakeProvider([
            'sub' => 'first-link-subject',
            'email' => 'OWNER@example.test',
            'email_verified' => true,
        ]);

        $response = $this->get('/api/v1/auth/social/test/callback?'.http_build_query([
            'state' => $state,
            'code' => 'authorization-code-2',
        ]));

        $response->assertRedirect('/kursanci');
        $this->assertSame(1, DB::table('auth_social_accounts')->where('user_id', $actor['user_id'])->count());
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_provider_error_consumes_state_and_replay_never_reaches_provider(): void
    {
        Http::fake();

        $state = $this->begin('/');

        $this->get('/api/v1/auth/social/test/callback?'.http_build_query([
            'state' => $state,
            'error' => 'access_denied',
        ]))->assertRedirect('/?social_auth=error');

        $this->get('/api/v1/auth/social/test/callback?'.http_build_query([
            'state' => $state,
            'code' => 'replayed-code',
        ]))->assertRedirect('/?social_auth=error');

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('auth_social_accounts')->count());
    }

    public function test_revoked_subject_and_organization_managed_identity_fail_closed(): void
    {
        $actor = FoundationSchema::actor();
        $this->passwordManagement($actor['user_id'], 'organization_managed', $actor['organization_id']);

        DB::table('auth_login_identifiers')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $actor['user_id'],
            'identifier_type' => 'email',
            'identifier_normalized' => 'owner@example.test',
            'is_primary_for_type' => true,
            'verified_at' => now(),
            'created_at' => now(),
            'revoked_at' => null,
        ]);
        DB::table('auth_social_accounts')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $actor['user_id'],
            'provider' => 'test',
            'provider_subject' => 'revoked-subject',
            'created_at' => now()->subMinute(),
            'revoked_at' => now(),
        ]);

        $state = $this->begin('/');
        $this->fakeProvider([
            'sub' => 'revoked-subject',
            'email' => 'owner@example.test',
            'email_verified' => true,
        ]);

        $this->get('/api/v1/auth/social/test/callback?'.http_build_query([
            'state' => $state,
            'code' => 'authorization-code',
        ]))->assertRedirect('/?social_auth=error');

        $this->assertSame(1, DB::table('auth_social_accounts')->count());

        $this->withSession(['auth_session_id' => $actor['session_id']]);
        $state = $this->begin('/');
        $this->fakeProvider([
            'sub' => 'new-managed-subject',
            'email_verified' => false,
        ]);

        $this->get('/api/v1/auth/social/test/callback?'.http_build_query([
            'state' => $state,
            'code' => 'authorization-code-2',
        ]))->assertRedirect('/?social_auth=error');

        $this->assertSame(0, DB::table('auth_social_accounts')->where('provider_subject', 'new-managed-subject')->count());
    }

    private function begin(string $returnUrl): string
    {
        $response = $this->get('/api/v1/auth/social/test/redirect?'.http_build_query([
            'return_url' => $returnUrl,
        ]))->assertRedirect();

        $query = $this->redirectQuery($response->headers->get('Location'));
        $state = $query['state'] ?? null;
        $this->assertIsString($state);
        $this->assertNotSame('', $state);

        return $state;
    }

    /** @param array<string,mixed> $profile */
    private function fakeProvider(array $profile): void
    {
        Http::fake([
            'https://provider.example/oauth/token' => Http::response([
                'access_token' => 'transient-access-token',
                'token_type' => 'Bearer',
            ]),
            'https://provider.example/oauth/userinfo' => Http::response($profile),
        ]);
    }

    /** @return array<string,string> */
    private function redirectQuery(?string $location): array
    {
        $this->assertIsString($location);
        $query = parse_url($location, PHP_URL_QUERY);
        $this->assertIsString($query);

        parse_str($query, $values);

        return array_map(static fn ($value): string => (string) $value, $values);
    }

    private function passwordManagement(string $userId, string $mode, ?string $organizationId): void
    {
        DB::table('user_password_management')->insert([
            'user_id' => $userId,
            'management_mode' => $mode,
            'managing_organization_id' => $organizationId,
            'credential_version' => 1,
            'password_changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
