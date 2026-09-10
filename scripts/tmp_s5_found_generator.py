#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLAN_ID = "d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10"

TABLES = [
    ("MIG-TBL-ORGANIZATIONS", 20, "organizations", """
CREATE TABLE organizations (
    id uuid PRIMARY KEY,
    name varchar(255) NOT NULL,
    nip varchar(16) NULL,
    phone varchar(40) NULL,
    timezone varchar(64) NOT NULL DEFAULT 'Europe/Warsaw',
    status varchar(32) NOT NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
"""),
    ("MIG-TBL-USERS", 30, "users", """
CREATE TABLE users (
    id uuid PRIMARY KEY,
    first_name varchar(120) NULL,
    last_name varchar(120) NULL,
    password_hash varchar(255) NULL,
    status varchar(32) NOT NULL,
    last_login_at timestamptz NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
"""),
    ("MIG-TBL-PERMISSIONS", 40, "permissions", """
CREATE TABLE permissions (
    code varchar(128) PRIMARY KEY,
    description varchar(255) NOT NULL
)
"""),
    ("MIG-TBL-DATA_SCOPES", 50, "data_scopes", """
CREATE TABLE data_scopes (
    code varchar(64) PRIMARY KEY,
    description varchar(255) NOT NULL
)
"""),
    ("MIG-TBL-ORGANIZATION_SETTINGS", 120, "organization_settings", """
CREATE TABLE organization_settings (
    organization_id uuid PRIMARY KEY,
    default_language_code varchar(16) NULL,
    preferences jsonb NULL,
    version integer NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
"""),
    ("MIG-TBL-ORGANIZATION_CONTACT_ADDRESSES", 130, "organization_contact_addresses", """
CREATE TABLE organization_contact_addresses (
    organization_id uuid PRIMARY KEY,
    street varchar(255) NOT NULL,
    house_number varchar(32) NOT NULL,
    unit_number varchar(32) NULL,
    postal_code varchar(20) NOT NULL,
    city_name varchar(160) NOT NULL,
    city_reference varchar(128) NULL,
    voivodeship_name varchar(160) NULL,
    country_code char(2) NOT NULL DEFAULT 'PL',
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
"""),
    ("MIG-TBL-AUTH_LOGIN_IDENTIFIERS", 150, "auth_login_identifiers", """
CREATE TABLE auth_login_identifiers (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL,
    identifier_type varchar(32) NOT NULL,
    identifier_normalized varchar(320) NOT NULL,
    is_primary_for_type boolean NOT NULL DEFAULT false,
    verified_at timestamptz NULL,
    created_at timestamptz NOT NULL,
    revoked_at timestamptz NULL
)
"""),
    ("MIG-TBL-ORGANIZATION_MEMBERSHIPS", 170, "organization_memberships", """
CREATE TABLE organization_memberships (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL,
    user_id uuid NOT NULL,
    status varchar(32) NOT NULL,
    is_owner boolean NOT NULL DEFAULT false,
    role_template_code varchar(64) NULL,
    role_template_catalog_version varchar(64) NULL,
    version bigint NOT NULL DEFAULT 1,
    authorization_version bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
)
"""),
    ("MIG-TBL-MEMBERSHIP_PERMISSIONS", 180, "membership_permissions", """
CREATE TABLE membership_permissions (
    membership_id uuid NOT NULL,
    permission_code varchar(128) NOT NULL,
    granted boolean NOT NULL,
    created_at timestamptz NOT NULL,
    PRIMARY KEY (membership_id, permission_code)
)
"""),
    ("MIG-TBL-PERMISSION_SCOPE_OPTIONS", 190, "permission_scope_options", """
CREATE TABLE permission_scope_options (
    permission_code varchar(128) NOT NULL,
    scope_code varchar(64) NOT NULL,
    resolver_code varchar(96) NOT NULL,
    PRIMARY KEY (permission_code, scope_code)
)
"""),
    ("MIG-TBL-MEMBERSHIP_PERMISSION_SCOPES", 200, "membership_permission_scopes", """
CREATE TABLE membership_permission_scopes (
    membership_id uuid NOT NULL,
    permission_code varchar(128) NOT NULL,
    scope_code varchar(64) NOT NULL,
    created_at timestamptz NOT NULL,
    PRIMARY KEY (membership_id, permission_code, scope_code)
)
"""),
    ("MIG-TBL-AUTH_SESSIONS", 210, "auth_sessions", """
CREATE TABLE auth_sessions (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL,
    organization_membership_id uuid NULL,
    token_or_framework_session_hash varchar(255) NOT NULL,
    created_at timestamptz NOT NULL,
    last_seen_at timestamptz NULL,
    revoked_at timestamptz NULL,
    revoke_reason varchar(255) NULL,
    ip_hash varchar(128) NULL,
    user_agent varchar(512) NULL
)
"""),
    ("MIG-TBL-AUDIT_ACTION_POLICY_REVISIONS", 1080, "audit_action_policy_revisions", """
CREATE TABLE audit_action_policy_revisions (
    action varchar(128) NOT NULL,
    policy_version bigint NOT NULL,
    payload_validator_code varchar(128) NOT NULL,
    before_payload_requirement varchar(32) NOT NULL,
    after_payload_requirement varchar(32) NOT NULL,
    reason_requirement varchar(32) NOT NULL,
    policy_hash varchar(128) NOT NULL,
    created_at timestamptz NOT NULL,
    PRIMARY KEY (action, policy_version)
)
"""),
    ("MIG-TBL-AUDIT_ACTION_POLICY_CURRENTS", 1090, "audit_action_policy_currents", """
CREATE TABLE audit_action_policy_currents (
    action varchar(128) PRIMARY KEY,
    policy_version bigint NOT NULL,
    updated_at timestamptz NOT NULL
)
"""),
    ("MIG-TBL-AUDIT_LOGS", 1100, "audit_logs", """
CREATE TABLE audit_logs (
    id uuid PRIMARY KEY,
    audit_scope varchar(32) NOT NULL,
    organization_id uuid NULL,
    actor_kind varchar(32) NOT NULL,
    actor_organization_membership_id uuid NULL,
    actor_user_id uuid NULL,
    action varchar(128) NOT NULL,
    audit_policy_version bigint NOT NULL,
    entity_reference_mode varchar(32) NOT NULL,
    entity_type varchar(128) NULL,
    entity_id varchar(128) NULL,
    before_redacted_json jsonb NULL,
    after_redacted_json jsonb NULL,
    reason text NULL,
    request_id varchar(64) NOT NULL,
    ip_hash varchar(128) NULL,
    user_agent varchar(512) NULL,
    created_at timestamptz NOT NULL
)
"""),
    ("MIG-TBL-DOMAIN_EVENTS", 1110, "domain_events", """
CREATE TABLE domain_events (
    id uuid PRIMARY KEY,
    event_scope varchar(32) NOT NULL,
    organization_id uuid NULL,
    event_type varchar(128) NOT NULL,
    aggregate_reference_mode varchar(32) NOT NULL,
    aggregate_type varchar(128) NULL,
    aggregate_id varchar(128) NULL,
    request_id varchar(64) NOT NULL,
    causation_event_id uuid NULL,
    required_audit_log_id uuid NULL,
    occurred_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL
)
"""),
    ("MIG-TBL-OUTBOX_MESSAGES", 1120, "outbox_messages", """
CREATE TABLE outbox_messages (
    id uuid PRIMARY KEY,
    domain_event_id uuid NOT NULL,
    event_scope varchar(32) NOT NULL,
    organization_id uuid NULL,
    aggregate_type varchar(128) NULL,
    aggregate_id varchar(128) NULL,
    event_type varchar(128) NOT NULL,
    payload jsonb NOT NULL,
    request_id varchar(64) NOT NULL,
    publication_state varchar(32) NOT NULL DEFAULT 'pending',
    next_attempt_at timestamptz NULL,
    lease_token varchar(128) NULL,
    lease_version bigint NOT NULL DEFAULT 0,
    leased_by varchar(128) NULL,
    lease_expires_at timestamptz NULL,
    attempts bigint NOT NULL DEFAULT 0,
    attempts_in_cycle integer NOT NULL DEFAULT 0,
    replay_count integer NOT NULL DEFAULT 0,
    published_at timestamptz NULL,
    last_error_code varchar(128) NULL,
    last_error varchar(512) NULL,
    created_at timestamptz NOT NULL
)
"""),
]

