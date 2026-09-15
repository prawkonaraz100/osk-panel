<?php

namespace App\Modules\IdentityTenant;

use App\Modules\ResourcesCore\ResourceDomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use LogicException;

final class AuthSessionService
{
    private const LOGIN_MAX_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    /**
     * @return array{user_id:string,organization_membership_id:?string}
     */
    public function verifyCredentials(string $identifier, string $password, ?string $ipAddress): array
    {
        $identifier = $this->normalizeIdentifier($identifier);
        $rateKey = $this->loginRateKey($identifier, $ipAddress);

        if (RateLimiter::tooManyAttempts($rateKey, self::LOGIN_MAX_ATTEMPTS)) {
            throw new ResourceDomainException('RATE_LIMITED', 429, 'Too many login attempts. Try again later.');
        }

        $row = DB::table('auth_login_identifiers as i')
            ->join('users as u', 'u.id', '=', 'i.user_id')
            ->where('i.identifier_normalized', $identifier)
            ->whereNull('i.revoked_at')
            ->select(['i.user_id', 'u.password_hash', 'u.status'])
            ->first();

        $valid = $row !== null
            && (string) $row->status === 'active'
            && is_string($row->password_hash)
            && $row->password_hash !== ''
            && Hash::check($password, (string) $row->password_hash);

        if (! $valid) {
            RateLimiter::hit($rateKey, self::LOGIN_DECAY_SECONDS);

            throw new AuthenticationException('Invalid login credentials.');
        }

        RateLimiter::clear($rateKey);

        $userId = (string) $row->user_id;
        $activeMembershipIds = DB::table('organization_memberships')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return [
            'user_id' => $userId,
            'organization_membership_id' => count($activeMembershipIds) === 1 ? $activeMembershipIds[0] : null,
        ];
    }

    /**
     * @return array{id:string,created_at:string,last_seen_at:?string,current:bool}
     */
    public function startSession(
        string $userId,
        ?string $organizationMembershipId,
        string $frameworkSessionId,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        if ($frameworkSessionId === '') {
            throw new LogicException('Framework session id must exist before auth session materialization.');
        }

        return DB::transaction(function () use (
            $userId,
            $organizationMembershipId,
            $frameworkSessionId,
            $ipAddress,
            $userAgent,
        ): array {
            $now = now();
            $id = (string) Str::uuid7();

            DB::table('users')->where('id', $userId)->update([
                'last_login_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('auth_sessions')->insert([
                'id' => $id,
                'user_id' => $userId,
                'organization_membership_id' => $organizationMembershipId,
                'token_or_framework_session_hash' => hash('sha256', $frameworkSessionId),
                'created_at' => $now,
                'last_seen_at' => $now,
                'revoked_at' => null,
                'revoke_reason' => null,
                'ip_hash' => $this->hashIp($ipAddress),
                'user_agent' => $this->nullableUserAgent($userAgent),
            ]);

            return [
                'id' => $id,
                'created_at' => (string) $now,
                'last_seen_at' => (string) $now,
                'current' => true,
            ];
        });
    }

