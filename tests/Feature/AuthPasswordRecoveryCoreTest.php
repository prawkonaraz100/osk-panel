<?php

namespace Tests\Feature;

use App\Modules\IdentityTenant\PasswordResetMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class AuthPasswordRecoveryCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
        Cache::flush();
        Mail::fake();

        Config::set('password_recovery.reset_url_template', 'https://app.example/reset-password?token={token}');
        Config::set('password_recovery.mailer', 'array');
    }

    public function test_forgot_is_enumeration_safe_and_delivers_only_to_verified_primary_email(): void
    {
        $actor = $this->eligibleActor();

        $this->postJson('/api/v1/auth/password/forgot', [
            'identifier' => 'OWNER-LOGIN',
        ])->assertStatus(202)->assertContent('');

        Mail::assertSent(PasswordResetMail::class, 1);
        $mail = Mail::sent(PasswordResetMail::class)->first();
        $this->assertInstanceOf(PasswordResetMail::class, $mail);
        $this->assertTrue($mail->hasTo('owner@example.test'));

        $token = $this->tokenFromMail($mail);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        $this->assertTrue(Cache::has('password-reset:token:'.hash('sha256', $token)));
        $this->assertFalse(Cache::has($token));

        Mail::fake();

        $this->postJson('/api/v1/auth/password/forgot', [
            'identifier' => 'unknown@example.test',
        ])->assertStatus(202)->assertContent('');

        Mail::assertNothingSent();

        DB::table('auth_login_identifiers')
            ->where('user_id', $actor['user_id'])
            ->where('identifier_type', 'email')
            ->update(['verified_at' => null]);

        $this->postJson('/api/v1/auth/password/forgot', [
            'identifier' => 'owner-login',
        ])->assertStatus(202)->assertContent('');

        Mail::assertNothingSent();
    }

    public function test_reissue_invalidates_previous_token_and_valid_reset_revokes_all_sessions(): void
    {
        $actor = $this->eligibleActor();

        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => 'owner-login'])
            ->assertStatus(202);
        $first = $this->latestToken();

        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => 'owner-login'])
            ->assertStatus(202);
        $second = $this->latestToken();

        $this->assertNotSame($first, $second);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $first,
            'password' => 'new-password-1',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');

        $secondSession = (string) Str::uuid7();
        DB::table('auth_sessions')->insert([
            'id' => $secondSession,
            'user_id' => $actor['user_id'],
            'organization_membership_id' => $actor['membership_id'],
            'token_or_framework_session_hash' => hash('sha256', $secondSession),
            'created_at' => now(),
            'last_seen_at' => now(),
            'revoked_at' => null,
            'revoke_reason' => null,
            'ip_hash' => null,
            'user_agent' => 'recovery-test',
        ]);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $second,
            'password' => 'new-password-2',
        ])->assertOk()->assertContent('');

        $user = DB::table('users')->where('id', $actor['user_id'])->firstOrFail();
        $this->assertTrue(Hash::check('new-password-2', (string) $user->password_hash));

        $management = DB::table('user_password_management')
            ->where('user_id', $actor['user_id'])
            ->firstOrFail();

        $this->assertSame(8, (int) $management->credential_version);
        $this->assertNotNull($management->password_changed_at);

        $sessions = DB::table('auth_sessions')
            ->where('user_id', $actor['user_id'])
            ->get(['revoked_at', 'revoke_reason']);

        $this->assertCount(2, $sessions);
        foreach ($sessions as $session) {
            $this->assertNotNull($session->revoked_at);
            $this->assertSame('password_reset', (string) $session->revoke_reason);
        }

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $second,
            'password' => 'must-not-apply',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');

        $this->assertTrue(Hash::check('new-password-2', (string) DB::table('users')
            ->where('id', $actor['user_id'])
            ->value('password_hash')));
    }

    public function test_credential_epoch_or_management_change_after_issue_invalidates_claim(): void
    {
        $actor = $this->eligibleActor();

        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => 'owner-login'])
            ->assertStatus(202);
        $token = $this->latestToken();

        DB::table('user_password_management')
            ->where('user_id', $actor['user_id'])
            ->update([
                'credential_version' => 8,
                'updated_at' => now(),
            ]);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'password' => 'must-not-apply',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');

        $this->assertTrue(Hash::check('old-password', (string) DB::table('users')
            ->where('id', $actor['user_id'])
            ->value('password_hash')));

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'password' => 'replay-must-not-apply',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');
    }

    public function test_organization_managed_identity_receives_same_forgot_response_without_token(): void
    {
        $actor = FoundationSchema::actor();
        $userId = (string) Str::uuid7();

        DB::table('users')->insert([
            'id' => $userId,
            'first_name' => 'Managed',
            'last_name' => 'Learner',
            'password_hash' => Hash::make('managed-password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_password_management')->insert([
            'user_id' => $userId,
            'management_mode' => 'organization_managed',
            'managing_organization_id' => $actor['organization_id'],
            'credential_version' => 1,
            'password_changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('auth_login_identifiers')->insert([
            [
                'id' => (string) Str::uuid7(),
                'user_id' => $userId,
                'identifier_type' => 'email',
                'identifier_normalized' => 'managed@example.test',
                'is_primary_for_type' => true,
                'verified_at' => now(),
                'created_at' => now(),
                'revoked_at' => null,
            ],
            [
                'id' => (string) Str::uuid7(),
                'user_id' => $userId,
                'identifier_type' => 'username',
                'identifier_normalized' => 'managed-login',
                'is_primary_for_type' => true,
                'verified_at' => null,
                'created_at' => now(),
                'revoked_at' => null,
            ],
        ]);

        $this->postJson('/api/v1/auth/password/forgot', [
            'identifier' => 'managed-login',
        ])->assertStatus(202)->assertContent('');

        Mail::assertNothingSent();
    }

    public function test_forgot_rate_limit_is_identical_for_unknown_identifier(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
                ->postJson('/api/v1/auth/password/forgot', [
                    'identifier' => 'unknown@example.test',
                ])->assertStatus(202);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
            ->postJson('/api/v1/auth/password/forgot', [
                'identifier' => 'unknown@example.test',
            ])->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function eligibleActor(): array
    {
        $actor = FoundationSchema::actor();

        DB::table('users')->where('id', $actor['user_id'])->update([
            'password_hash' => Hash::make('old-password'),
            'updated_at' => now(),
        ]);

        DB::table('user_password_management')->insert([
            'user_id' => $actor['user_id'],
            'management_mode' => 'self_service',
            'managing_organization_id' => null,
            'credential_version' => 7,
            'password_changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('auth_login_identifiers')->insert([
            [
                'id' => (string) Str::uuid7(),
                'user_id' => $actor['user_id'],
                'identifier_type' => 'email',
                'identifier_normalized' => 'owner@example.test',
                'is_primary_for_type' => true,
                'verified_at' => now(),
                'created_at' => now(),
                'revoked_at' => null,
            ],
            [
                'id' => (string) Str::uuid7(),
                'user_id' => $actor['user_id'],
                'identifier_type' => 'username',
                'identifier_normalized' => 'owner-login',
                'is_primary_for_type' => true,
                'verified_at' => null,
                'created_at' => now(),
                'revoked_at' => null,
            ],
        ]);

        return $actor;
    }

    private function latestToken(): string
    {
        $mail = Mail::sent(PasswordResetMail::class)->last();
        $this->assertInstanceOf(PasswordResetMail::class, $mail);

        return $this->tokenFromMail($mail);
    }

    private function tokenFromMail(PasswordResetMail $mail): string
    {
        $query = parse_url($mail->resetUrl, PHP_URL_QUERY);
        $this->assertIsString($query);
        parse_str($query, $values);

        $token = $values['token'] ?? null;
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        return $token;
    }
}