MIGRATION_TEMPLATE = """<?php

use App\\Support\\Migrations\\ControlledMigrationContext;
use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ControlledMigrationContext::assertActive('expand', '__NODE__');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('__NODE__ requires PostgreSQL.');
        }

        if (Schema::hasTable('__TABLE__')) {
            return;
        }

        DB::statement(<<<'SQL'
__SQL__
SQL);

        if (! Schema::hasTable('__TABLE__')) {
            throw new LogicException('__NODE__ postcondition failed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Automatic destructive down is forbidden. Use an explicitly reviewed safe-down plan if one is ever proven safe.');
    }
};
"""

FILES = {}

FILES["app/Modules/IdentityTenant/Models/User.php"] = r"""<?php

namespace App\Modules\IdentityTenant\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    protected $table = 'users';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password_hash'];

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
"""

FILES["app/Modules/IdentityTenant/TenantAuthorizer.php"] = r"""<?php

namespace App\Modules\IdentityTenant;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class TenantAuthorizer
{
    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireOrganizationPermission(string $sessionId, string $targetOrganizationId, string $permission): array
    {
        $membership = $this->activeMembershipForSession($sessionId);

        if ($membership['organization_id'] !== $targetOrganizationId) {
            throw new AuthorizationException('Cross-tenant access denied.');
        }

        $granted = DB::table('membership_permissions')
            ->where('membership_id', $membership['id'])
            ->where('permission_code', $permission)
            ->where('granted', true)
            ->exists();

        if (! $granted) {
            throw new AuthorizationException('Permission denied.');
        }

        $hasOrganizationScope = DB::table('membership_permission_scopes as s')
            ->join('permission_scope_options as o', function ($join): void {
                $join->on('o.permission_code', '=', 's.permission_code')
                    ->on('o.scope_code', '=', 's.scope_code');
            })
            ->where('s.membership_id', $membership['id'])
            ->where('s.permission_code', $permission)
            ->where('s.scope_code', 'organization')
            ->where('o.resolver_code', 'tenant_resource')
            ->exists();

        if (! $hasOrganizationScope) {
            throw new AuthorizationException('Permission scope denied.');
        }

        return $membership;
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function activeMembershipForSession(string $sessionId): array
    {
        $row = DB::table('auth_sessions as s')
            ->join('organization_memberships as m', function ($join): void {
                $join->on('m.id', '=', 's.organization_membership_id')
                    ->on('m.user_id', '=', 's.user_id');
            })
            ->where('s.id', $sessionId)
            ->whereNull('s.revoked_at')
            ->select([
                'm.id',
                'm.organization_id',
                'm.user_id',
                'm.status',
                'm.is_owner',
                'm.version',
                'm.authorization_version',
            ])
            ->first();

        if ($row === null || $row->status !== 'active') {
            throw new AuthorizationException('Active tenant membership context required.');
        }

        return [
            'id' => (string) $row->id,
            'organization_id' => (string) $row->organization_id,
            'user_id' => (string) $row->user_id,
            'status' => (string) $row->status,
            'is_owner' => (bool) $row->is_owner,
            'version' => (int) $row->version,
            'authorization_version' => (int) $row->authorization_version,
        ];
    }

    /** @return list<string> */
    public function grantedScopes(string $membershipId, string $permission): array
    {
        if (! DB::table('membership_permissions')
            ->where('membership_id', $membershipId)
            ->where('permission_code', $permission)
            ->where('granted', true)
            ->exists()) {
            return [];
        }

        $scopes = DB::table('membership_permission_scopes')
            ->where('membership_id', $membershipId)
            ->where('permission_code', $permission)
            ->orderBy('scope_code')
            ->pluck('scope_code')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        return array_values($scopes);
    }
}
"""

