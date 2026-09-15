<?php

namespace App\Support\Operations;

final class ProductionDeploymentPreflight
{
    /**
     * @return array{
     *   status:string,
     *   error_count:int,
     *   warning_count:int,
     *   checks:list<array{code:string,severity:string,status:string}>,
     *   external_evidence:array{
     *     target_database_PITR:string,
     *     target_object_restore:string,
     *     monitoring_and_alert_routes:string,
     *     paging_smoke:string,
     *     scheduler_delivery_smoke:string,
     *     target_release_smoke:string
     *   },
     *   deferred_boundaries:array{
     *     PKK_PWPW:string,
     *     payment_provider_webhook:string
     *   }
     * }
     */
    public function evaluate(): array
    {
        $checks = [];

        $checks[] = $this->check('app.environment.production', config('app.env') === 'production');
        $checks[] = $this->check('app.debug.disabled', config('app.debug') === false);
        $checks[] = $this->check('app.url.https_nonlocal', $this->httpsUrl(config('app.url'), true));
        $checks[] = $this->check('app.key.configured', $this->nonBlank(config('app.key')));

        $checks[] = $this->check('logging.channel.json_stderr', config('logging.default') === 'json_stderr');
        $this->check(
            $checks,
            'logging.level.not_debug',
            in_array(config('logging.channels.json_stderr.level'), ['info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true),
        );

        $checks[] = $this->check('database.driver.pgsql', config('database.default') === 'pgsql');
        $this->check(
            $checks,
            'database.pgsql.ssl_required',
            in_array(config('database.connections.pgsql.sslmode'), ['require', 'verify-ca', 'verify-full'], true),
        );

        $checks[] = $this->check('cache.driver.redis', config('cache.default') === 'redis');
        $checks[] = $this->check('session.driver.redis', config('session.driver') === 'redis');
        $checks[] = $this->check('session.cookie.secure', config('session.secure') === true);
        $checks[] = $this->check('session.cookie.http_only', config('session.http_only') === true);
        $checks[] = $this->check('session.serialization.json', config('session.serialization') === 'json');
        $checks[] = $this->check('queue.driver.redis', config('queue.default') === 'redis');
        $checks[] = $this->check('queue.failed_jobs.durable', config('queue.failed.driver') === 'database-uuids');

        $checks[] = $this->check('storage.default.s3', config('filesystems.default') === 's3');
        $checks[] = $this->check('storage.s3.bucket.configured', $this->nonBlank(config('filesystems.disks.s3.bucket')));
        $checks[] = $this->check('storage.s3.region.configured', $this->nonBlank(config('filesystems.disks.s3.region')));
        $checks[] = $this->check('storage.s3.endpoint.safe', $this->safeOptionalS3Endpoint(config('filesystems.disks.s3.endpoint')));
        $checks[] = $this->check('storage.s3.credentials.not_local_test_defaults', !$this->hasKnownLocalStorageCredential() === false);

        $mailer = config('mail.default');
        $this->check(
            $checks,
            'mail.transport.delivers',
            is_string($mailer) && in_array($mailer, ['log', 'array'], true) === false,
        );
        $checks[] = $this->check('mail.from.nonplaceholder', $this->safeMailFrom(config('mail.from.address')));

        $resetTemplate = config('password_recovery.reset_url_template');
        $this->check(
            $checks,
            'password_recovery.url.secure_template',
            $this->httpsUrl($resetTemplate, true)
                && is_string($resetTemplate)
                && str_contains($resetTemplate, '{token}'),
        );

        $appKey = config('app.key');
        $lookupKey = config('security.sensitive_identifiers.lookup_key');
        $checks[] = $this->check('sensitive_identifier.lookup_key.high_entropy', is_string($lookupKey) && strlen(trim($lookupKey)) >= 32);
        $this->check(
            $checks,
            'sensitive_identifier.lookup_key.independent',
            is_string($lookupKey)
                && is_string($appKey)
                && trim($lookupKey) !== ''
                && trim($appKey) !== ''
                && hash_equals(trim($appKey), trim($lookupKey)) === false,
        );

        $checks[] = $this->check('internal_exam.heartbeat.configured', $this->positiveIntegerLike(config('internal_exams.station_heartbeat_fresh_seconds')));
        $checks[] = $this->check('internal_exam.execution_ttl.configured', $this->positiveIntegerLike(config('internal_exams.execution_token_ttl_minutes')));
        $checks[] = $this->check('internal_exam.result_ttl.configured', $this->positiveIntegerLike(config('internal_exams.result_token_ttl_minutes')));
        $checks[] = $this->check('internal_exam.remote_ttl.configured', $this->positiveIntegerLike(config('internal_exams.remote_access_ttl_minutes')));
        $checks[] = $this->check('internal_exam.remote_url.https_nonlocal', $this->httpsUrl(config('internal_exams.remote_public_base_url'), true));

        $examTokenKey = config('internal_exams.token_verifier_key_v1');
        $examStationKey = config('internal_exams.station_verifier_key_v1');
        $checks[] = $this->check('internal_exam.token_key.high_entropy', is_string($examTokenKey) && strlen(trim($examTokenKey)) >= 32);
        $checks[] = $this->check('internal_exam.station_key.high_entropy', is_string($examStationKey) && strlen(trim($examStationKey)) >= 32);
        $this->check(
            $checks,
            'internal_exam.verifier_keys.distinct',
            is_string($examTokenKey)
                && is_string($examStationKey)
                && trim($examTokenKey) !== ''
                && trim($examStationKey) !== ''
                && hash_equals(trim($examTokenKey), trim($examStationKey)) === false,
        );
        $checks[] = $this->check('internal_exam.documents.s3', config('internal_exams.document_storage_disk') === 's3');

        $contactRefs = config('incident_response.contact_refs');
        $checks[] = $this->check('incident.contact_refs.configured', $this->allIncidentContactRefsConfigured($contactRefs));

        $checks[] = $this->check('retention.executor.disabled_at_baseline', config('retention.executor.enabled') === false);

        $errorCount = count(array_filter(
            $checks,
            static fn (array $check): bool => $check['severity'] === 'error' && $check['status'] === 'fail',
        ));
        $warningCount = count(array_filter(
            $checks,
            static fn (array $check): bool => $check['severity'] === 'warning' && $check['status'] === 'fail',
        ));

        return [
            'status' => $errorCount === 0 ? 'pass' : 'fail',
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'checks' => array_values($checks),
            'external_evidence' => [
                'target_database_PITR' => 'required_external',
                'target_object_restore' => 'required_external',
                'monitoring_and_alert_routes' => 'required_external',
                'paging_smoke' => 'required_external',
                'scheduler_delivery_smoke' => 'required_external',
                'target_release_smoke' => 'required_external',
            ],
            'deferred_boundaries' => [
                'PKK_PWPW' => 'frozen_not_required_for_core_launch',
                'payment_provider_webhook' => 'external_provider_boundary_not_required_by_this_preflight',
            ],
        ];
    }

    /** @return array{code:string,severity:string,status:string} */
    private function check(string $code, bool $passes, string $severity = 'error'): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'status' => $passes ? 'pass' : 'fail',
        ];
    }