    /**
     * @return list<array{id:string,created_at:string,last_seen_at:?string,current:bool}>
     */
    public function listOwn(string $currentSessionId): array
    {
        $actor = $this->requireOwnSessionPermission($currentSessionId);

        DB::table('auth_sessions')
            ->where('id', $currentSessionId)
            ->whereNull('revoked_at')
            ->update(['last_seen_at' => now()]);

        return array_values(DB::table('auth_sessions')
            ->where('user_id', $actor['user_id'])
            ->whereNull('revoked_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'created_at', 'last_seen_at'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'created_at' => (string) $row->created_at,
                'last_seen_at' => $row->last_seen_at === null ? null : (string) $row->last_seen_at,
                'current' => (string) $row->id === $currentSessionId,
            ])
            ->all());
    }

    /**
     * @return array{revoked_current:bool}
     */
    public function revokeOwn(string $currentSessionId, string $targetSessionId): array
    {
        $actor = $this->requireOwnSessionPermission($currentSessionId);

        return DB::transaction(function () use ($actor, $currentSessionId, $targetSessionId): array {
            $target = DB::table('auth_sessions')
                ->where('id', $targetSessionId)
                ->where('user_id', $actor['user_id'])
                ->lockForUpdate()
                ->first();

            if ($target === null) {
                throw ResourceDomainException::notFound();
            }

            if ($target->revoked_at === null) {
                DB::table('auth_sessions')->where('id', $targetSessionId)->update([
                    'revoked_at' => now(),
                    'revoke_reason' => 'revoked_by_user',
                ]);
            }

            return ['revoked_current' => $targetSessionId === $currentSessionId];
        });
    }

    public function revokeCurrent(string $currentSessionId, string $reason = 'logout'): void
    {
        DB::table('auth_sessions')
            ->where('id', $currentSessionId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoke_reason' => mb_substr($reason, 0, 255),
            ]);
    }

    public function safeReturnUrl(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)
            || mb_strlen($value) > 2048
            || preg_match('/[\x00-\x1F\x7F\\\\]/u', $value) === 1
            || ! str_starts_with($value, '/')
            || str_starts_with($value, '//')) {
            throw ResourceDomainException::rule('Return URL must be a local allowlisted application path.');
        }

        $parts = parse_url($value);
        if ($parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])) {
            throw ResourceDomainException::rule('Return URL must be a local allowlisted application path.');
        }

        $path = (string) ($parts['path'] ?? '/');
        $exact = [
            '/',
            '/kursanci',
            '/lokalizacje',
            '/pracownicy',
            '/pojazdy',
            '/kalendarz',
            '/historia-zakupow',
            '/licencje/panel',
            '/egzamin-wewnetrzny/panel',
        ];
        $dynamicPrefixes = ['/kursanci/', '/pracownicy/', '/pojazdy/'];

        if (! in_array($path, $exact, true)
            && ! array_any($dynamicPrefixes, static fn (string $prefix): bool => str_starts_with($path, $prefix))) {
            throw ResourceDomainException::rule('Return URL must be a local allowlisted application path.');
        }

        return $value;
    }

    /**
     * @return array{user_id:string,membership_id:string}
     */
    private function requireOwnSessionPermission(string $currentSessionId): array
    {
        $session = DB::table('auth_sessions as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.id', $currentSessionId)
            ->whereNull('s.revoked_at')
            ->where('u.status', 'active')
            ->select(['s.user_id', 's.organization_membership_id'])
            ->first();

        if ($session === null) {
            throw new AuthenticationException('Authenticated application session required.');
        }

        if ($session->organization_membership_id === null) {
            throw new AuthorizationException('Active tenant membership context required.');
        }

        $membership = DB::table('organization_memberships')
            ->where('id', $session->organization_membership_id)
            ->where('user_id', $session->user_id)
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            throw new AuthorizationException('Active tenant membership context required.');
        }

        $allowed = DB::table('membership_permissions as p')
            ->join('membership_permission_scopes as s', function ($join): void {
                $join->on('s.membership_id', '=', 'p.membership_id')
                    ->on('s.permission_code', '=', 'p.permission_code');
            })
            ->join('permission_scope_options as o', function ($join): void {
                $join->on('o.permission_code', '=', 's.permission_code')
                    ->on('o.scope_code', '=', 's.scope_code');
            })
            ->where('p.membership_id', $membership->id)
            ->where('p.permission_code', 'sessions.manage.own')
            ->where('p.granted', true)
            ->where('s.scope_code', 'own')
            ->where('o.resolver_code', 'permission_target_owner')
            ->exists();

        if (! $allowed) {
            throw new AuthorizationException('Permission denied.');
        }

        return [
            'user_id' => (string) $session->user_id,
            'membership_id' => (string) $membership->id,
        ];
    }

    private function normalizeIdentifier(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '' || mb_strlen($value) > 320 || preg_match('/\s/u', $value) === 1) {
            throw ResourceDomainException::rule('Login identifier is invalid.');
        }

        return $value;
    }

    private function loginRateKey(string $identifier, ?string $ipAddress): string
    {
        return 'auth-login:'.hash('sha256', ($ipAddress ?? 'unknown').'|'.$identifier);
    }

    private function hashIp(?string $ipAddress): ?string
    {
        $ipAddress = $ipAddress === null ? '' : trim($ipAddress);

        return $ipAddress === '' ? null : hash('sha256', $ipAddress);
    }

    private function nullableUserAgent(?string $userAgent): ?string
    {
        $userAgent = $userAgent === null ? '' : trim($userAgent);

        return $userAgent === '' ? null : mb_substr($userAgent, 0, 512);
    }
}
