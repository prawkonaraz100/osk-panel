<?php

namespace App\Modules\ResourcesCore;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class ResourceIdempotency
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  callable():array{status:int,resource_type:string,resource_id:string,body:array<string,mixed>}  $callback
     * @return array{status:int,resource_type:string,resource_id:string,body:array<string,mixed>}
     */
    public function execute(string $organizationId, string $operationKey, string $idempotencyKey, array $payload, callable $callback): array
    {
        return DB::transaction(function () use ($organizationId, $operationKey, $idempotencyKey, $payload, $callback): array {
            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->firstOrFail();

            $requestHash = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
            $existing = DB::table('idempotency_records')
                ->where('organization_id', $organizationId)
                ->where('operation_key', $operationKey)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                    throw new ResourceDomainException(
                        'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD',
                        409,
                        'Idempotency key was already used with a different payload.',
                    );
                }
                if ($existing->status !== 'completed' || $existing->safe_response_snapshot === null) {
                    throw ResourceDomainException::conflict('Idempotent operation is not in a replayable completed state.');
                }

                $body = json_decode((string) $existing->safe_response_snapshot, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($body)) {
                    throw ResourceDomainException::conflict('Stored idempotency response is invalid.');
                }

                return [
                    'status' => (int) $existing->response_status,
                    'resource_type' => (string) $existing->result_resource_type,
                    'resource_id' => (string) $existing->result_resource_id,
                    'body' => $body,
                ];
            }

            $id = (string) Str::uuid7();
            DB::table('idempotency_records')->insert([
                'id' => $id,
                'organization_id' => $organizationId,
                'operation_key' => $operationKey,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'created_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);

            $result = $callback();

            DB::table('idempotency_records')->where('id', $id)->update([
                'status' => 'completed',
                'result_resource_type' => $result['resource_type'],
                'result_resource_id' => $result['resource_id'],
                'response_status' => $result['status'],
                'safe_response_snapshot' => json_encode($result['body'], JSON_THROW_ON_ERROR),
                'completed_at' => now(),
            ]);

            return $result;
        });
    }

    /**
     * Execute an idempotent command whose first response may contain a non-replayable
     * in-memory secret. Only replay_body is persisted. A retry therefore never
     * recovers or replays the original secret.
     *
     * @param  array<string,mixed>  $payload
     * @param  callable():array{status:int,resource_type:string,resource_id:string,body:array<string,mixed>,replay_body:array<string,mixed>}  $callback
     * @return array{status:int,resource_type:string,resource_id:string,body:array<string,mixed>}
     */
    public function executeWithSanitizedReplay(
        string $organizationId,
        string $operationKey,
        string $idempotencyKey,
        array $payload,
        callable $callback,
    ): array {
        return DB::transaction(function () use ($organizationId, $operationKey, $idempotencyKey, $payload, $callback): array {
            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->firstOrFail();

            $requestHash = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
            $existing = DB::table('idempotency_records')
                ->where('organization_id', $organizationId)
                ->where('operation_key', $operationKey)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                    throw new ResourceDomainException(
                        'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD',
                        409,
                        'Idempotency key was already used with a different payload.',
                    );
                }
                if ($existing->status !== 'completed' || $existing->safe_response_snapshot === null) {
                    throw ResourceDomainException::conflict('Idempotent operation is not in a replayable completed state.');
                }

                $body = json_decode((string) $existing->safe_response_snapshot, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($body)) {
                    throw ResourceDomainException::conflict('Stored idempotency response is invalid.');
                }

                return [
                    'status' => (int) $existing->response_status,
                    'resource_type' => (string) $existing->result_resource_type,
                    'resource_id' => (string) $existing->result_resource_id,
                    'body' => $body,
                ];
            }

            $id = (string) Str::uuid7();
            DB::table('idempotency_records')->insert([
                'id' => $id,
                'organization_id' => $organizationId,
                'operation_key' => $operationKey,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'created_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);

            $result = $callback();

            DB::table('idempotency_records')->where('id', $id)->update([
                'status' => 'completed',
                'result_resource_type' => $result['resource_type'],
                'result_resource_id' => $result['resource_id'],
                'response_status' => $result['status'],
                'safe_response_snapshot' => json_encode($result['replay_body'], JSON_THROW_ON_ERROR),
                'completed_at' => now(),
            ]);

            return [
                'status' => $result['status'],
                'resource_type' => $result['resource_type'],
                'resource_id' => $result['resource_id'],
                'body' => $result['body'],
            ];
        });
    }

    /**
     * Prepare a secret-bearing external delivery without persisting the secret.
     *
     * A completed record replays only the sanitized snapshot. A delivery_failed
     * record may be prepared again with the same idempotency key so the caller
     * can rotate a fresh one-time secret before retrying the external delivery.
     * A delivery_prepared record is deliberately not replayable: another request
     * must not race the in-flight external delivery.
     *
     * @param  array<string,mixed>  $payload
     * @param  callable():array{status:int,resource_type:string,resource_id:string,body:array<string,mixed>,replay_body:array<string,mixed>}  $callback
     * @return array{replayed:bool,status:int,resource_type:string,resource_id:string,body:array<string,mixed>}
     */
    /**
     * Prepare a secret-bearing external delivery without persisting the secret.
     *
     * A completed record replays only the sanitized snapshot. A delivery_failed
     * record may be prepared again with the same idempotency key. A
     * delivery_prepared record holds only a safe delivery reference and prepared
     * timestamp; after the configured lease it can be recovered only if the
     * caller proves that exact delivery is still current.
     *
     * @param  array<string,mixed>  $payload
     * @param  callable():array{status:int,resource_type:string,resource_id:string,body:array<string,mixed>,replay_body:array<string,mixed>,delivery_reference:string}  $callback
     * @param  callable(string):bool  $recoverExpiredPrepared
     * @return array{replayed:bool,status:int,resource_type:string,resource_id:string,body:array<string,mixed>}
     */
    public function prepareRetryableSecretDelivery(
        string $organizationId,
        string $operationKey,
        string $idempotencyKey,
        array $payload,
        callable $callback,
        callable $recoverExpiredPrepared,
        int $preparedLeaseSeconds,
    ): array {
        if ($preparedLeaseSeconds < 1) {
            throw new LogicException('Prepared secret delivery lease must be positive.');
        }

        $outcome = DB::transaction(function () use (
            $organizationId,
            $operationKey,
            $idempotencyKey,
            $payload,
            $callback,
            $recoverExpiredPrepared,
            $preparedLeaseSeconds,
        ): array {
            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->firstOrFail();

            $requestHash = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
            $existing = DB::table('idempotency_records')
                ->where('organization_id', $organizationId)
                ->where('operation_key', $operationKey)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                    throw new ResourceDomainException(
                        'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD',
                        409,
                        'Idempotency key was already used with a different payload.',
                    );
                }

                if ($existing->status === 'completed' && $existing->safe_response_snapshot !== null) {
                    $body = json_decode((string) $existing->safe_response_snapshot, true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($body)) {
                        throw ResourceDomainException::conflict('Stored idempotency response is invalid.');
                    }

                    return [
                        'kind' => 'result',
                        'replayed' => true,
                        'status' => (int) $existing->response_status,
                        'resource_type' => (string) $existing->result_resource_type,
                        'resource_id' => (string) $existing->result_resource_id,
                        'body' => $body,
                    ];
                }

                if ($existing->status === 'delivery_prepared') {
                    if ($existing->safe_response_snapshot === null) {
                        throw ResourceDomainException::conflict('Prepared secret delivery snapshot is missing.');
                    }

                    $prepared = $this->decodePreparedDeliverySnapshot((string) $existing->safe_response_snapshot);
                    $preparedAt = CarbonImmutable::parse($prepared['prepared_at']);
                    if (CarbonImmutable::now()->lt($preparedAt->addSeconds($preparedLeaseSeconds))) {
                        throw ResourceDomainException::conflict('Secret delivery is already in progress.');
                    }

                    if (! $recoverExpiredPrepared($prepared['delivery_reference'])) {
                        DB::table('idempotency_records')->where('id', $existing->id)->update([
                            'status' => 'delivery_superseded',
                            'completed_at' => now(),
                        ]);

                        return ['kind' => 'superseded'];
                    }

                    DB::table('idempotency_records')->where('id', $existing->id)->update([
                        'status' => 'delivery_failed',
                        'completed_at' => null,
                    ]);
                } elseif ($existing->status !== 'delivery_failed') {
                    throw ResourceDomainException::conflict('Secret delivery is already in progress or is not retryable.');
                }

                $recordId = (string) $existing->id;
            } else {
                $recordId = (string) Str::uuid7();
                DB::table('idempotency_records')->insert([
                    'id' => $recordId,
                    'organization_id' => $organizationId,
                    'operation_key' => $operationKey,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'status' => 'processing',
                    'created_at' => now(),
                    'expires_at' => now()->addDays(7),
                ]);
            }

            $result = $callback();
            $deliveryReference = trim($result['delivery_reference']);
            if ($deliveryReference === '') {
                throw new LogicException('Prepared secret delivery reference must be non-empty.');
            }

            DB::table('idempotency_records')->where('id', $recordId)->update([
                'status' => 'delivery_prepared',
                'result_resource_type' => $result['resource_type'],
                'result_resource_id' => $result['resource_id'],
                'response_status' => $result['status'],
                'safe_response_snapshot' => $this->encodePreparedDeliverySnapshot(
                    $result['replay_body'],
                    $deliveryReference,
                ),
                'completed_at' => null,
            ]);

            return [
                'kind' => 'result',
                'replayed' => false,
                'status' => $result['status'],
                'resource_type' => $result['resource_type'],
                'resource_id' => $result['resource_id'],
                'body' => $result['body'],
            ];
        });

        if (($outcome['kind'] ?? null) === 'superseded') {
            throw ResourceDomainException::conflict('Prepared secret delivery was superseded by a newer delivery command.');
        }

        /** @var array{replayed:bool,status:int,resource_type:string,resource_id:string,body:array<string,mixed>} $outcome */
        return $outcome;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  callable():array<string,mixed>  $callback
     * @return array<string,mixed>
     */
    public function completeRetryableSecretDelivery(
        string $organizationId,
        string $operationKey,
        string $idempotencyKey,
        array $payload,
        callable $callback,
    ): array {
        return DB::transaction(function () use (
            $organizationId,
            $operationKey,
            $idempotencyKey,
            $payload,
            $callback,
        ): array {
            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->firstOrFail();

            $requestHash = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
            $existing = DB::table('idempotency_records')
                ->where('organization_id', $organizationId)
                ->where('operation_key', $operationKey)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing === null || ! hash_equals((string) $existing->request_hash, $requestHash)) {
                throw ResourceDomainException::conflict('Prepared secret delivery idempotency record is missing or mismatched.');
            }

            if ($existing->status === 'completed' && $existing->safe_response_snapshot !== null) {
                $body = json_decode((string) $existing->safe_response_snapshot, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($body)) {
                    throw ResourceDomainException::conflict('Stored idempotency response is invalid.');
                }

                return $body;
            }
            if ($existing->status !== 'delivery_prepared') {
                throw ResourceDomainException::conflict('Secret delivery is not in a completable prepared state.');
            }

            $replayBody = $callback();

            DB::table('idempotency_records')->where('id', $existing->id)->update([
                'status' => 'completed',
                'safe_response_snapshot' => json_encode($replayBody, JSON_THROW_ON_ERROR),
                'completed_at' => now(),
            ]);

            return $replayBody;
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  callable():void  $callback
     */
    public function failRetryableSecretDelivery(
        string $organizationId,
        string $operationKey,
        string $idempotencyKey,
        array $payload,
        callable $callback,
    ): void {
        DB::transaction(function () use ($organizationId, $operationKey, $idempotencyKey, $payload, $callback): void {
            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->firstOrFail();

            $requestHash = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
            $existing = DB::table('idempotency_records')
                ->where('organization_id', $organizationId)
                ->where('operation_key', $operationKey)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing === null || ! hash_equals((string) $existing->request_hash, $requestHash)) {
                throw ResourceDomainException::conflict('Prepared secret delivery idempotency record is missing or mismatched.');
            }

            if ($existing->status === 'delivery_failed') {
                return;
            }
            if ($existing->status !== 'delivery_prepared') {
                throw ResourceDomainException::conflict('Secret delivery is not in a fail-able prepared state.');
            }

            $callback();

            DB::table('idempotency_records')->where('id', $existing->id)->update([
                'status' => 'delivery_failed',
                'completed_at' => null,
            ]);
        });
    }

    /**
     * @param  array<string,mixed>  $replayBody
     */
    private function encodePreparedDeliverySnapshot(array $replayBody, string $deliveryReference): string
    {
        return json_encode([
            '_delivery' => [
                'version' => 1,
                'reference' => $deliveryReference,
                'prepared_at' => CarbonImmutable::now()->toIso8601String(),
            ],
            'replay_body' => $replayBody,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{delivery_reference:string,prepared_at:string,replay_body:array<string,mixed>}
     */
    private function decodePreparedDeliverySnapshot(string $raw): array
    {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)
            || ! is_array($decoded['_delivery'] ?? null)
            || ($decoded['_delivery']['version'] ?? null) !== 1
            || ! is_string($decoded['_delivery']['reference'] ?? null)
            || trim((string) $decoded['_delivery']['reference']) === ''
            || ! is_string($decoded['_delivery']['prepared_at'] ?? null)
            || ! is_array($decoded['replay_body'] ?? null)) {
            throw ResourceDomainException::conflict('Prepared secret delivery snapshot is invalid.');
        }

        return [
            'delivery_reference' => (string) $decoded['_delivery']['reference'],
            'prepared_at' => (string) $decoded['_delivery']['prepared_at'],
            'replay_body' => $decoded['replay_body'],
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
