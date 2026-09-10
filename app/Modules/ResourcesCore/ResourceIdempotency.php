<?php

namespace App\Modules\ResourcesCore;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ResourceIdempotency
{
    /**
     * @param array<string,mixed> $payload
     * @param callable():array{status:int,resource_type:string,resource_id:string,body:array<string,mixed>} $callback
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

    /** @return mixed */
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
