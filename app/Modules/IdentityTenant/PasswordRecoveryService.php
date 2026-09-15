<?php

namespace App\Modules\IdentityTenant;

use App\Modules\ResourcesCore\ResourceDomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

final class PasswordRecoveryService
{
    private const TOKEN_TTL_SECONDS = 1800;

    private const FORGOT_MAX_ATTEMPTS = 5;

    private const FORGOT_DECAY_SECONDS = 300;

    private const RESET_MAX_ATTEMPTS = 10;

    private const RESET_DECAY_SECONDS = 300;

    public function forgot(string $identifier, ?string $ipAddress): void
    {
        $normalized = mb_strtolower(trim($identifier));
        $rateKey = 'auth-password-forgot:'.hash('sha256', ($ipAddress ?? 'unknown').'|'.$normalized);

        if (RateLimiter::tooManyAttempts($rateKey, self::FORGOT_MAX_ATTEMPTS)) {
            throw new ResourceDomainException('RATE_LIMITED', 429, 'Too many password recovery attempts. Try again later.');
        }

        RateLimiter::hit($rateKey, self::FORGOT_DECAY_SECONDS);

        if ($normalized === ''
            || mb_strlen($normalized) > 320
            || preg_match('/\s/u', $normalized) === 1) {
            return;
        }

        $identifiers = DB::table('auth_login_identifiers')
            ->where('identifier_normalized', $normalized)
            ->whereNull('revoked_at')
            ->get(['user_id']);

        if ($identifiers->count() !== 1) {
            return;
        }

        $identifierRow = $identifiers->first();
        if (! is_object($identifierRow) || ! isset($identifierRow->user_id)) {
            return;
        }

        $userId = (string) $identifierRow->user_id;
        $delivery = $this->issueTokenForEligibleUser($userId);
        if ($delivery === null) {
            return;
        }

        try {
            $mailer = $this->secretSafeMailer();
            if ($mailer === null) {
                throw new RuntimeException('Password reset mail transport is not secret-safe.');
            }

            Mail::mailer($mailer)
                ->to($delivery['email'])
                ->send(new PasswordResetMail($delivery['reset_url']));
        } catch (Throwable) {
            $this->invalidateIssuedToken($userId, $delivery['token_hash']);
            report(new RuntimeException('Password reset delivery failed.'));
        }
    }

