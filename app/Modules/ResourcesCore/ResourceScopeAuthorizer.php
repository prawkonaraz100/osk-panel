<?php

namespace App\Modules\ResourcesCore;

use App\Modules\IdentityTenant\TenantAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ResourceScopeAuthorizer
{
    public function __construct(private readonly TenantAuthorizer $tenantAuthorizer) {}

    /**
     * @return array{membership:array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int},unrestricted:bool,location_ids:list<string>}
     */
    public function visibility(string $sessionId, string $permission): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $scopes = $this->tenantAuthorizer->grantedScopes($membership['id'], $permission);

        if ($scopes === []) {
            throw new AuthorizationException('Permission denied.');
        }

        if (in_array('organization', $scopes, true)) {
            return ['membership' => $membership, 'unrestricted' => true, 'location_ids' => []];
        }

        if (! in_array('assigned_locations', $scopes, true)) {
            throw new AuthorizationException('Permission scope denied.');
        }

        $staffProfileId = DB::table('staff_membership_links as l')
            ->join('staff_profiles as s', function ($join): void {
                $join->on('s.id', '=', 'l.staff_profile_id')
                    ->on('s.organization_id', '=', 'l.organization_id');
            })
            ->where('l.organization_id', $membership['organization_id'])
            ->where('l.organization_membership_id', $membership['id'])
            ->whereNull('l.unlinked_at')
            ->whereNull('s.archived_at')
            ->value('l.staff_profile_id');

        if (! is_string($staffProfileId) || $staffProfileId === '') {
            return ['membership' => $membership, 'unrestricted' => false, 'location_ids' => []];
        }

        $locationIds = DB::table('staff_location_assignments')
            ->where('organization_id', $membership['organization_id'])
            ->where('staff_profile_id', $staffProfileId)
            ->pluck('location_id')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        return ['membership' => $membership, 'unrestricted' => false, 'location_ids' => array_values($locationIds)];
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireLocationTarget(string $sessionId, string $permission, string $locationId): array
    {
        $visibility = $this->visibility($sessionId, $permission);
        if (! $visibility['unrestricted'] && ! in_array($locationId, $visibility['location_ids'], true)) {
            throw ResourceDomainException::notFound();
        }

        return $visibility['membership'];
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireStaffTarget(string $sessionId, string $permission, string $staffProfileId): array
    {
        $visibility = $this->visibility($sessionId, $permission);
        if ($visibility['unrestricted']) {
            return $visibility['membership'];
        }

        $visible = $visibility['location_ids'] !== [] && DB::table('staff_location_assignments')
            ->where('organization_id', $visibility['membership']['organization_id'])
            ->where('staff_profile_id', $staffProfileId)
            ->whereIn('location_id', $visibility['location_ids'])
            ->exists();

        if (! $visible) {
            throw ResourceDomainException::notFound();
        }

        return $visibility['membership'];
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireVehicleTarget(string $sessionId, string $permission, string $vehicleId): array
    {
        $visibility = $this->visibility($sessionId, $permission);
        if ($visibility['unrestricted']) {
            return $visibility['membership'];
        }

        $visible = $visibility['location_ids'] !== [] && DB::table('vehicle_location_assignments')
            ->where('organization_id', $visibility['membership']['organization_id'])
            ->where('vehicle_id', $vehicleId)
            ->whereIn('location_id', $visibility['location_ids'])
            ->exists();

        if (! $visible) {
            throw ResourceDomainException::notFound();
        }

        return $visibility['membership'];
    }
}
