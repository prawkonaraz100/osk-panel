<?php

namespace App\Support\Operations;

final class ProductionEnvironmentPreflight
{
    /**
     * @return array{
     *   policy_version:string,
     *   configuration_status:string,
     *   production_ready:bool,
     *   go_live_status:string,
     *   checks:list<array{id:string,status:string,message:string}>,
     *   external_evidence_required:list<string>,
     *   deferred_boundaries:array{PKK_PWPW:string,payment_provider_specific_webhook:string}
     * }
     */
    public function evaluate(): array
    {
        $checks = [
            $this->equals('app_environment', config('app.env'), 'production', 'APP_ENV must be production.'),
            $this->boolean('app_debug_disabled', config('app.debug'), false, 'APP_DEBUG must be false.'),
            $this->httpsUrl('app_url_https', config('app.url'), 'APP_URL must be an HTTPS URL.'),
            $this->nonblank('app_key_present', config('app.key'), 'APP_KEY must be injected.'),
            $this->equals('structured_json_logging', config('logging.default'), 'json_stderr', 'LOG_CHANNEL must be json_stderr.'),
            $this->equals('postgresql_authority', config('database.default'), 'pgsql', 'DB_CONNECTION must be pgsql.'),
            $this->databaseConfigured(),
            $this->equals('redis_cache', config('cache.default'), 'redis', 'CACHE_STORE must be redis.'),
            $this->equals('redis_session', config('session.driver'), 'redis', 'SESSION_DRIVER must be redis.'),
            $this->boolean('secure_session_cookie', config('session.secure'), true, 'SESSION_SECURE_COOKIE must be true.'),
            $this->equals('redis_queue', config('queue.default'), 'redis', 'QUEUE_CONNECTION must be redis.'),
            $this->equals('s3_default_storage', config('filesystems.default'), 's3', 'FILESYSTEM_DISK must be s3.'),
            $this->equals('s3_upload_storage', config('uploads.disk'), 's3', 'UPLOAD_STORAGE_DISK must be s3.'),
            $this->s3Configured(),
            $this->productionMailer(),
            $this->mailFromConfigured(),
            $this->passwordResetConfigured(),
            $this->sensitiveLookupKeyConfigured(),
            $this->incidentContactRefsConfigured(),
            $this->boolean(
                'operational_alerting_enabled',
                config('operational_alerting.enabled'),
                true,
                'OPS_ALERTING_ENABLED must be true in the production target.',
            ),
            $this->httpsUrl(
                'operational_alert_endpoint_https',
                config('operational_alerting.webhook_url'),
                'OPS_ALERT_WEBHOOK_URL must be an HTTPS URL.',
            ),
            $this->nonblank(
                'operational_alert_secret_present',
                config('operational_alerting.webhook_secret'),
                'OPS_ALERT_WEBHOOK_SECRET must be injected.',
            ),
            $this->boolean(
                'retention_executor_disabled_at_baseline',
                config('retention.executor.enabled'),
                false,
                'RETENTION_EXECUTOR_ENABLED must remain false for the normal production baseline.',
            ),
        ];

        $failed = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] === 'FAIL',
        ));

        $external = config('production_preflight.external_evidence_required', []);
        if (! is_array($external)) {
            $external = [];
        }
        $external = array_values(array_filter(
            $external,
            static fn (mixed $item): bool => is_string($item) && trim($item) !== '',
        ));

        $boundaries = config('production_preflight.deferred_boundaries', []);
        $pkk = is_array($boundaries) && is_string($boundaries['PKK_PWPW'] ?? null)
            ? $boundaries['PKK_PWPW']
            : 'FROZEN_UNTIL_EXPLICIT_UNFREEZE';
        $payment = is_array($boundaries) && is_string($boundaries['payment_provider_specific_webhook'] ?? null)
            ? $boundaries['payment_provider_specific_webhook']
            : 'EXTERNAL_PROVIDER_BOUNDARY';

        return [
            'policy_version' => (string) config('production_preflight.policy_version', 'unknown'),
            'configuration_status' => $failed === [] ? 'PASS' : 'FAIL',
            'production_ready' => false,
            'go_live_status' => 'BLOCKED_EXTERNAL_EVIDENCE',
            'checks' => $checks,
            'external_evidence_required' => $external,
            'deferred_boundaries' => [
                'PKK_PWPW' => $pkk,
                'payment_provider_specific_webhook' => $payment,
            ],
        ];
    }

    /** @return array{id:string,status:string,message:string} */
    private function equals(string $id, mixed $actual, string $expected, string $message): array
    {
        return $this->check($id, is_string($actual) && $actual === $expected, $message);
    }

    /** @return array{id:string,status:string,message:string} */
    private function boolean(string $id, mixed $actual, bool $expected, string $message): array
    {
        $normalized = is_bool($actual)
            ? $actual
            : filter_var($actual, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $this->check($id, $normalized === $expected, $message);
    }

    /** @return array{id:string,status:string,message:string} */
    private function nonblank(string $id, mixed $actual, string $message): array
    {
        return $this->check($id, is_string($actual) && trim($actual) !== '', $message);
    }

    /** @return array{id:string,status:string,message:string} */
    private function httpsUrl(string $id, mixed $actual, string $message): array
    {
        if (! is_string($actual) || trim($actual) === '') {
            return $this->check($id, false, $message);
        }

        $parts = parse_url($actual);
        $valid = is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && trim((string) $parts['host']) !== '';

        return $this->check($id, $valid, $message);
    }

    /** @return array{id:string,status:string,message:string} */
    private function databaseConfigured(): array
    {
        $url = config('database.connections.pgsql.url');
        $database = config('database.connections.pgsql.database');
        $username = config('database.connections.pgsql.username');

        $configured = (is_string($url) && trim($url) !== '')
            || (
                is_string($database)
                && trim($database) !== ''
                && is_string($username)
                && trim($username) !== ''
            );

        return $this->check(
            'postgresql_target_configured',
            $configured,
            'PostgreSQL target URL or database/username must be configured.',
        );
    }

    /** @return array{id:string,status:string,message:string} */
    private function s3Configured(): array
    {
        $bucket = config('filesystems.disks.s3.bucket');
        $region = config('filesystems.disks.s3.region');
        $endpoint = config('filesystems.disks.s3.endpoint');

        $endpointSafe = $endpoint === null
            || $endpoint === ''
            || (is_string($endpoint) && str_starts_with(strtolower($endpoint), 'https://'));

        $configured = is_string($bucket)
            && trim($bucket) !== ''
            && is_string($region)
            && trim($region) !== ''
            && $endpointSafe;

        return $this->check(
            's3_target_configured',
            $configured,
            'S3 bucket/region must be configured and any explicit endpoint must use HTTPS.',
        );
    }

    /** @return array{id:string,status:string,message:string} */
    private function productionMailer(): array
    {
        $passwordMailer = config('password_recovery.mailer');
        $defaultMailer = config('mail.default');
        $effective = is_string($passwordMailer) && trim($passwordMailer) !== ''
            ? $passwordMailer
            : $defaultMailer;

        $valid = is_string($effective)
            && trim($effective) !== ''
            && $this->mailerIsProductionSafe($effective);

        return $this->check(
            'production_mail_transport',
            $valid,
            'Effective password-recovery mail transport must not be log or array.',
        );
    }

    /**
     * @param  array<string,bool>  $visited
     */
    private function mailerIsProductionSafe(string $mailer, array $visited = []): bool
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

        if (in_array($transport, [
            'smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'sendmail', 'mailgun',
        ], true)) {
            return true;
        }

        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return false;
        }

        $children = $definition['mailers'] ?? null;
        if (! is_array($children) || $children === []) {
            return false;
        }

        foreach ($children as $child) {
            if (! is_string($child) || ! $this->mailerIsProductionSafe($child, $visited)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{id:string,status:string,message:string} */
    private function mailFromConfigured(): array
    {
        $address = config('mail.from.address');
        $valid = is_string($address)
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false
            && ! str_ends_with(strtolower($address), '@example.com');

        return $this->check(
            'mail_from_address',
            $valid,
            'MAIL_FROM_ADDRESS must be a non-placeholder valid email address.',
        );
    }

    /** @return array{id:string,status:string,message:string} */
    private function passwordResetConfigured(): array
    {
        $template = config('password_recovery.reset_url_template');
        $valid = is_string($template)
            && str_contains($template, '{token}')
            && str_starts_with(strtolower($template), 'https://');

        return $this->check(
            'password_reset_https_template',
            $valid,
            'Password reset URL template must use HTTPS and contain the literal {token} placeholder.',
        );
    }

    /** @return array{id:string,status:string,message:string} */
    private function sensitiveLookupKeyConfigured(): array
    {
        $key = config('security.sensitive_identifiers.lookup_key');
        $valid = is_string($key) && strlen(trim($key)) >= 32;

        return $this->check(
            'sensitive_identifier_lookup_key',
            $valid,
            'Sensitive identifier lookup key must be injected with at least 32 characters.',
        );
    }

    /** @return array{id:string,status:string,message:string} */
    private function incidentContactRefsConfigured(): array
    {
        $refs = config('incident_response.contact_refs');
        $required = [
            'primary_on_call',
            'technical_lead',
            'privacy_lead',
            'business_liaison',
            'communications_lead',
            'paging_channel',
        ];

        if (! is_array($refs)) {
            return $this->check(
                'incident_contact_references',
                false,
                'All incident contact references must be injected.',
            );
        }

        foreach ($required as $key) {
            $value = $refs[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                return $this->check(
                    'incident_contact_references',
                    false,
                    'All incident contact references must be injected.',
                );
            }
        }

        return $this->check(
            'incident_contact_references',
            true,
            'All incident contact references must be injected.',
        );
    }

    /** @return array{id:string,status:string,message:string} */
    private function check(string $id, bool $passes, string $message): array
    {
        return [
            'id' => $id,
            'status' => $passes ? 'PASS' : 'FAIL',
            'message' => $message,
        ];
    }
}
