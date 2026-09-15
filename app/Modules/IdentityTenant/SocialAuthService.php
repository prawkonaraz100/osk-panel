<?php

namespace App\Modules\IdentityTenant;

use App\Modules\ResourcesCore\ResourceDomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class SocialAuthService
{
    private const STATE_TTL_SECONDS = 600;

    private const PROVIDER_PATTERN = '/^[a-z0-9_-]{2,32}$/';

    public function __construct(
        private readonly AuthSessionService $sessions,
    ) {}

    public function begin(
        string $provider,
        string $frameworkSessionBinding,
        ?string $authSessionId,
        mixed $returnUrl,
    ): string {
        $config = $this->providerConfig($provider);
        if ($config === null) {
            throw ResourceDomainException::notFound('Social provider is unavailable.');
        }

        $validatedReturnUrl = $this->sessions->safeReturnUrl($returnUrl) ?? '/';
        $activeSession = $this->activeApplicationSession($authSessionId);
        $mode = $activeSession === null ? 'sign_in' : 'authenticated_link';

        $state = $this->randomToken();
        $verifier = $this->randomToken();
        $challenge = $this->base64Url(hash('sha256', $verifier, true));
        $stateHash = hash('sha256', $state);

        $stored = Cache::put($this->stateKey($stateHash), [
            'provider' => $provider,
            'framework_session_binding_hash' => hash('sha256', $frameworkSessionBinding),
            'return_url' => $validatedReturnUrl,
            'initiation_mode' => $mode,
            'initiating_user_id' => $activeSession['user_id'] ?? null,
            'initiating_auth_session_id' => $activeSession['session_id'] ?? null,
            'pkce_verifier' => $verifier,
            'expires_at' => now()->addSeconds(self::STATE_TTL_SECONDS)->getTimestamp(),
        ], self::STATE_TTL_SECONDS);

        if (! $stored) {
            throw new ResourceDomainException(
                'AUTH_PROVIDER_UNAVAILABLE',
                503,
                'Social sign-in is temporarily unavailable.',
            );
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'scope' => implode(' ', $config['scopes']),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $config['authorization_url'].(str_contains($config['authorization_url'], '?') ? '&' : '?').$query;
    }

    /**
     * @return array{mode:string,user_id:string,organization_membership_id:?string,return_url:string}
     */
    public function callback(
        string $provider,
        string $frameworkSessionBinding,
        ?string $currentAuthSessionId,
        mixed $state,
        mixed $code,
        mixed $providerError,
    ): array {
        $claimed = $this->claimState($state);
        $returnUrl = is_string($claimed['return_url'] ?? null) ? $claimed['return_url'] : '/';

        if (($claimed['provider'] ?? null) !== $provider
            || ($claimed['framework_session_binding_hash'] ?? null) !== hash('sha256', $frameworkSessionBinding)
            || ! is_int($claimed['expires_at'] ?? null)
            || $claimed['expires_at'] <= now()->getTimestamp()) {
            throw new SocialAuthFlowException($returnUrl);
        }

        $mode = $claimed['initiation_mode'] ?? null;
        if (! is_string($mode) || ! in_array($mode, ['sign_in', 'authenticated_link'], true)) {
            throw new SocialAuthFlowException($returnUrl);
        }

        if ($mode === 'authenticated_link'
            && (! is_string($claimed['initiating_auth_session_id'] ?? null)
                || $claimed['initiating_auth_session_id'] === ''
                || $currentAuthSessionId !== $claimed['initiating_auth_session_id'])) {
            throw new SocialAuthFlowException($returnUrl);
        }

        if (is_string($providerError) && trim($providerError) !== '') {
            throw new SocialAuthFlowException($returnUrl);
        }

        if (! is_string($code) || trim($code) === '') {
            throw new SocialAuthFlowException($returnUrl);
        }

        $config = $this->providerConfig($provider);
        if ($config === null || ! is_string($claimed['pkce_verifier'] ?? null) || $claimed['pkce_verifier'] === '') {
            throw new SocialAuthFlowException($returnUrl);
        }

        [$subject, $verifiedEmail] = $this->providerIdentity(
            $config,
            trim($code),
            $claimed['pkce_verifier'],
            $returnUrl,
        );

        try {
            $userId = DB::transaction(function () use (
                $provider,
                $subject,
                $verifiedEmail,
                $mode,
                $claimed,
                $returnUrl,
            ): string {
                $existing = DB::table('auth_social_accounts')
                    ->where('provider', $provider)
                    ->where('provider_subject', $subject)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if ($existing->revoked_at !== null) {
                        throw new SocialAuthFlowException($returnUrl);
                    }

                    $user = DB::table('users')->where('id', $existing->user_id)->lockForUpdate()->first();
                    if ($user === null || (string) $user->status !== 'active') {
                        throw new SocialAuthFlowException($returnUrl);
                    }

                    if ($mode === 'authenticated_link') {
                        $initiatingUserId = $claimed['initiating_user_id'] ?? null;
                        $initiatingSessionId = $claimed['initiating_auth_session_id'] ?? null;
                        if (! is_string($initiatingUserId)
                            || ! is_string($initiatingSessionId)
                            || $initiatingUserId !== (string) $existing->user_id
                            || ! $this->authSessionStillActive($initiatingSessionId, $initiatingUserId)) {
                            throw new SocialAuthFlowException($returnUrl);
                        }
                    }

                    return (string) $existing->user_id;
                }

                if ($mode === 'authenticated_link') {
                    $initiatingUserId = $claimed['initiating_user_id'] ?? null;
                    $initiatingSessionId = $claimed['initiating_auth_session_id'] ?? null;
                    if (! is_string($initiatingUserId)
                        || ! is_string($initiatingSessionId)
                        || ! $this->authSessionStillActive($initiatingSessionId, $initiatingUserId)) {
                        throw new SocialAuthFlowException($returnUrl);
                    }

                    $this->assertSocialLinkAllowed($initiatingUserId, $returnUrl);
                    $this->insertSocialAccount($initiatingUserId, $provider, $subject);

                    return $initiatingUserId;
                }

                if ($verifiedEmail === null) {
                    throw new SocialAuthFlowException($returnUrl);
                }

                $identifiers = DB::table('auth_login_identifiers')
                    ->where('identifier_type', 'email')
                    ->where('identifier_normalized', $verifiedEmail)
                    ->whereNull('revoked_at')
                    ->whereNotNull('verified_at')
                    ->lockForUpdate()
                    ->get(['user_id']);

                if ($identifiers->count() !== 1) {
                    throw new SocialAuthFlowException($returnUrl);
                }

                $identifier = $identifiers->first();
                if (! is_object($identifier) || ! isset($identifier->user_id)) {
                    throw new SocialAuthFlowException($returnUrl);
                }

                $targetUserId = (string) $identifier->user_id;
                $user = DB::table('users')->where('id', $targetUserId)->lockForUpdate()->first();
                if ($user === null || (string) $user->status !== 'active') {
                    throw new SocialAuthFlowException($returnUrl);
                }

                $this->assertSocialLinkAllowed($targetUserId, $returnUrl);
                $this->insertSocialAccount($targetUserId, $provider, $subject);

                return $targetUserId;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? $exception->getCode()) === '23505') {
                throw new SocialAuthFlowException($returnUrl);
            }

            throw $exception;
        }

        return [
            'mode' => $mode,
            'user_id' => $userId,
            'organization_membership_id' => $mode === 'sign_in' ? $this->singleActiveMembershipId($userId) : null,
            'return_url' => $returnUrl,
        ];
    }

    /**
     * @param  array{token_url:string,userinfo_url:string,redirect_uri:string,client_id:string,client_secret:string,scopes:list<string>}  $config
     * @return array{0:string,1:?string}
     */
    private function providerIdentity(array $config, string $code, string $verifier, string $returnUrl): array
    {
        try {
            $tokenResponse = Http::asForm()
                ->acceptJson()
                ->timeout(10)
                ->post($config['token_url'], [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $config['redirect_uri'],
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'code_verifier' => $verifier,
                ]);

            if (! $tokenResponse->successful()) {
                throw new SocialAuthFlowException($returnUrl);
            }

            $tokenPayload = $tokenResponse->json();
            $accessToken = is_array($tokenPayload) ? ($tokenPayload['access_token'] ?? null) : null;
            if (! is_string($accessToken) || $accessToken === '' || strlen($accessToken) > 8192) {
                throw new SocialAuthFlowException($returnUrl);
            }

            $userInfoResponse = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(10)
                ->get($config['userinfo_url']);

            if (! $userInfoResponse->successful()) {
                throw new SocialAuthFlowException($returnUrl);
            }

            $profile = $userInfoResponse->json();
            if (! is_array($profile)) {
                throw new SocialAuthFlowException($returnUrl);
            }

            $subject = $profile['sub'] ?? null;
            if (! is_string($subject) || trim($subject) === '' || mb_strlen($subject) > 255) {
                throw new SocialAuthFlowException($returnUrl);
            }

            $verified = filter_var($profile['email_verified'] ?? false, FILTER_VALIDATE_BOOL);
            $email = $profile['email'] ?? null;
            $verifiedEmail = null;
            if ($verified === true && is_string($email)) {
                $normalized = mb_strtolower(trim($email));
                if (mb_strlen($normalized) <= 320 && filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false) {
                    $verifiedEmail = $normalized;
                }
            }

            return [trim($subject), $verifiedEmail];
        } catch (SocialAuthFlowException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new SocialAuthFlowException($returnUrl);
        }
    }

    /** @return array<string,mixed> */
    private function claimState(mixed $state): array
    {
        if (! is_string($state) || $state === '' || strlen($state) > 512) {
            throw new SocialAuthFlowException('/');
        }

        $stateHash = hash('sha256', $state);
        $lock = Cache::lock('social-oauth:claim:'.$stateHash, 5);
        if (! $lock->get()) {
            throw new SocialAuthFlowException('/');
        }

        try {
            $key = $this->stateKey($stateHash);
            $claimed = Cache::get($key);
            Cache::forget($key);
        } finally {
            $lock->release();
        }

        if (! is_array($claimed)) {
            throw new SocialAuthFlowException('/');
        }

        return $claimed;
    }

    /** @return array{session_id:string,user_id:string}|null */
    private function activeApplicationSession(?string $authSessionId): ?array
    {
        if ($authSessionId === null || $authSessionId === '') {
            return null;
        }

        $row = DB::table('auth_sessions as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.id', $authSessionId)
            ->whereNull('s.revoked_at')
            ->where('u.status', 'active')
            ->select(['s.id', 's.user_id'])
            ->first();

        return $row === null ? null : [
            'session_id' => (string) $row->id,
            'user_id' => (string) $row->user_id,
        ];
    }

    private function authSessionStillActive(string $authSessionId, string $userId): bool
    {
        $session = DB::table('auth_sessions')
            ->where('id', $authSessionId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->first();

        if ($session === null) {
            return false;
        }

        $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();

        return $user !== null && (string) $user->status === 'active';
    }

    private function assertSocialLinkAllowed(string $userId, string $returnUrl): void
    {
        $management = DB::table('user_password_management')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if ($management === null || (string) $management->management_mode === 'organization_managed') {
            throw new SocialAuthFlowException($returnUrl);
        }
    }

    private function insertSocialAccount(string $userId, string $provider, string $subject): void
    {
        DB::table('auth_social_accounts')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'provider' => $provider,
            'provider_subject' => $subject,
            'created_at' => now(),
            'revoked_at' => null,
        ]);
    }

    private function singleActiveMembershipId(string $userId): ?string
    {
        $ids = DB::table('organization_memberships')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * @return array{authorization_url:string,token_url:string,userinfo_url:string,redirect_uri:string,client_id:string,client_secret:string,scopes:list<string>}|null
     */
    private function providerConfig(string $provider): ?array
    {
        if (preg_match(self::PROVIDER_PATTERN, $provider) !== 1) {
            return null;
        }

        $raw = config('services.social.providers.'.$provider);
        if (! is_array($raw) || ($raw['enabled'] ?? false) !== true) {
            return null;
        }

        $keys = ['authorization_url', 'token_url', 'userinfo_url', 'redirect_uri', 'client_id', 'client_secret'];
        $values = [];
        foreach ($keys as $key) {
            $value = $raw[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                return null;
            }
            $values[$key] = trim($value);
        }

        foreach (['authorization_url', 'token_url', 'userinfo_url', 'redirect_uri'] as $urlKey) {
            $parts = parse_url($values[$urlKey]);
            if ($parts === false || ! isset($parts['scheme'], $parts['host'])
                || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
                return null;
            }

            if (app()->environment('production') && strtolower((string) $parts['scheme']) !== 'https') {
                return null;
            }
        }

        $scopes = $raw['scopes'] ?? null;
        if (! is_array($scopes) || $scopes === []) {
            return null;
        }

        $normalizedScopes = [];
        foreach ($scopes as $scope) {
            if (! is_string($scope) || trim($scope) === '') {
                return null;
            }
            $normalizedScopes[] = trim($scope);
        }

        return [
            'authorization_url' => $values['authorization_url'],
            'token_url' => $values['token_url'],
            'userinfo_url' => $values['userinfo_url'],
            'redirect_uri' => $values['redirect_uri'],
            'client_id' => $values['client_id'],
            'client_secret' => $values['client_secret'],
            'scopes' => $normalizedScopes,
        ];
    }

    private function randomToken(): string
    {
        return $this->base64Url(random_bytes(32));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function stateKey(string $stateHash): string
    {
        return 'social-oauth:state:'.$stateHash;
    }
}