FILES["app/Modules/AuditNotification/AtomicAuditOutbox.php"] = r"""<?php

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
    ];

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
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
"""

FILES["app/Modules/IdentityTenant/MembershipGovernance.php"] = r"""<?php

namespace App\Modules\IdentityTenant;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class MembershipGovernance
{
    private const OWNER_BASELINE = [
        'organization.view',
        'organization.members.manage',
        'staff.permissions.manage',
        'sessions.manage.organization',
    ];

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /**
     * @param list<string> $scopeCodes
     * @return array{version:int,authorization_version:int}
     */
    public function replacePermissionScopes(
        string $actorSessionId,
        string $targetMembershipId,
        int $expectedVersion,
        string $permission,
        bool $granted,
        array $scopeCodes,
        string $requestId,
    ): array {
        return DB::transaction(function () use (
            $actorSessionId,
            $targetMembershipId,
            $expectedVersion,
            $permission,
            $granted,
            $scopeCodes,
            $requestId,
        ): array {
            $target = DB::table('organization_memberships')
                ->where('id', $targetMembershipId)
                ->lockForUpdate()
                ->first();

            if ($target === null) {
                throw new LogicException('Target membership not found.');
            }
            if ((int) $target->version !== $expectedVersion) {
                throw new LogicException('Stale membership version.');
            }

            $organizationId = (string) $target->organization_id;
            $actor = $this->authorizer->requireOrganizationPermission(
                $actorSessionId,
                $organizationId,
                'staff.permissions.manage',
            );

            if ((string) $target->organization_id !== $actor['organization_id']) {
                throw new AuthorizationException('Cross-tenant membership mutation denied.');
            }

            $requestedScopes = array_values(array_unique($scopeCodes));
            sort($requestedScopes);

            if (! $granted && $requestedScopes !== []) {
                throw new LogicException('Denied permission cannot retain scopes.');
            }

            $currentGranted = DB::table('membership_permissions')
                ->where('membership_id', $targetMembershipId)
                ->where('permission_code', $permission)
                ->where('granted', true)
                ->exists();
            $currentScopes = $this->authorizer->grantedScopes($targetMembershipId, $permission);
            sort($currentScopes);

            $broadening = $granted && (! $currentGranted || array_diff($requestedScopes, $currentScopes) !== []);
            if ($targetMembershipId === $actor['id'] && $broadening) {
                throw new AuthorizationException('Self privilege escalation denied.');
            }

            if ($granted) {
                if ($requestedScopes === []) {
                    throw new LogicException('Granted permission requires at least one scope.');
                }

                $legalScopes = DB::table('permission_scope_options')
                    ->where('permission_code', $permission)
                    ->whereIn('scope_code', $requestedScopes)
                    ->pluck('scope_code')
                    ->map(static fn ($value): string => (string) $value)
                    ->all();
                sort($legalScopes);
                if ($legalScopes !== $requestedScopes) {
                    throw new AuthorizationException('Unsupported permission scope pair.');
                }

                $actorScopes = $this->authorizer->grantedScopes($actor['id'], $permission);
                sort($actorScopes);
                if (! DB::table('membership_permissions')
                    ->where('membership_id', $actor['id'])
                    ->where('permission_code', $permission)
                    ->where('granted', true)
                    ->exists() || array_diff($requestedScopes, $actorScopes) !== []) {
                    throw new AuthorizationException('Grant exceeds actor permission ceiling.');
                }

                if ($broadening && ! $actor['is_owner']) {
                    throw new AuthorizationException('Privilege broadening requires active owner actor.');
                }
            }

            if ($currentGranted === $granted && $currentScopes === $requestedScopes) {
                return [
                    'version' => (int) $target->version,
                    'authorization_version' => (int) $target->authorization_version,
                ];
            }

            DB::table('membership_permissions')->updateOrInsert(
                ['membership_id' => $targetMembershipId, 'permission_code' => $permission],
                ['granted' => $granted, 'created_at' => now()],
            );
            DB::table('membership_permission_scopes')
                ->where('membership_id', $targetMembershipId)
                ->where('permission_code', $permission)
                ->delete();

            if ($granted) {
                DB::table('membership_permission_scopes')->insert(array_map(
                    static fn (string $scope): array => [
                        'membership_id' => $targetMembershipId,
                        'permission_code' => $permission,
                        'scope_code' => $scope,
                        'created_at' => now(),
                    ],
                    $requestedScopes,
                ));
            }

            $newVersion = (int) $target->version + 1;
            $newAuthorizationVersion = (int) $target->authorization_version + 1;
            DB::table('organization_memberships')->where('id', $targetMembershipId)->update([
                'version' => $newVersion,
                'authorization_version' => $newAuthorizationVersion,
                'updated_at' => now(),
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $organizationId,
                $actor['id'],
                $actor['user_id'],
                'authorization.permission.changed',
                'organization_membership',
                $targetMembershipId,
                $requestId,
                [
                    'membership_id' => $targetMembershipId,
                    'permission' => $permission,
                    'granted' => $currentGranted,
                    'scopes' => $currentScopes,
                    'version' => (int) $target->version,
                    'authorization_version' => (int) $target->authorization_version,
                ],
                [
                    'membership_id' => $targetMembershipId,
                    'permission' => $permission,
                    'granted' => $granted,
                    'scopes' => $requestedScopes,
                    'version' => $newVersion,
                    'authorization_version' => $newAuthorizationVersion,
                ],
            );

            return ['version' => $newVersion, 'authorization_version' => $newAuthorizationVersion];
        });
    }

    public function transferOwner(
        string $actorSessionId,
        string $fromMembershipId,
        string $toMembershipId,
        string $requestId,
    ): void {
        DB::transaction(function () use ($actorSessionId, $fromMembershipId, $toMembershipId, $requestId): void {
            if ($fromMembershipId === $toMembershipId) {
                throw new LogicException('Owner transfer requires two memberships.');
            }

            $from = DB::table('organization_memberships')->where('id', $fromMembershipId)->first();
            if ($from === null) {
                throw new LogicException('Current owner membership not found.');
            }
            $organizationId = (string) $from->organization_id;
            $actor = $this->authorizer->requireOrganizationPermission(
                $actorSessionId,
                $organizationId,
                'organization.members.manage',
            );
            if (! $actor['is_owner']) {
                throw new AuthorizationException('Owner transfer requires an active owner actor.');
            }

            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();

            $rows = DB::table('organization_memberships')
                ->where('organization_id', $organizationId)
                ->whereIn('id', [$fromMembershipId, $toMembershipId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedFrom = $rows->get($fromMembershipId);
            $lockedTo = $rows->get($toMembershipId);
            if ($lockedFrom === null || $lockedTo === null) {
                throw new AuthorizationException('Owner transfer memberships must share one tenant.');
            }
            if ($lockedFrom->status !== 'active' || ! (bool) $lockedFrom->is_owner || $lockedTo->status !== 'active') {
                throw new AuthorizationException('Owner transfer requires active source owner and active successor.');
            }
            if (! $this->hasOwnerBaseline($toMembershipId)) {
                throw new AuthorizationException('Successor lacks materialized owner permission baseline.');
            }

            DB::table('organization_memberships')->where('id', $toMembershipId)->update([
                'is_owner' => true,
                'version' => (int) $lockedTo->version + 1,
                'authorization_version' => (int) $lockedTo->authorization_version + 1,
                'updated_at' => now(),
            ]);
            DB::table('organization_memberships')->where('id', $fromMembershipId)->update([
                'is_owner' => false,
                'version' => (int) $lockedFrom->version + 1,
                'authorization_version' => (int) $lockedFrom->authorization_version + 1,
                'updated_at' => now(),
            ]);

            $activeOwners = DB::table('organization_memberships')
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->where('is_owner', true)
                ->count();
            if ($activeOwners < 1) {
                throw new LogicException('Last active owner invariant violated.');
            }

            $this->auditOutbox->recordOrganizationEvent(
                $organizationId,
                $actor['id'],
                $actor['user_id'],
                'authorization.owner.transferred',
                'organization_membership',
                $toMembershipId,
                $requestId,
                ['from_owner_membership_id' => $fromMembershipId, 'to_owner_membership_id' => null],
                ['from_owner_membership_id' => $fromMembershipId, 'to_owner_membership_id' => $toMembershipId],
            );
        });
    }

    private function hasOwnerBaseline(string $membershipId): bool
    {
        foreach (self::OWNER_BASELINE as $permission) {
            if (! DB::table('membership_permissions')
                ->where('membership_id', $membershipId)
                ->where('permission_code', $permission)
                ->where('granted', true)
                ->exists()) {
                return false;
            }

            if (! DB::table('membership_permission_scopes')
                ->where('membership_id', $membershipId)
                ->where('permission_code', $permission)
                ->where('scope_code', 'organization')
                ->exists()) {
                return false;
            }
        }

        return true;
    }
}
"""