    private function nonBlank(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function positiveIntegerLike(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0;
    }

    private function httpsUrl(mixed $value, bool $rejectLocal): bool
    {
        if (is_string($value) === false || trim((string) $value) === '') {
            return false;
        }

        $parts = parse_url($value);
        if (is_array($parts) === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        if ($rejectLocal && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        return true;
    }

    private function safeOptionalS3Endpoint(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return $this->httpsUrl($value, true);
    }

    private function hasKnownLocalStorageCredential(): bool
    {
        $values = [
            config('filesystems.disks.s3.key'),
            config('filesystems.disks.s3.secret'),
        ];

        foreach ($values as $value) {
            if (is_string($value) === false) {
                continue;
            }

            if (in_array(strtolower(trim($value)), ['test', 'minioadmin', 'osk_panel_local_only'], true)) {
                return true;
            }
        }

        return false;
    }

    private function safeMailFrom(mixed $value): bool
    {
        if (is_string($value) === false || filter_var((string) $value, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return str_ends_with(strtolower($value), '@example.com') === false;
    }

    private function allIncidentContactRefsConfigured(mixed $refs): bool
    {
        if (is_array($refs) === false) {
            return false;
        }

        foreach ([
            'primary_on_call',
            'technical_lead',
            'privacy_lead',
            'business_liaison',
            'communications_lead',
            'paging_channel',
        ] as $key) {
            if (array_key_exists($key, $refs) === false || $this->nonBlank($refs[$key]) === false) {
                return false;
            }
        }

        return true;
    }
}