    public function reset(string $token, string $password, ?string $ipAddress): void
    {
        $rateKey = 'auth-password-reset:'.hash('sha256', $ipAddress ?? 'unknown');

        if (RateLimiter::tooManyAttempts($rateKey, self::RESET_MAX_ATTEMPTS)) {
            throw new ResourceDomainException('RATE_LIMITED', 429, 'Too many password reset attempts. Try again later.');
        }

        RateLimiter::hit($rateKey, self::RESET_DECAY_SECONDS);

        $state = $this->claimToken($token);

        DB::transaction(function () use ($state, $password): void {
            $management = DB::table('user_password_management')
                ->where('user_id', $state['user_id'])
                ->lockForUpdate()
                ->first();

            if ($management === null
                || (string) $management->management_mode !== 'self_service'
                || (int) $management->credential_version !== $state['credential_version_at_issue']) {
                throw $this->invalidToken();
            }

            $user = DB::table('users')
                ->where('id', $state['user_id'])
                ->lockForUpdate()
                ->first();

            if ($user === null || (string) $user->status !== 'active') {
                throw $this->invalidToken();
            }

            $now = now();

            DB::table('users')
                ->where('id', $state['user_id'])
                ->update([
                    'password_hash' => Hash::make($password),
                    'updated_at' => $now,
                ]);

            DB::table('user_password_management')
                ->where('user_id', $state['user_id'])
                ->update([
                    'credential_version' => $state['credential_version_at_issue'] + 1,
                    'password_changed_at' => $now,
                    'updated_at' => $now,
                ]);

            DB::table('auth_sessions')
                ->where('user_id', $state['user_id'])
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $now,
                    'revoke_reason' => 'password_reset',
                ]);
        });
    }

    /**
     * @return array{email:string,token_hash:string,reset_url:string}|null
     */
    private function issueTokenForEligibleUser(string $userId): ?array
    {
        $lock = Cache::lock($this->issueLockKey($userId), 10);
        if (! $lock->get()) {
            return null;
        }

        try {
            $management = DB::table('user_password_management')
                ->where('user_id', $userId)
                ->first();

            $user = DB::table('users')
                ->where('id', $userId)
                ->first();

            if ($management === null
                || $user === null
                || (string) $user->status !== 'active'
                || (string) $management->management_mode !== 'self_service') {
                return null;
            }

            $emails = DB::table('auth_login_identifiers')
                ->where('user_id', $userId)
                ->where('identifier_type', 'email')
                ->where('is_primary_for_type', true)
                ->whereNull('revoked_at')
                ->whereNotNull('verified_at')
                ->get(['identifier_normalized']);

            if ($emails->count() !== 1) {
                return null;
            }

            $emailRow = $emails->first();
            if (! is_object($emailRow) || ! isset($emailRow->identifier_normalized)) {
                return null;
            }

            $email = mb_strtolower(trim((string) $emailRow->identifier_normalized));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 320) {
                return null;
            }

            $rawToken = $this->randomToken();
            $tokenHash = hash('sha256', $rawToken);

            try {
                $resetUrl = $this->resetUrl($rawToken);
            } catch (Throwable) {
                report(new RuntimeException('Password reset URL configuration is invalid.'));

                return null;
            }

            $pointerKey = $this->userPointerKey($userId);
            $previousHash = Cache::get($pointerKey);
            if (is_string($previousHash) && $previousHash !== '') {
                Cache::forget($this->tokenStateKey($previousHash));
            }

            $expiresAt = now()->addSeconds(self::TOKEN_TTL_SECONDS)->getTimestamp();
            $storedState = Cache::put($this->tokenStateKey($tokenHash), [
                'user_id' => $userId,
                'credential_version_at_issue' => (int) $management->credential_version,
                'expires_at' => $expiresAt,
            ], self::TOKEN_TTL_SECONDS);

            if (! $storedState) {
                return null;
            }

            $storedPointer = Cache::put($pointerKey, $tokenHash, self::TOKEN_TTL_SECONDS);
            if (! $storedPointer) {
                Cache::forget($this->tokenStateKey($tokenHash));

                return null;
            }

            return [
                'email' => $email,
                'token_hash' => $tokenHash,
                'reset_url' => $resetUrl,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{user_id:string,credential_version_at_issue:int,expires_at:int}
     */
    private function claimToken(string $token): array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 512) {
            throw $this->invalidToken();
        }

        $tokenHash = hash('sha256', $token);
        $lock = Cache::lock('password-reset:claim:'.$tokenHash, 5);

        if (! $lock->get()) {
            throw $this->invalidToken();
        }

        try {
            $state = Cache::get($this->tokenStateKey($tokenHash));
            if (! is_array($state)) {
                throw $this->invalidToken();
            }

            $userId = $state['user_id'] ?? null;
            $credentialVersion = $state['credential_version_at_issue'] ?? null;
            $expiresAt = $state['expires_at'] ?? null;

            if (! is_string($userId)
                || $userId === ''
                || ! is_int($credentialVersion)
                || ! is_int($expiresAt)) {
                throw $this->invalidToken();
            }

            $pointerKey = $this->userPointerKey($userId);
            $pointer = Cache::get($pointerKey);

            if (! is_string($pointer) || ! hash_equals($pointer, $tokenHash)) {
                throw $this->invalidToken();
            }

            Cache::forget($this->tokenStateKey($tokenHash));
            Cache::forget($pointerKey);

            if ($expiresAt <= now()->getTimestamp()) {
                throw $this->invalidToken();
            }

            return [
                'user_id' => $userId,
                'credential_version_at_issue' => $credentialVersion,
                'expires_at' => $expiresAt,
            ];
        } finally {
            $lock->release();
        }
    }

    private function invalidateIssuedToken(string $userId, string $tokenHash): void
    {
        $lock = Cache::lock($this->issueLockKey($userId), 10);
        if (! $lock->get()) {
            return;
        }

        try {
            $pointerKey = $this->userPointerKey($userId);
            $pointer = Cache::get($pointerKey);

            if (is_string($pointer) && hash_equals($pointer, $tokenHash)) {
                Cache::forget($this->tokenStateKey($tokenHash));
                Cache::forget($pointerKey);
            }
        } finally {
            $lock->release();
        }
    }

    private function resetUrl(string $rawToken): string
    {
        $template = config('password_recovery.reset_url_template');
        if (! is_string($template)
            || ! str_contains($template, '{token}')
            || mb_strlen($template) > 4096) {
            throw new RuntimeException('Password reset URL template is invalid.');
        }

        $url = str_replace('{token}', rawurlencode($rawToken), $template);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Password reset URL must be absolute.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Password reset URL scheme is invalid.');
        }

        if (app()->environment('production') && $scheme !== 'https') {
            throw new RuntimeException('Production password reset URL must use HTTPS.');
        }

        return $url;
    }

    private function secretSafeMailer(): ?string
    {
        $configured = config('password_recovery.mailer');
        $mailer = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : (string) config('mail.default');

        return $mailer !== '' && $this->mailerIsSecretSafe($mailer) ? $mailer : null;
    }

    /**
     * @param  array<string,bool>  $visited
     */
    private function mailerIsSecretSafe(string $mailer, array $visited = []): bool
    {
        if (isset($visited[$mailer])) {
            return false;
        }

        $visited[$mailer] = true;
        $config = config('mail.mailers.'.$mailer);
        if (! is_array($config)) {
            return false;
        }

        $transport = $config['transport'] ?? null;
        if (! is_string($transport)) {
            return false;
        }

        if (in_array($transport, [
            'smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'sendmail', 'mailgun', 'array',
        ], true)) {
            return true;
        }

        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return false;
        }

        $children = $config['mailers'] ?? null;
        if (! is_array($children) || $children === []) {
            return false;
        }

        foreach ($children as $child) {
            if (! is_string($child) || ! $this->mailerIsSecretSafe($child, $visited)) {
                return false;
            }
        }

        return true;
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function tokenStateKey(string $tokenHash): string
    {
        return 'password-reset:token:'.$tokenHash;
    }

    private function userPointerKey(string $userId): string
    {
        return 'password-reset:user:'.hash('sha256', $userId);
    }

    private function issueLockKey(string $userId): string
    {
        return 'password-reset:issue:'.hash('sha256', $userId);
    }

    private function invalidToken(): ResourceDomainException
    {
        return new ResourceDomainException(
            'PASSWORD_RESET_INVALID',
            422,
            'Password reset token is invalid or expired.',
        );
    }
}