FILES["app/Modules/OrganizationSettings/OrganizationSettingsService.php"] = r"""<?php

namespace App\Modules\OrganizationSettings;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\IdentityTenant\TenantAuthorizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class OrganizationSettingsService
{
    private const ALLOWED_FIELDS = [
        'first_name', 'last_name', 'company_name', 'phone', 'address',
    ];

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /**
     * @param array<string, mixed> $changes
     * @return array{version:int}
     */
    public function update(string $sessionId, int $expectedVersion, array $changes, string $requestId): array
    {
        $unknown = array_diff(array_keys($changes), self::ALLOWED_FIELDS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown or not-yet-materialized settings field: '.implode(',', $unknown));
        }

        $sessionMembership = $this->authorizer->activeMembershipForSession($sessionId);
        $organizationId = $sessionMembership['organization_id'];
        $actor = $this->authorizer->requireOrganizationPermission(
            $sessionId,
            $organizationId,
            'organization.settings.manage',
        );

        return DB::transaction(function () use (
            $expectedVersion,
            $changes,
            $requestId,
            $organizationId,
            $actor,
        ): array {
            $settings = DB::table('organization_settings')
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if ($settings === null) {
                throw new LogicException('Organization settings aggregate missing.');
            }
            if ((int) $settings->version !== $expectedVersion) {
                throw new LogicException('Stale organization settings version.');
            }

            $changedFields = [];

            $userUpdates = [];
            foreach (['first_name', 'last_name'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $userUpdates[$field] = $changes[$field];
                    $changedFields[] = $field;
                }
            }
            if ($userUpdates !== []) {
                $userUpdates['updated_at'] = now();
                DB::table('users')->where('id', $actor['user_id'])->update($userUpdates);
            }

            $organizationUpdates = [];
            if (array_key_exists('company_name', $changes)) {
                $organizationUpdates['name'] = $changes['company_name'];
                $changedFields[] = 'company_name';
            }
            if (array_key_exists('phone', $changes)) {
                $organizationUpdates['phone'] = $changes['phone'];
                $changedFields[] = 'phone';
            }
            if ($organizationUpdates !== []) {
                $organizationUpdates['updated_at'] = now();
                DB::table('organizations')->where('id', $organizationId)->update($organizationUpdates);
            }

            if (array_key_exists('address', $changes)) {
                if (! is_array($changes['address'])) {
                    throw new InvalidArgumentException('Address must be an object.');
                }
                $address = $changes['address'];
                foreach (['street', 'house_number', 'postal_code', 'city_name'] as $required) {
                    if (! isset($address[$required]) || ! is_string($address[$required]) || trim($address[$required]) === '') {
                        throw new InvalidArgumentException("Address {$required} is required.");
                    }
                }
                $allowedAddress = [
                    'street', 'house_number', 'unit_number', 'postal_code', 'city_name',
                    'city_reference', 'voivodeship_name', 'country_code',
                ];
                if (array_diff(array_keys($address), $allowedAddress) !== []) {
                    throw new InvalidArgumentException('Unknown address field.');
                }

                DB::table('organization_contact_addresses')->updateOrInsert(
                    ['organization_id' => $organizationId],
                    [
                        'street' => $address['street'],
                        'house_number' => $address['house_number'],
                        'unit_number' => $address['unit_number'] ?? null,
                        'postal_code' => $address['postal_code'],
                        'city_name' => $address['city_name'],
                        'city_reference' => $address['city_reference'] ?? null,
                        'voivodeship_name' => $address['voivodeship_name'] ?? null,
                        'country_code' => $address['country_code'] ?? 'PL',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
                $changedFields[] = 'address';
            }

            if ($changedFields === []) {
                return ['version' => (int) $settings->version];
            }

            $newVersion = (int) $settings->version + 1;
            DB::table('organization_settings')->where('organization_id', $organizationId)->update([
                'version' => $newVersion,
                'updated_at' => now(),
            ]);

            sort($changedFields);
            $this->auditOutbox->recordOrganizationEvent(
                $organizationId,
                $actor['id'],
                $actor['user_id'],
                'organization.settings.updated',
                'organization',
                $organizationId,
                $requestId,
                ['fields' => $changedFields, 'version' => (int) $settings->version],
                ['fields' => $changedFields, 'version' => $newVersion],
            );

            return ['version' => $newVersion];
        });
    }
}
"""

