<?php

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
     * @param  list<string>  $scopeCodes
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
