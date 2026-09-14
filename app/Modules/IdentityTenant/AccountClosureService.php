<?php

namespace App\Modules\IdentityTenant;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\ResourcesCore\ResourceDomainException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountClosureService
{
    public function __construct(
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /**
     * @return array{id:string,status:string,requested_at:string}
     */
    public function request(
        string $sessionId,
        ?string $reason,
        string $idempotencyKey,
        string $requestId,
    ): array {
        $reason = $this->normalizeReason($reason);

        return DB::transaction(function () use ($sessionId, $reason, $idempotencyKey, $requestId): array {
            $session = DB::table('auth_sessions')
                ->where('id', $sessionId)
                ->lockForUpdate()
                ->first();

            if ($session === null || $session->revoked_at !== null) {
                throw new AuthenticationException('Authenticated application session required.');
            }

            $user = DB::table('users')
                ->where('id', $session->user_id)
                ->lockForUpdate()
                ->first();

            if ($user === null || (string) $user->status !== 'active') {
                throw new AuthenticationException('Authenticated application session required.');
            }

            $userId = (string) $user->id;
            $payload = ['reason' => $reason];
            $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

            $existingIdempotency = DB::table('idempotency_records')
                ->whereNull('organization_id')
                ->where('operation_key', 'auth.account_closure_request')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingIdempotency !== null) {
                return $this->replayExistingIdempotency(
                    (string) $existingIdempotency->request_hash,
                    (string) $existingIdempotency->status,
                    $existingIdempotency->safe_response_snapshot,
                    $requestHash,
                );
            }

            $idempotencyRecordId = (string) Str::uuid7();
            DB::table('idempotency_records')->insert([
                'id' => $idempotencyRecordId,
                'organization_id' => null,
                'operation_key' => 'auth.account_closure_request',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'created_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);

            $pending = DB::table('account_closure_requests')
                ->where('user_id', $userId)
                ->whereNull('organization_id')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($pending !== null) {
                $result = $this->present(
                    $pending->id,
                    $pending->status,
                    $pending->requested_at,
                );
                $this->completeIdempotency($idempotencyRecordId, $result);

                return $result;
            }

            $closureId = (string) Str::uuid7();
            $requestedAt = now();

            DB::table('account_closure_requests')->insert([
                'id' => $closureId,
                'user_id' => $userId,
                'organization_id' => null,
                'requested_at' => $requestedAt,
                'reason' => $reason,
                'status' => 'pending',
                'resolved_at' => null,
                'resolved_by_user_id' => null,
                'resolution_note' => null,
                'request_id' => $requestId,
            ]);

            $this->auditOutbox->recordGlobalUserEvent(
                $userId,
                'auth.account_closure.requested',
                'account_closure_request',
                $closureId,
                $requestId,
                ['state' => 'absent'],
                ['state' => 'pending'],
            );

            $result = [
                'id' => $closureId,
                'status' => 'pending',
                'requested_at' => (string) $requestedAt,
            ];
            $this->completeIdempotency($idempotencyRecordId, $result);

            return $result;
        });
    }

    /**
     * @return array{id:string,status:string,requested_at:string}
     */
    private function replayExistingIdempotency(
        string $storedRequestHash,
        string $storedStatus,
        mixed $storedResponseSnapshot,
        string $requestHash,
    ): array {
        if (! hash_equals($storedRequestHash, $requestHash)) {
            throw new ResourceDomainException(
                'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD',
                409,
                'Idempotency key was already used with a different payload.',
            );
        }

        if ($storedStatus !== 'completed' || $storedResponseSnapshot === null) {
            throw ResourceDomainException::conflict('Idempotent operation is not in a replayable completed state.');
        }

        $decoded = json_decode((string) $storedResponseSnapshot, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)
            || ! is_string($decoded['id'] ?? null)
            || ! is_string($decoded['status'] ?? null)
            || ! is_string($decoded['requested_at'] ?? null)) {
            throw ResourceDomainException::conflict('Stored account closure response is invalid.');
        }

        return [
            'id' => $decoded['id'],
            'status' => $decoded['status'],
            'requested_at' => $decoded['requested_at'],
        ];
    }

    /**
     * @param  array{id:string,status:string,requested_at:string}  $result
     */
    private function completeIdempotency(string $recordId, array $result): void
    {
        DB::table('idempotency_records')->where('id', $recordId)->update([
            'status' => 'completed',
            'result_resource_type' => 'account_closure_request',
            'result_resource_id' => $result['id'],
            'response_status' => 202,
            'safe_response_snapshot' => json_encode($result, JSON_THROW_ON_ERROR),
            'completed_at' => now(),
        ]);
    }

    /**
     * @return array{id:string,status:string,requested_at:string}
     */
    private function present(mixed $id, mixed $status, mixed $requestedAt): array
    {
        return [
            'id' => (string) $id,
            'status' => (string) $status,
            'requested_at' => (string) $requestedAt,
        ];
    }

    private function normalizeReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $reason = trim($reason);
        if ($reason === '') {
            return null;
        }
        if (mb_strlen($reason) > 1000) {
            throw ResourceDomainException::rule('Account closure reason is too long.');
        }

        return $reason;
    }
}
