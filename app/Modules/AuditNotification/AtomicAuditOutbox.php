<?php

namespace App\Modules\AuditNotification;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class AtomicAuditOutbox
{
    /** @var array<string, list<string>> */
    private const ALLOWED_KEYS = [
        'foundation.authorization.v1' => [
            'membership_id', 'permission', 'granted', 'scopes', 'version', 'authorization_version',
            'from_owner_membership_id', 'to_owner_membership_id',
        ],
        'foundation.settings.v1' => ['fields', 'version'],
        'resources.lifecycle.v1' => ['fields', 'state', 'document_type', 'membership_id', 'archived', 'has_login_account'],
    ];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return array{audit_log_id:string,domain_event_id:string,outbox_message_id:string}
     */
    public function recordOrganizationEvent(
        string $organizationId,
        string $actorMembershipId,
        string $actorUserId,
        string $action,
        string $entityType,
        string $entityId,
        string $requestId,
        ?array $before,
        ?array $after,
        ?string $reason = null,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Audit/outbox must be written inside the business transaction.');
        }

        $policy = DB::table('audit_action_policy_currents as c')
            ->join('audit_action_policy_revisions as r', function ($join): void {
                $join->on('r.action', '=', 'c.action')
                    ->on('r.policy_version', '=', 'c.policy_version');
            })
            ->where('c.action', $action)
            ->select([
                'r.policy_version',
                'r.payload_validator_code',
                'r.before_payload_requirement',
                'r.after_payload_requirement',
                'r.reason_requirement',
            ])
            ->first();

        if ($policy === null) {
            throw new LogicException("No current audit policy for {$action}.");
        }

        $this->validatePayload((string) $policy->payload_validator_code, $before);
        $this->validatePayload((string) $policy->payload_validator_code, $after);

        if ($policy->before_payload_requirement === 'required' && $before === null) {
            throw new LogicException('Audit before payload is required.');
        }
        if ($policy->after_payload_requirement === 'required' && $after === null) {
            throw new LogicException('Audit after payload is required.');
        }
        if ($policy->reason_requirement === 'required' && ($reason === null || trim($reason) === '')) {
            throw new LogicException('Audit reason is required.');
        }

        $now = now();
        $auditId = (string) Str::uuid7();
        $eventId = (string) Str::uuid7();
        $outboxId = (string) Str::uuid7();

        DB::table('audit_logs')->insert([
            'id' => $auditId,
            'audit_scope' => 'organization',
            'organization_id' => $organizationId,
            'actor_kind' => 'organization_membership',
            'actor_organization_membership_id' => $actorMembershipId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'audit_policy_version' => (int) $policy->policy_version,
            'entity_reference_mode' => 'snapshot_only',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_redacted_json' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_redacted_json' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'request_id' => $requestId,
            'created_at' => $now,
        ]);

