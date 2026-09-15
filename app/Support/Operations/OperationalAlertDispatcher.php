<?php

namespace App\Support\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class OperationalAlertDispatcher
{
    /**
     * @param array<string,mixed> $context
     * @return array{status:string,event_id:string,event_code:string,severity:string,runbook:string}
     */
    public function dispatch(string $eventCode, array $context = []): array
    {
        $definition = $this->definition($eventCode);
        $eventId = (string) Str::uuid7();

        $result = [
            'status' => 'disabled',
            'event_id' => $eventId,
            'event_code' => $eventCode,
            'severity' => $definition['severity'],
            'runbook' => $definition['runbook'],
        ];

        if (! (bool) config('operational_alerting.enabled', false)) {
            Log::warning('operational_alert_disabled', [
                'event_id' => $eventId,
                'event_code' => $eventCode,
                'severity' => $definition['severity'],
                'runbook' => $definition['runbook'],
            ]);

            return $result;
        }

        $url = config('operational_alerting.webhook_url');
        $secret = config('operational_alerting.webhook_secret');
        $timeout = config('operational_alerting.timeout_seconds', 5);

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new LogicException('Operational alert endpoint must be an HTTPS URL.');
        }
        if (! is_string($secret) || trim($secret) === '') {
            throw new LogicException('Operational alert webhook secret is unavailable.');
        }
        if (! is_int($timeout) || $timeout < 1 || $timeout > 15) {
            throw new LogicException('Operational alert timeout must be between 1 and 15 seconds.');
        }

        $payload = [
            'schema_version' => '1',
            'event_id' => $eventId,
            'emitted_at' => CarbonImmutable::now()->toIso8601String(),
            'service' => 'osk-panel',
            'environment' => (string) config('app.env', 'unknown'),
            'event_code' => $eventCode,
            'severity' => $definition['severity'],
            'runbook' => $definition['runbook'],
            'summary' => $definition['summary'],
            'context' => $this->safeContext($definition['context_keys'], $context),
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $secret);

        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'X-OSK-Alert-Signature' => 'sha256='.$signature,
                    'X-OSK-Alert-Event-Id' => $eventId,
                ])
                ->withBody($body, 'application/json')
                ->post($url);

            if (! $response->successful()) {
                Log::error('operational_alert_delivery_failed', [
                    'event_id' => $eventId,
                    'event_code' => $eventCode,
                    'severity' => $definition['severity'],
                    'runbook' => $definition['runbook'],
                    'http_status' => $response->status(),
                ]);

                return [...$result, 'status' => 'failed'];
            }
        } catch (ConnectionException $exception) {
            Log::error('operational_alert_delivery_failed', [
                'event_id' => $eventId,
                'event_code' => $eventCode,
                'severity' => $definition['severity'],
                'runbook' => $definition['runbook'],
                'exception' => $exception::class,
            ]);

            return [...$result, 'status' => 'failed'];
        } catch (Throwable $exception) {
            Log::error('operational_alert_delivery_failed', [
                'event_id' => $eventId,
                'event_code' => $eventCode,
                'severity' => $definition['severity'],
                'runbook' => $definition['runbook'],
                'exception' => $exception::class,
            ]);

            return [...$result, 'status' => 'failed'];
        }

        Log::info('operational_alert_delivered', [
            'event_id' => $eventId,
            'event_code' => $eventCode,
            'severity' => $definition['severity'],
            'runbook' => $definition['runbook'],
        ]);

        return [...$result, 'status' => 'delivered'];
    }

    /**
     * @return array{severity:string,runbook:string,summary:string,context_keys:list<string>}
     */
    private function definition(string $eventCode): array
    {
        $definition = config('operational_alerting.events.'.$eventCode);
        if (! is_array($definition)) {
            throw new LogicException("Unknown operational alert event: {$eventCode}");
        }

        $severity = $definition['severity'] ?? null;
        $runbook = $definition['runbook'] ?? null;
        $summary = $definition['summary'] ?? null;
        $contextKeys = $definition['context_keys'] ?? null;

        if (! is_string($severity)
            || ! is_string($runbook)
            || ! is_string($summary)
            || ! is_array($contextKeys)) {
            throw new LogicException("Operational alert definition is invalid for {$eventCode}.");
        }

        $keys = [];
        foreach ($contextKeys as $key) {
            if (! is_string($key) || $key === '') {
                throw new LogicException("Operational alert context key is invalid for {$eventCode}.");
            }
            $keys[] = $key;
        }

        return [
            'severity' => $severity,
            'runbook' => $runbook,
            'summary' => $summary,
            'context_keys' => $keys,
        ];
    }

    /**
     * @param list<string> $allowedKeys
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function safeContext(array $allowedKeys, array $context): array
    {
        $safe = [];

        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }

            $value = $context[$key];

            if (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, 200);

                continue;
            }

            if (is_int($value) || is_bool($value) || $value === null) {
                $safe[$key] = $value;

                continue;
            }

            if ($key === 'findings_by_scope' && is_array($value)) {
                $counts = [];
                foreach ($value as $scope => $count) {
                    if (is_string($scope) && is_int($count) && $count >= 0) {
                        $counts[$scope] = $count;
                    }
                }
                ksort($counts);
                $safe[$key] = $counts;
            }
        }

        ksort($safe);

        return $safe;
    }
}
