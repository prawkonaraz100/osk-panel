<?php

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