        DB::table('domain_events')->insert([
            'id' => $eventId,
            'event_scope' => 'organization',
            'organization_id' => $organizationId,
            'event_type' => $action,
            'aggregate_reference_mode' => 'snapshot_only',
            'aggregate_type' => $entityType,
            'aggregate_id' => $entityId,
            'request_id' => $requestId,
            'causation_event_id' => null,
            'required_audit_log_id' => $auditId,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);

        DB::table('outbox_messages')->insert([
            'id' => $outboxId,
            'domain_event_id' => $eventId,
            'event_scope' => 'organization',
            'organization_id' => $organizationId,
            'aggregate_type' => $entityType,
            'aggregate_id' => $entityId,
            'event_type' => $action,
            'payload' => json_encode([
                'domain_event_id' => $eventId,
                'event_type' => $action,
                'organization_id' => $organizationId,
            ], JSON_THROW_ON_ERROR),
            'request_id' => $requestId,
            'publication_state' => 'pending',
            'lease_version' => 0,
            'attempts' => 0,
            'attempts_in_cycle' => 0,
            'replay_count' => 0,
            'created_at' => $now,
        ]);

        return [
            'audit_log_id' => $auditId,
            'domain_event_id' => $eventId,
            'outbox_message_id' => $outboxId,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return array{audit_log_id:string,domain_event_id:string,outbox_message_id:string}
     */
    public function recordOrganizationSystemEvent(
        string $organizationId,
        string $action,
        string $entityType,
        string $entityId,
        string $requestId,
        ?array $before,
        ?array $after,
        ?string $reason = null,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Audit/outbox must be written inside the business transaction.');
        }

        $policy = DB::table('audit_action_policy_currents as c')
            ->join('audit_action_policy_revisions as r', function ($join): void {
                $join->on('r.action', '=', 'c.action')
                    ->on('r.policy_version', '=', 'c.policy_version');
            })
            ->where('c.action', $action)
            ->select([
                'r.policy_version',
                'r.payload_validator_code',
                'r.before_payload_requirement',
                'r.after_payload_requirement',
                'r.reason_requirement',
            ])
            ->first();

        if ($policy === null) {
            throw new LogicException("No current audit policy for {$action}.");
        }

        $this->validatePayload((string) $policy->payload_validator_code, $before);
        $this->validatePayload((string) $policy->payload_validator_code, $after);

        if ($policy->before_payload_requirement === 'required' && $before === null) {
            throw new LogicException('Audit before payload is required.');
        }
        if ($policy->after_payload_requirement === 'required' && $after === null) {
            throw new LogicException('Audit after payload is required.');
        }
        if ($policy->reason_requirement === 'required' && ($reason === null || trim($reason) === '')) {
            throw new LogicException('Audit reason is required.');
        }

        $now = now();
        $auditId = (string) Str::uuid7();
        $eventId = (string) Str::uuid7();
        $outboxId = (string) Str::uuid7();

        DB::table('audit_logs')->insert([
            'id' => $auditId,
            'audit_scope' => 'organization',
            'organization_id' => $organizationId,
            'actor_kind' => 'system',
            'actor_organization_membership_id' => null,
            'actor_user_id' => null,
            'action' => $action,
            'audit_policy_version' => (int) $policy->policy_version,
            'entity_reference_mode' => 'snapshot_only',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_redacted_json' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_redacted_json' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'request_id' => $requestId,
            'created_at' => $now,
        ]);

        DB::table('domain_events')->insert([
            'id' => $eventId,
            'event_scope' => 'organization',
            'organization_id' => $organizationId,
            'event_type' => $action,
            'aggregate_reference_mode' => 'snapshot_only',
            'aggregate_type' => $entityType,
            'aggregate_id' => $entityId,
            'request_id' => $requestId,
            'causation_event_id' => null,
            'required_audit_log_id' => $auditId,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);

        DB::table('outbox_messages')->insert([
            'id' => $outboxId,
            'domain_event_id' => $eventId,
            'event_scope' => 'organization',
            'organization_id' => $organizationId,
            'aggregate_type' => $entityType,
            'aggregate_id' => $entityId,
            'event_type' => $action,
            'payload' => json_encode([
                'domain_event_id' => $eventId,
                'event_type' => $action,
                'organization_id' => $organizationId,
            ], JSON_THROW_ON_ERROR),
            'request_id' => $requestId,
            'publication_state' => 'pending',
            'lease_version' => 0,
            'attempts' => 0,
            'attempts_in_cycle' => 0,
            'replay_count' => 0,
            'created_at' => $now,
        ]);

        return [
            'audit_log_id' => $auditId,
            'domain_event_id' => $eventId,
            'outbox_message_id' => $outboxId,
        ];
    }

    /** @param array<string, mixed>|null $payload */
    private function validatePayload(string $validatorCode, ?array $payload): void
    {
        if ($payload === null) {
            return;
        }

        $allowed = self::ALLOWED_KEYS[$validatorCode] ?? null;
        if ($allowed === null) {
            throw new LogicException("Unknown audit payload validator {$validatorCode}.");
        }

        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            foreach (['password', 'token', 'pesel', 'pkk', 'secret', 'external_osk_login'] as $forbidden) {
                if (str_contains($normalized, $forbidden)) {
                    throw new LogicException('Sensitive audit payload key rejected.');
                }
            }
            if (! in_array((string) $key, $allowed, true)) {
                throw new LogicException("Audit payload key {$key} is not allowlisted.");
            }
            if (is_array($value)) {
                foreach ($value as $nested) {
                    if (is_array($nested) || is_object($nested) || is_resource($nested)) {
                        throw new LogicException('Nested complex audit payload rejected.');
                    }
                }
            } elseif (is_object($value) || is_resource($value)) {
                throw new LogicException('Complex audit payload rejected.');
            }
        }
    }
}