FILES["tests/Support/FoundationSchema.php"] = r"""<?php

namespace Tests\Support;

use App\Support\Migrations\MigrationPlan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class FoundationSchema
{
    /** @var list<string> */
    private const TABLES = [
        'outbox_messages',
        'domain_events',
        'audit_logs',
        'audit_action_policy_currents',
        'audit_action_policy_revisions',
        'auth_sessions',
        'membership_permission_scopes',
        'permission_scope_options',
        'membership_permissions',
        'organization_memberships',
        'auth_login_identifiers',
        'organization_contact_addresses',
        'organization_settings',
        'data_scopes',
        'permissions',
        'users',
        'organizations',
    ];

    public static function ensureMigrated(): void
    {
        $plan = app(MigrationPlan::class);
        $plan->validate();

        if (! DB::getSchemaBuilder()->hasTable('organizations')) {
            $exit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'expand',
                '--force' => true,
            ]);
            if ($exit !== 0) {
                throw new LogicException('Foundation controlled migration failed: '.Artisan::output());
            }
        }
    }

    public static function reset(): void
    {
        self::ensureMigrated();
        foreach (self::TABLES as $table) {
            DB::table($table)->delete();
        }
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    public static function actor(bool $owner = true): array
    {
        $org = (string) Str::uuid7();
        $user = (string) Str::uuid7();
        $membership = (string) Str::uuid7();
        $session = (string) Str::uuid7();
        $now = now();

        DB::table('organizations')->insert([
            'id' => $org, 'name' => 'Synthetic OSK', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('organization_settings')->insert([
            'organization_id' => $org, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('users')->insert([
            'id' => $user, 'first_name' => 'Test', 'last_name' => 'Owner', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('organization_memberships')->insert([
            'id' => $membership, 'organization_id' => $org, 'user_id' => $user,
            'status' => 'active', 'is_owner' => $owner, 'version' => 1, 'authorization_version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('auth_sessions')->insert([
            'id' => $session, 'user_id' => $user, 'organization_membership_id' => $membership,
            'token_or_framework_session_hash' => hash('sha256', $session), 'created_at' => $now,
        ]);

        self::seedScopes();
        self::grant($membership, 'staff.permissions.manage', ['organization']);
        self::grant($membership, 'organization.members.manage', ['organization']);
        self::grant($membership, 'organization.settings.manage', ['organization']);

        if ($owner) {
            foreach (['organization.view', 'sessions.manage.organization'] as $permission) {
                self::grant($membership, $permission, ['organization']);
            }
        }

        self::installAuditPolicies();

        return [
            'organization_id' => $org,
            'user_id' => $user,
            'membership_id' => $membership,
            'session_id' => $session,
        ];
    }

    /** @param list<string> $scopes */
    public static function grant(string $membershipId, string $permission, array $scopes): void
    {
        DB::table('permissions')->updateOrInsert(
            ['code' => $permission],
            ['description' => $permission],
        );
        foreach ($scopes as $scope) {
            DB::table('permission_scope_options')->updateOrInsert(
                ['permission_code' => $permission, 'scope_code' => $scope],
                ['resolver_code' => $scope === 'organization' ? 'tenant_resource' : 'not_materialized'],
            );
        }
        DB::table('membership_permissions')->updateOrInsert(
            ['membership_id' => $membershipId, 'permission_code' => $permission],
            ['granted' => true, 'created_at' => now()],
        );
        DB::table('membership_permission_scopes')
            ->where('membership_id', $membershipId)
            ->where('permission_code', $permission)
            ->delete();
        foreach ($scopes as $scope) {
            DB::table('membership_permission_scopes')->insert([
                'membership_id' => $membershipId,
                'permission_code' => $permission,
                'scope_code' => $scope,
                'created_at' => now(),
            ]);
        }
    }

    public static function member(string $organizationId, bool $owner = false): string
    {
        $user = (string) Str::uuid7();
        $membership = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $user, 'first_name' => 'Synthetic', 'last_name' => 'Member', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('organization_memberships')->insert([
            'id' => $membership, 'organization_id' => $organizationId, 'user_id' => $user,
            'status' => 'active', 'is_owner' => $owner, 'version' => 1, 'authorization_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $membership;
    }

    public static function installAuditPolicies(): void
    {
        $policies = [
            'authorization.permission.changed' => 'foundation.authorization.v1',
            'authorization.owner.transferred' => 'foundation.authorization.v1',
            'organization.settings.updated' => 'foundation.settings.v1',
        ];
        foreach ($policies as $action => $validator) {
            DB::table('audit_action_policy_revisions')->updateOrInsert(
                ['action' => $action, 'policy_version' => 1],
                [
                    'payload_validator_code' => $validator,
                    'before_payload_requirement' => 'required',
                    'after_payload_requirement' => 'required',
                    'reason_requirement' => 'optional',
                    'policy_hash' => hash('sha256', $action.'|1|'.$validator),
                    'created_at' => now(),
                ],
            );
            DB::table('audit_action_policy_currents')->updateOrInsert(
                ['action' => $action],
                ['policy_version' => 1, 'updated_at' => now()],
            );
        }
    }

    private static function seedScopes(): void
    {
        foreach ([
            'organization' => 'Tenant-wide resource scope',
            'own' => 'Own resource scope',
            'assigned_students' => 'Assigned students scope',
            'assigned_locations' => 'Assigned locations scope',
        ] as $code => $description) {
            DB::table('data_scopes')->updateOrInsert(['code' => $code], ['description' => $description]);
        }
    }
}
"""

