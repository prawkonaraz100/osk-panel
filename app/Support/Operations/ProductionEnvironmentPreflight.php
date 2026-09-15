<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ProductionEnvironmentPreflight
{
    /**
     * @return array{
     *   policy_version:string,
     *   status:string,
     *   live_requested:bool,
     *   live_skipped:bool,
     *   static_checks:array<string,array{status:string,failure_code:?string}>,
     *   live_checks:array<string,array{status:string,failure_code:?string}>,
     *   failures:int,
     *   external_evidence_required:list<string>,
     *   PKK_required:bool,
     *   payment_provider_webhook_required:bool
     * }
     */
    public function run(bool $live): array
    {
        $staticChecks = $this->staticChecks();
        $staticFailures = $this->failureCount($staticChecks);
        $liveChecks = [];
        $liveSkipped = false;

        if ($live) {
            if ($staticFailures === 0) {
                $liveChecks = $this->liveChecks();
            } else {
                $liveSkipped = true;
            }
        }

        $failures = $staticFailures + $this->failureCount($liveChecks);

        return [
            'policy_version' => $this->policyVersion(),
            'status' => $failures === 0 && ! $liveSkipped ? 'pass' : 'fail',
            'live_requested' => $live,
            'live_skipped' => $liveSkipped,
            'static_checks' => $staticChecks,
            'live_checks' => $liveChecks,
            'failures' => $failures + ($liveSkipped ? 1 : 0),
            'external_evidence_required' => $this->externalEvidence(),
            'PKK_required' => false,
            'payment_provider_webhook_required' => false,
        ];
    }

    /**
     * @return array<string,array{status:string,failure_code:?string}>
     */
    public function staticChecks(): array
    {
        $appUrl = $this->stringConfig('app.url');
        $appKey = $this->stringConfig('app.key');
        $sensitiveKey = $this->stringConfig('security.sensitive_identifiers.lookup_key');
        $databaseUrl = $this->stringConfig('database.connections.pgsql.url');
        $databaseUsername = $this->stringConfig('database.connections.pgsql.username');
        $databasePassword = $this->stringConfig('database.connections.pgsql.password');
        $redisUrl = $this->stringConfig('database.redis.default.url');
        $redisPassword = $this->stringConfig('database.redis.default.password');
        $s3Key = $this->stringConfig('filesystems.disks.s3.key');
        $s3Secret = $this->stringConfig('filesystems.disks.s3.secret');
        $s3Bucket = $this->stringConfig('filesystems.disks.s3.bucket');
        $s3Endpoint = $this->stringConfig('filesystems.disks.s3.endpoint');
        $resetUrl = $this->stringConfig('password_recovery.reset_url_template');
        $mailFrom = $this->stringConfig('mail.from.address');

        $knownDatabasePassword = $this->stringConfig('production_preflight.known_development_values.database_password');
        $knownS3Key = $this->stringConfig('production_preflight.known_development_values.s3_access_key');
        $knownS3Secret = $this->stringConfig('production_preflight.known_development_values.s3_secret_key');
        $knownS3Bucket = $this->stringConfig('production_preflight.known_development_values.s3_bucket');

        $databaseCredentialsSafe = $databaseUrl !== null
            || (
                $databaseUsername !== null
                && $databasePassword !== null
                && ($knownDatabasePassword === null || ! hash_equals($knownDatabasePassword, $databasePassword))
            );

        $s3DevelopmentPair = $s3Key !== null
            && $s3Secret !== null
            && $knownS3Key !== null
            && $knownS3Secret !== null
            && hash_equals($knownS3Key, $s3Key)
            && hash_equals($knownS3Secret, $s3Secret);

        $checks = [
            'app_environment_production' => $this->check(
                config('app.env') === 'production',
                'APP_ENV_NOT_PRODUCTION',
            ),
            'app_debug_disabled' => $this->check(
                config('app.debug') === false,
                'APP_DEBUG_ENABLED',
            ),
            'app_url_https_nonlocal' => $this->check(
                $this->isHttpsNonlocalUrl($appUrl),
                'APP_URL_NOT_PRODUCTION_HTTPS',
            ),
            'app_key_strong' => $this->check(
                $this->secretMaterialLength($appKey) >= 32,
                'APP_KEY_MISSING_OR_WEAK',
            ),
            'sensitive_identifier_key_independent' => $this->check(
                $sensitiveKey !== null
                    && strlen($sensitiveKey) >= 32
                    && ($appKey === null || ! hash_equals($appKey, $sensitiveKey)),
                'SENSITIVE_IDENTIFIER_KEY_MISSING_WEAK_OR_REUSED',
            ),
            'database_postgresql' => $this->check(
                config('database.default') === 'pgsql',
                'DATABASE_NOT_POSTGRESQL',
            ),
            'database_credentials_not_development' => $this->check(
                $databaseCredentialsSafe,
                'DATABASE_CREDENTIALS_MISSING_OR_DEVELOPMENT',
            ),
            'redis_authenticated_or_managed_url' => $this->check(
                $redisUrl !== null || $redisPassword !== null,
                'REDIS_AUTH_OR_MANAGED_URL_MISSING',
            ),
            'cache_redis' => $this->check(
                config('cache.default') === 'redis',
                'CACHE_NOT_REDIS',
            ),
            'session_redis' => $this->check(
                config('session.driver') === 'redis',
                'SESSION_NOT_REDIS',
            ),
            'session_secure_cookie' => $this->check(
                config('session.secure') === true,
                'SESSION_SECURE_COOKIE_DISABLED',
            ),
            'session_http_only' => $this->check(
                config('session.http_only') === true,
                'SESSION_HTTP_ONLY_DISABLED',
            ),
            'session_json_serialization' => $this->check(
                config('session.serialization') === 'json',
                'SESSION_SERIALIZATION_NOT_JSON',
            ),
            'queue_redis' => $this->check(
                config('queue.default') === 'redis',
                'QUEUE_NOT_REDIS',
            ),
            'failed_jobs_durable' => $this->check(
                ! in_array(config('queue.failed.driver'), [null, 'null', 'file'], true),
                'FAILED_JOB_VISIBILITY_NOT_DURABLE',
            ),
            'filesystem_s3' => $this->check(
                config('filesystems.default') === 's3' && config('uploads.disk') === 's3',
                'FORMAL_STORAGE_NOT_S3',
            ),
            's3_bucket_not_development' => $this->check(
                $s3Bucket !== null
                    && ($knownS3Bucket === null || ! hash_equals($knownS3Bucket, $s3Bucket)),
                'S3_BUCKET_MISSING_OR_DEVELOPMENT',
            ),
            's3_credentials_not_development_pair' => $this->check(
                ! $s3DevelopmentPair,
                'S3_DEVELOPMENT_CREDENTIALS_PRESENT',
            ),
            's3_endpoint_secure_if_custom' => $this->check(
                $s3Endpoint === null || $this->isHttpsNonlocalUrl($s3Endpoint),
                'S3_CUSTOM_ENDPOINT_NOT_PRODUCTION_HTTPS',
            ),
            'structured_json_stderr_logging' => $this->check(
                config('logging.default') === 'json_stderr',
                'LOG_CHANNEL_NOT_JSON_STDERR',
            ),
            'production_log_level' => $this->check(
                in_array(config('logging.channels.json_stderr.level'), [
                    'info',
                    'notice',
                    'warning',
                    'error',
                    'critical',
                    'alert',
                    'emergency',
                ], true),
                'LOG_LEVEL_TOO_VERBOSE_OR_INVALID',
            ),
            'password_reset_https' => $this->check(
                $resetUrl !== null
                    && str_contains($resetUrl, '{token}')
                    && $this->isHttpsNonlocalUrl(str_replace('{token}', 'placeholder', $resetUrl)),
                'PASSWORD_RESET_URL_NOT_PRODUCTION_HTTPS',
            ),
            'password_reset_mailer_secret_safe' => $this->check(
                $this->productionMailerIsSafe(),
                'PASSWORD_RESET_MAILER_UNSAFE',
            ),
            'mail_from_valid' => $this->check(
                $mailFrom !== null
                    && filter_var($mailFrom, FILTER_VALIDATE_EMAIL) !== false
                    && ! str_ends_with(strtolower($mailFrom), '@example.com'),
                'MAIL_FROM_MISSING_OR_PLACEHOLDER',
            ),
            'incident_contact_refs_present' => $this->check(
                $this->incidentContactRefsPresent(),
                'INCIDENT_CONTACT_REFS_INCOMPLETE',
            ),
            'retention_executor_disabled_at_boot' => $this->check(
                config('retention.executor.enabled') === false,
                'RETENTION_EXECUTOR_ENABLED_AT_BOOT',
            ),
        ];

        return $checks;
    }

    /**
     * @return array<string,array{status:string,failure_code:?string}>
     */
    public function liveChecks(): array
    {
        return [
            'postgresql_connectivity' => $this->safeLiveCheck(function (): bool {
                return DB::selectOne('select 1 as ready') !== null;
            }, 'POSTGRESQL_CONNECTIVITY_FAILED'),

            'redis_connectivity' => $this->safeLiveCheck(function (): bool {
                return Redis::connection()->command('ping') !== false;
            }, 'REDIS_CONNECTIVITY_FAILED'),

            'redis_cache_round_trip' => $this->safeLiveCheck(function (): bool {
                $key = 'production-preflight:cache:'.Str::uuid7();

                try {
                    if (! Cache::store('redis')->put($key, 'ok', 30)) {
                        return false;
                    }

                    return Cache::store('redis')->get($key) === 'ok';
                } finally {
                    Cache::store('redis')->forget($key);
                }
            }, 'REDIS_CACHE_ROUND_TRIP_FAILED'),

            'redis_queue_visibility' => $this->safeLiveCheck(function (): bool {
                return Queue::connection('redis')->size() >= 0;
            }, 'REDIS_QUEUE_ACCESS_FAILED'),

            'object_storage_round_trip' => $this->safeLiveCheck(function (): bool {
                $diskName = $this->stringConfig('uploads.disk');
                if ($diskName === null) {
                    return false;
                }

                $disk = Storage::disk($diskName);
                $key = 'production-preflight/'.Str::uuid7().'.txt';
                $written = false;

                try {
                    $written = $disk->put($key, 'production-preflight-ok');
                    if (! $written) {
                        return false;
                    }

                    return $disk->get($key) === 'production-preflight-ok';
                } finally {
                    if ($written || $disk->exists($key)) {
                        $disk->delete($key);
                    }
                }
            }, 'OBJECT_STORAGE_ROUND_TRIP_FAILED'),
        ];
    }

    /**
     * @return array{status:string,failure_code:?string}
     */
    private function safeLiveCheck(callable $callback, string $failureCode): array
    {
        try {
            return $this->check($callback() === true, $failureCode);
        } catch (Throwable) {
            return $this->check(false, $failureCode);
        }
    }

    /**
     * @return array{status:string,failure_code:?string}
     */
    private function check(bool $passes, string $failureCode): array
    {
        return [
            'status' => $passes ? 'pass' : 'fail',
            'failure_code' => $passes ? null : $failureCode,
        ];
    }

    /**
     * @param  array<string,array{status:string,failure_code:?string}>  $checks
     */
    private function failureCount(array $checks): int
    {
        $failures = 0;
        foreach ($checks as $check) {
            if ($check['status'] !== 'pass') {
                $failures++;
            }
        }

        return $failures;
    }

    private function isHttpsNonlocalUrl(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $parts = parse_url($value);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        return ! in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function secretMaterialLength(?string $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);

            return is_string($decoded) ? strlen($decoded) : 0;
        }

        return strlen($value);
    }

    private function productionMailerIsSafe(): bool
    {
        $configured = $this->stringConfig('password_recovery.mailer');
        $mailer = $configured ?? $this->stringConfig('mail.default');
        if ($mailer === null) {
            return false;
        }

        return $this->mailerIsSafe($mailer, []);
    }

    /**
     * @param  array<string,bool>  $visited
     */
    private function mailerIsSafe(string $mailer, array $visited): bool
    {
        if (isset($visited[$mailer])) {
            return false;
        }

        $visited[$mailer] = true;
        $definition = config('mail.mailers.'.$mailer);
        if (! is_array($definition)) {
            return false;
        }

        $transport = $definition['transport'] ?? null;
        if (! is_string($transport)) {
            return false;
        }

        if (in_array($transport, ['log', 'array'], true)) {
            return false;
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = $definition['mailers'] ?? null;
            if (! is_array($children) || $children === []) {
                return false;
            }

            foreach ($children as $child) {
                if (! is_string($child) || ! $this->mailerIsSafe($child, $visited)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function incidentContactRefsPresent(): bool
    {
        $required = config('production_preflight.required_incident_contact_refs', []);
        $refs = config('incident_response.contact_refs', []);

        if (! is_array($required) || ! is_array($refs) || $required === []) {
            return false;
        }

        foreach ($required as $key) {
            if (! is_string($key)) {
                return false;
            }

            $value = $refs[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    private function policyVersion(): string
    {
        return (string) config('production_preflight.policy_version', 'unknown');
    }

    /**
     * @return list<string>
     */
    private function externalEvidence(): array
    {
        $values = config('production_preflight.external_evidence_required', []);
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            $values,
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));
    }

    private function stringConfig(string $key): ?string
    {
        $value = config($key);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
