<?php

namespace App\Modules\CalendarTraining;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class CalendarEventScopeAuthorizer
{
    public function __construct(private readonly TenantAuthorizer $tenantAuthorizer) {}

    /**
     * @return array{
     *   membership:array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int},
     *   unrestricted:bool,
     *   own_instructor_id:?string,
     *   assigned_student_ids:list<string>,
     *   assigned_location_ids:list<string>
     * }
     */
    public function visibility(string $sessionId): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $scopes = $this->tenantAuthorizer->grantedScopes($membership['id'], 'calendar.view');
        if ($scopes === []) {
            throw new AuthorizationException('Permission denied.');
        }

        $staffProfileId = $this->linkedStaffProfileId($membership);
        $assignedStudentIds = in_array('assigned_students', $scopes, true) && $staffProfileId !== null
            ? $this->assignedStudentIds($membership['organization_id'], $staffProfileId)
            : [];
        $assignedLocationIds = in_array('assigned_locations', $scopes, true) && $staffProfileId !== null
            ? $this->assignedLocationIds($membership['organization_id'], $staffProfileId)
            : [];

        $unrestricted = in_array('organization', $scopes, true);
        $ownInstructorId = in_array('own', $scopes, true) ? $staffProfileId : null;
        if (! $unrestricted && $ownInstructorId === null && $assignedStudentIds === [] && $assignedLocationIds === []) {
            throw new AuthorizationException('Permission scope denied.');
        }

        return [
            'membership' => $membership,
            'unrestricted' => $unrestricted,
            'own_instructor_id' => $ownInstructorId,
            'assigned_student_ids' => $assignedStudentIds,
            'assigned_location_ids' => $assignedLocationIds,
        ];
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireViewTarget(string $sessionId, \stdClass $event): array
    {
        $visibility = $this->visibility($sessionId);
        if ($event->organization_id !== $visibility['membership']['organization_id']) {
            throw ResourceDomainException::notFound();
        }

        if ($visibility['unrestricted']
            || ($visibility['own_instructor_id'] !== null && $event->instructor_id === $visibility['own_instructor_id'])
            || ($event->student_id !== null && in_array((string) $event->student_id, $visibility['assigned_student_ids'], true))
            || ($event->location_id !== null && in_array((string) $event->location_id, $visibility['assigned_location_ids'], true))) {
            return $visibility['membership'];
        }

        throw ResourceDomainException::notFound();
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireTrainingProjectionTarget(string $sessionId, \stdClass $projection): array
    {
        $visibility = $this->visibility($sessionId);
        if ($projection->organization_id !== $visibility['membership']['organization_id']) {
            throw ResourceDomainException::notFound();
        }

        if ($visibility['unrestricted']
            || ($visibility['own_instructor_id'] !== null && $projection->instructor_id === $visibility['own_instructor_id'])
            || ($projection->course_student_id !== null && in_array((string) $projection->course_student_id, $visibility['assigned_student_ids'], true))
            || ($projection->location_id !== null && in_array((string) $projection->location_id, $visibility['assigned_location_ids'], true))) {
            return $visibility['membership'];
        }

        throw ResourceDomainException::notFound();
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireCreate(string $sessionId, ?string $proposedInstructorId): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        if ($this->hasOrganizationManage($membership)) {
            return $membership;
        }

        $staffProfileId = $this->ownManageStaffProfileId($membership);
        if ($staffProfileId !== null && $proposedInstructorId !== null && hash_equals($staffProfileId, $proposedInstructorId)) {
            return $membership;
        }

        throw new AuthorizationException('Calendar create scope denied.');
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireManageTarget(string $sessionId, \stdClass $event, ?string $proposedFinalInstructorId): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        if ($event->organization_id !== $membership['organization_id']) {
            throw ResourceDomainException::notFound();
        }

        if ($this->hasOrganizationManage($membership)) {
            return $membership;
        }

        $staffProfileId = $this->ownManageStaffProfileId($membership);
        if ($staffProfileId !== null
            && $event->instructor_id !== null
            && hash_equals($staffProfileId, (string) $event->instructor_id)
            && $proposedFinalInstructorId !== null
            && hash_equals($staffProfileId, $proposedFinalInstructorId)) {
            return $membership;
        }

        throw ResourceDomainException::notFound();
    }

    /**
     * @param  array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int}  $membership
     */
    private function hasOrganizationManage(array $membership): bool
    {
        return in_array(
            'organization',
            $this->tenantAuthorizer->grantedScopes($membership['id'], 'calendar.manage.organization'),
            true,
        );
    }

    /**
     * @param  array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int}  $membership
     */
    private function ownManageStaffProfileId(array $membership): ?string
    {
        if (! in_array('own', $this->tenantAuthorizer->grantedScopes($membership['id'], 'calendar.manage.own'), true)) {
            return null;
        }

        return $this->linkedStaffProfileId($membership, true);
    }

    /**
     * @param  array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int}  $membership
     */
    private function linkedStaffProfileId(array $membership, bool $sharedLock = false): ?string
    {
        $query = DB::table('staff_membership_links as l')
            ->join('staff_profiles as s', function ($join): void {
                $join->on('s.id', '=', 'l.staff_profile_id')
                    ->on('s.organization_id', '=', 'l.organization_id');
            })
            ->where('l.organization_id', $membership['organization_id'])
            ->where('l.organization_membership_id', $membership['id'])
            ->whereNull('l.unlinked_at')
            ->whereNull('s.archived_at')
            ->select('l.staff_profile_id');

        if ($sharedLock && DB::transactionLevel() > 0) {
            $query->sharedLock();
        }

        $row = $query->first();

        return $row === null ? null : (string) $row->staff_profile_id;
    }

    /** @return list<string> */
    private function assignedStudentIds(string $organizationId, string $staffProfileId): array
    {
        $courseStudents = DB::table('course_enrollments')
            ->where('organization_id', $organizationId)
            ->where('lead_instructor_id', $staffProfileId)
            ->whereNull('cancelled_at')
            ->pluck('student_id')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        $sessionStudents = DB::table('training_sessions as ts')
            ->join('course_enrollments as c', function ($join): void {
                $join->on('c.id', '=', 'ts.course_enrollment_id')
                    ->on('c.organization_id', '=', 'ts.organization_id');
            })
            ->where('ts.organization_id', $organizationId)
            ->where('ts.instructor_id', $staffProfileId)
            ->where('ts.status', '!=', 'cancelled')
            ->pluck('c.student_id')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        return array_values(array_unique([...$courseStudents, ...$sessionStudents]));
    }

    /** @return list<string> */
    private function assignedLocationIds(string $organizationId, string $staffProfileId): array
    {
        return array_values(DB::table('staff_location_assignments')
            ->where('organization_id', $organizationId)
            ->where('staff_profile_id', $staffProfileId)
            ->pluck('location_id')
            ->map(static fn ($value): string => (string) $value)
            ->all());
    }
}