FILES["tests/Feature/IdentityTenantFoundationTest.php"] = r"""<?php

namespace Tests\Feature;

use App\Modules\IdentityTenant\MembershipGovernance;
use App\Modules\IdentityTenant\TenantAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class IdentityTenantFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_dbt_iam_009_tenant_request_without_membership_context_is_denied(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('auth_sessions')->where('id', $actor['session_id'])->update(['organization_membership_id' => null]);

        $this->expectException(AuthorizationException::class);
        app(TenantAuthorizer::class)->requireOrganizationPermission(
            $actor['session_id'],
            $actor['organization_id'],
            'organization.view',
        );
    }

    public function test_dbt_iam_013_granted_permission_without_scope_is_denied(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('membership_permission_scopes')
            ->where('membership_id', $actor['membership_id'])
            ->where('permission_code', 'organization.view')
            ->delete();

        $this->expectException(AuthorizationException::class);
        app(TenantAuthorizer::class)->requireOrganizationPermission(
            $actor['session_id'],
            $actor['organization_id'],
            'organization.view',
        );
    }

    public function test_dbt_iam_015_organization_scope_never_bypasses_cross_tenant_validation(): void
    {
        $actor = FoundationSchema::actor();
        $other = FoundationSchema::actor();

        $this->expectException(AuthorizationException::class);
        app(TenantAuthorizer::class)->requireOrganizationPermission(
            $actor['session_id'],
            $other['organization_id'],
            'organization.view',
        );
    }

    public function test_dbt_core_009_owner_marker_alone_does_not_grant_permissions(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('membership_permissions')->where('membership_id', $actor['membership_id'])->delete();
        DB::table('membership_permission_scopes')->where('membership_id', $actor['membership_id'])->delete();

        $this->expectException(AuthorizationException::class);
        app(TenantAuthorizer::class)->requireOrganizationPermission(
            $actor['session_id'],
            $actor['organization_id'],
            'organization.view',
        );
    }

    public function test_dbt_iam_020_suspended_membership_cannot_authorize_with_retained_permission_rows(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('organization_memberships')->where('id', $actor['membership_id'])->update(['status' => 'suspended']);

        $this->expectException(AuthorizationException::class);
        app(TenantAuthorizer::class)->requireOrganizationPermission(
            $actor['session_id'],
            $actor['organization_id'],
            'organization.view',
        );
    }

    public function test_dbt_iam_017_actor_cannot_grant_permission_above_own_ceiling(): void
    {
        $actor = FoundationSchema::actor();
        $target = FoundationSchema::member($actor['organization_id']);
        DB::table('permissions')->insert(['code' => 'students.manage', 'description' => 'students.manage']);
        DB::table('permission_scope_options')->insert([
            'permission_code' => 'students.manage', 'scope_code' => 'organization', 'resolver_code' => 'tenant_resource',
        ]);

        $this->expectException(AuthorizationException::class);
        app(MembershipGovernance::class)->replacePermissionScopes(
            $actor['session_id'],
            $target,
            1,
            'students.manage',
            true,
            ['organization'],
            (string) Str::uuid7(),
        );
    }

    public function test_dbt_iam_016_owner_transfer_requires_materialized_successor_baseline(): void
    {
        $actor = FoundationSchema::actor();
        $target = FoundationSchema::member($actor['organization_id']);

        $this->expectException(AuthorizationException::class);
        app(MembershipGovernance::class)->transferOwner(
            $actor['session_id'],
            $actor['membership_id'],
            $target,
            (string) Str::uuid7(),
        );
    }

    public function test_dbt_core_015_authorization_mutation_increments_authorization_version_once(): void
    {
        $actor = FoundationSchema::actor();
        $target = FoundationSchema::member($actor['organization_id']);
        FoundationSchema::grant($actor['membership_id'], 'students.manage', ['organization']);

        $result = app(MembershipGovernance::class)->replacePermissionScopes(
            $actor['session_id'],
            $target,
            1,
            'students.manage',
            true,
            ['organization'],
            (string) Str::uuid7(),
        );

        $this->assertSame(2, $result['version']);
        $this->assertSame(2, $result['authorization_version']);
        $row = DB::table('organization_memberships')->where('id', $target)->first();
        $this->assertSame(2, (int) $row->version);
        $this->assertSame(2, (int) $row->authorization_version);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('domain_events', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }
}
"""

