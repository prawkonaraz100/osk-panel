<?php

namespace App\Modules\CalendarTraining;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\StudentsCourses\StudentCourseScopeAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class AvailabilityScopeAuthorizer
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly CalendarEventScopeAuthorizer $calendarScope,
        private readonly StudentCourseScopeAuthorizer $studentScope,
    ) {}

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
        return $this->calendarScope->visibility($sessionId);
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function membership(string $sessionId): array
    {
        return $this->tenantAuthorizer->activeMembershipForSession($sessionId);
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireCreate(string $sessionId, ?string $proposedInstructorId): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $scopes = $this->tenantAuthorizer->grantedScopes($membership['id'], 'calendar.publish_student_slots');
        if (in_array('organization', $scopes, true)) {
            return $membership;
        }

        if (in_array('own', $scopes, true)) {
            $staffProfileId = $this->linkedStaffProfileId($membership);
            if ($staffProfileId !== null
                && $proposedInstructorId !== null
                && hash_equals($staffProfileId, $proposedInstructorId)) {
                return $membership;
            }
        }

        throw new AuthorizationException('Availability publication scope denied.');
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireTarget(
        string $sessionId,
        \stdClass $slot,
        ?string $proposedFinalInstructorId,
    ): array {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        if ($slot->organization_id !== $membership['organization_id']) {
            throw ResourceDomainException::notFound();
        }

        $scopes = $this->tenantAuthorizer->grantedScopes($membership['id'], 'calendar.publish_student_slots');
        if (in_array('organization', $scopes, true)) {
            return $membership;
        }

        if (in_array('own', $scopes, true)) {
            $staffProfileId = $this->linkedStaffProfileId($membership);
            if ($staffProfileId !== null
                && $slot->instructor_id !== null
                && hash_equals($staffProfileId, (string) $slot->instructor_id)
                && $proposedFinalInstructorId !== null
                && hash_equals($staffProfileId, $proposedFinalInstructorId)) {
                return $membership;
            }
        }

        throw ResourceDomainException::notFound();
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireBookingStudent(string $sessionId, string $studentId): array
    {
        return $this->studentScope->requireStudentTarget($sessionId, 'calendar.book_for_student', $studentId);
    }

    /**
     * @param  array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int}  $membership
     */
    private function linkedStaffProfileId(array $membership): ?string
    {
        $row = DB::table('staff_membership_links as l')
            ->join('staff_profiles as s', function ($join): void {
                $join->on('s.id', '=', 'l.staff_profile_id')
                    ->on('s.organization_id', '=', 'l.organization_id');
            })
            ->where('l.organization_id', $membership['organization_id'])
            ->where('l.organization_membership_id', $membership['id'])
            ->whereNull('l.unlinked_at')
            ->whereNull('s.archived_at')
            ->select('l.staff_profile_id')
            ->sharedLock()
            ->first();

        return $row === null ? null : (string) $row->staff_profile_id;
    }
}