FILES["tests/Feature/OrganizationSettingsFoundationTest.php"] = r"""<?php

namespace Tests\Feature;

use App\Modules\OrganizationSettings\OrganizationSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class OrganizationSettingsFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_dbt_iam_005_settings_version_increments_once_per_successful_atomic_write(): void
    {
        $actor = FoundationSchema::actor();

        $result = app(OrganizationSettingsService::class)->update(
            $actor['session_id'],
            1,
            [
                'first_name' => 'Anna',
                'company_name' => 'OSK Test',
                'address' => [
                    'street' => 'Testowa',
                    'house_number' => '1',
                    'postal_code' => '00-001',
                    'city_name' => 'Warszawa',
                ],
            ],
            (string) Str::uuid7(),
        );

        $this->assertSame(2, $result['version']);
        $this->assertSame(2, (int) DB::table('organization_settings')->where('organization_id', $actor['organization_id'])->value('version'));
        $this->assertSame('Anna', DB::table('users')->where('id', $actor['user_id'])->value('first_name'));
        $this->assertSame('OSK Test', DB::table('organizations')->where('id', $actor['organization_id'])->value('name'));
        $this->assertSame('Warszawa', DB::table('organization_contact_addresses')->where('organization_id', $actor['organization_id'])->value('city_name'));
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_stale_settings_version_rolls_back_without_partial_write(): void
    {
        $actor = FoundationSchema::actor();

        try {
            app(OrganizationSettingsService::class)->update(
                $actor['session_id'],
                99,
                ['company_name' => 'Must Not Persist'],
                (string) Str::uuid7(),
            );
            $this->fail('Expected stale version failure.');
        } catch (LogicException) {
            $this->assertSame('Synthetic OSK', DB::table('organizations')->where('id', $actor['organization_id'])->value('name'));
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertDatabaseCount('domain_events', 0);
            $this->assertDatabaseCount('outbox_messages', 0);
        }
    }
}
"""

FILES["tests/Feature/AuditOutboxFoundationTest.php"] = r"""<?php

namespace Tests\Feature;

use App\Modules\OrganizationSettings\OrganizationSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class AuditOutboxFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_dbt_aud_003_missing_current_audit_policy_rolls_back_business_effect(): void
    {
        $actor = FoundationSchema::actor();
        DB::table('audit_action_policy_currents')->where('action', 'organization.settings.updated')->delete();

        try {
            app(OrganizationSettingsService::class)->update(
                $actor['session_id'],
                1,
                ['company_name' => 'Rollback Me'],
                (string) Str::uuid7(),
            );
            $this->fail('Expected audit policy failure.');
        } catch (LogicException) {
            $this->assertSame('Synthetic OSK', DB::table('organizations')->where('id', $actor['organization_id'])->value('name'));
            $this->assertSame(1, (int) DB::table('organization_settings')->where('organization_id', $actor['organization_id'])->value('version'));
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertDatabaseCount('domain_events', 0);
            $this->assertDatabaseCount('outbox_messages', 0);
        }
    }

    public function test_dbt_aud_005_business_audit_domain_event_and_outbox_commit_together(): void
    {
        $actor = FoundationSchema::actor();
        $requestId = (string) Str::uuid7();

        app(OrganizationSettingsService::class)->update(
            $actor['session_id'],
            1,
            ['phone' => '+48123456789'],
            $requestId,
        );

        $audit = DB::table('audit_logs')->first();
        $event = DB::table('domain_events')->first();
        $outbox = DB::table('outbox_messages')->first();

        $this->assertNotNull($audit);
        $this->assertNotNull($event);
        $this->assertNotNull($outbox);
        $this->assertSame($actor['organization_id'], $audit->organization_id);
        $this->assertSame($actor['organization_id'], $event->organization_id);
        $this->assertSame($actor['organization_id'], $outbox->organization_id);
        $this->assertSame($audit->id, $event->required_audit_log_id);
        $this->assertSame($event->id, $outbox->domain_event_id);
        $this->assertSame($requestId, $audit->request_id);
        $this->assertSame($requestId, $event->request_id);
        $this->assertSame($requestId, $outbox->request_id);
    }
}
"""

FILES["specs/traceability/implementation/IdentityTenant.yml"] = """module: IdentityTenant
core_traceability_modules:
  - auth_identity
changed_scope:
  - evidence: specs/database/identity-rbac.yml
    requirement: tenant operations derive organization from an active same-user membership context
    domain_data: auth_sessions + organization_memberships
    api_use_case: tenant authorization foundation
    permission: explicit membership permission plus per-permission scope
    ui: NOT_APPLICABLE_FOUNDATION_NO_UI
    acceptance_test: Tests\\\\Feature\\\\IdentityTenantFoundationTest
    status: IMPLEMENTED
  - evidence: docs/87-physical-database-schema.md
    requirement: owner marker is governance state and cannot bypass runtime permission authority
    domain_data: organization_memberships + membership_permissions + membership_permission_scopes
    api_use_case: owner governance foundation
    permission: organization.members.manage
    ui: NOT_APPLICABLE_FOUNDATION_NO_UI
    acceptance_test: Tests\\\\Feature\\\\IdentityTenantFoundationTest
    status: IMPLEMENTED
"""

FILES["specs/traceability/implementation/OrganizationSettings.yml"] = """module: OrganizationSettings
core_traceability_modules:
  - auth_identity
changed_scope:
  - evidence: specs/database/organization-settings.yml
    requirement: settings write is tenant-authorized optimistic-concurrency transaction
    domain_data: organization_settings + organizations + users + organization_contact_addresses
    api_use_case: update organization settings foundation service
    permission: organization.settings.manage
    ui: DEFERRED_UI_UNTIL_FEATURE_SLICE
    acceptance_test: Tests\\\\Feature\\\\OrganizationSettingsFoundationTest
    status: IMPLEMENTED
"""

FILES["specs/traceability/implementation/AuditNotification.yml"] = """module: AuditNotification
core_traceability_modules:
  - dashboard_activity
changed_scope:
  - evidence: specs/database/audit-outbox-notifications.yml
    requirement: critical mutation commits business state audit domain event and outbox intent atomically
    domain_data: audit_logs + domain_events + outbox_messages
    api_use_case: atomic audit outbox foundation
    permission: inherited_from_calling_authorized_command
    ui: NOT_APPLICABLE_FOUNDATION_NO_UI
    acceptance_test: Tests\\\\Feature\\\\AuditOutboxFoundationTest
    status: IMPLEMENTED
"""

AUTH_CONFIG = r"""<?php

use App\Modules\IdentityTenant\Models\User;

return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => null,
    ],
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],
    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => null,
            'expire' => null,
            'throttle' => null,
        ],
    ],
    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
"""

DBT_REGISTRY = r"""<?php

use Tests\Feature\AuditOutboxFoundationTest;
use Tests\Feature\IdentityTenantFoundationTest;
use Tests\Feature\OrganizationSettingsFoundationTest;
use Tests\Feature\Stage4MigrationPostcheckTest;

return [
    'DBT-CORE-009' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_core_009_owner_marker_alone_does_not_grant_permissions',
        'scope' => 'Owner governance marker never bypasses explicit runtime permission authority.',
    ],
    'DBT-CORE-015' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_core_015_authorization_mutation_increments_authorization_version_once',
        'scope' => 'Authorization-affecting permission mutation increments membership and authorization version once and emits audit/outbox.',
    ],
    'DBT-IAM-005' => [
        'class' => OrganizationSettingsFoundationTest::class,
        'method' => 'test_dbt_iam_005_settings_version_increments_once_per_successful_atomic_write',
        'scope' => 'Organization settings optimistic concurrency version increments exactly once on a successful atomic write.',
    ],
    'DBT-IAM-009' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_iam_009_tenant_request_without_membership_context_is_denied',
        'scope' => 'Tenant operation without active membership context fails closed.',
    ],
    'DBT-IAM-013' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_iam_013_granted_permission_without_scope_is_denied',
        'scope' => 'Granted permission without an applicable scope fails closed.',
    ],
    'DBT-IAM-015' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_iam_015_organization_scope_never_bypasses_cross_tenant_validation',
        'scope' => 'Organization scope cannot cross the active membership tenant.',
    ],
    'DBT-IAM-016' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_iam_016_owner_transfer_requires_materialized_successor_baseline',
        'scope' => 'Owner successor requires the protected permission baseline before transfer.',
    ],
    'DBT-IAM-017' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_iam_017_actor_cannot_grant_permission_above_own_ceiling',
        'scope' => 'Actor cannot grant a permission/scope it does not hold.',
    ],
    'DBT-IAM-020' => [
        'class' => IdentityTenantFoundationTest::class,
        'method' => 'test_dbt_iam_020_suspended_membership_cannot_authorize_with_retained_permission_rows',
        'scope' => 'Suspended membership cannot authorize despite retained permission rows.',
    ],
    'DBT-AUD-003' => [
        'class' => AuditOutboxFoundationTest::class,
        'method' => 'test_dbt_aud_003_missing_current_audit_policy_rolls_back_business_effect',
        'scope' => 'Runtime audit requires a registered current policy and failure rolls back the business effect.',
    ],
    'DBT-AUD-005' => [
        'class' => AuditOutboxFoundationTest::class,
        'method' => 'test_dbt_aud_005_business_audit_domain_event_and_outbox_commit_together',
        'scope' => 'Business effect, audit, domain event, and outbox intent commit in one local transaction.',
    ],
    'DBT-CORE-099' => [
        'class' => Stage4MigrationPostcheckTest::class,
        'method' => 'test_dbt_core_099_zero_gap_traceability_authority_is_executable',
        'scope' => 'Stage-4 zero-gap coverage summary and complete 491-ID catalog remain executable and unchanged.',
    ],
    'DBT-CORE-100' => [
        'class' => Stage4MigrationPostcheckTest::class,
        'method' => 'test_dbt_core_100_final_aggregate_sync_is_executable',
        'scope' => 'Final Stage-4 aggregate authority blobs and 491-test catalog remain synchronized.',
    ],
];
"""

def write(path: str, content: str) -> None:
    target = ROOT / path
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(content.rstrip() + "\n", encoding="utf-8")

def migration_name(order: int, table: str) -> str:
    return f"2026_09_10_{order:06d}_create_{table}_table"

def generate() -> None:
    for node, order, table, sql in TABLES:
        name = migration_name(order, table)
        path = f"database/migrations/stage4/expand/{node}/{name}.php"
        content = MIGRATION_TEMPLATE.replace("__NODE__", node).replace("__TABLE__", table).replace("__SQL__", sql.strip())
        write(path, content)

    for path, content in FILES.items():
        write(path, content)
    write("config/auth.php", AUTH_CONFIG)
    write("tests/Contracts/stage4-test-implementations.php", DBT_REGISTRY)

def finalize_registry() -> None:
    registry_path = ROOT / "database/migration-plan/implementations.json"
    registry = json.loads(registry_path.read_text(encoding="utf-8"))
    existing = {f"{x['node_id']}|{x['phase']}": x for x in registry["implemented_steps"]}

    for node, order, table, _sql in TABLES:
        name = migration_name(order, table)
        rel = f"database/migrations/stage4/expand/{node}/{name}.php"
        digest = hashlib.sha256((ROOT / rel).read_bytes()).hexdigest()
        existing[f"{node}|expand"] = {
            "node_id": node,
            "phase": "expand",
            "migration_file": rel,
            "migration_name": name,
            "file_sha256": digest,
            "restart_classification": "restart_safe",
            "safe_down": False,
        }

    plan = json.loads((ROOT / "database/migration-plan/plan.json").read_text(encoding="utf-8"))
    order_map = {x["node_id"]: x["order"] for x in plan["nodes"]}
    steps = sorted(existing.values(), key=lambda x: (order_map[x["node_id"]], x["phase"]))
    registry["implemented_steps"] = steps

    lines = [
        f"schema_version|{registry['schema_version']}",
        f"plan_identity|{registry['plan_identity']}",
        f"stage4_root|{registry['stage4_root']}",
    ]
    for step in steps:
        lines.append("|".join([
            "step",
            step["node_id"],
            step["phase"],
            step["migration_file"],
            step["migration_name"],
            step["file_sha256"],
            step["restart_classification"],
            "1" if step["safe_down"] else "0",
        ]))
    registry["execution_identity"] = hashlib.sha256(("\n".join(lines) + "\n").encode()).hexdigest()
    registry_path.write_text(json.dumps(registry, indent=2) + "\n", encoding="utf-8")

def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--finalize-registry", action="store_true")
    args = parser.parse_args()
    if args.finalize_registry:
        finalize_registry()
    else:
        generate()

if __name__ == "__main__":
    main()
