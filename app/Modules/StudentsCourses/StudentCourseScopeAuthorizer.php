<?php

namespace App\Modules\StudentsCourses;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StudentCourseScopeAuthorizer
{
    public function __construct(private readonly TenantAuthorizer $tenantAuthorizer) {}

    /**
     * @return array{membership:array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int},unrestricted:bool,student_ids:list<string>}
     */
    public function visibility(string $sessionId, string $permission): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $scopes = $this->tenantAuthorizer->grantedScopes($membership['id'], $permission);

        if ($scopes === []) {
            throw new AuthorizationException('Permission denied.');
        }

        if (in_array('organization', $scopes, true)) {
            return ['membership' => $membership, 'unrestricted' => true, 'student_ids' => []];
        }

        if (! in_array('assigned_students', $scopes, true)) {
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
            return ['membership' => $membership, 'unrestricted' => false, 'student_ids' => []];
        }

        $studentIds = DB::table('course_enrollments')
            ->where('organization_id', $membership['organization_id'])
            ->where('lead_instructor_id', $staffProfileId)
            ->whereNull('cancelled_at')
            ->pluck('student_id')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        if (Schema::hasTable('training_sessions')) {
            $fromSessions = DB::table('training_sessions as ts')
                ->join('course_enrollments as c', function ($join): void {
                    $join->on('c.id', '=', 'ts.course_enrollment_id')
                        ->on('c.organization_id', '=', 'ts.organization_id');
                })
                ->where('ts.organization_id', $membership['organization_id'])
                ->where('ts.instructor_id', $staffProfileId)
                ->whereNull('c.cancelled_at')
                ->pluck('c.student_id')
                ->map(static fn ($value): string => (string) $value)
                ->all();
            $studentIds = [...$studentIds, ...$fromSessions];
        }

        return [
            'membership' => $membership,
            'unrestricted' => false,
            'student_ids' => array_values(array_unique($studentIds)),
        ];
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireStudentTarget(string $sessionId, string $permission, string $studentId): array
    {
        $visibility = $this->visibility($sessionId, $permission);
        if (! $visibility['unrestricted'] && ! in_array($studentId, $visibility['student_ids'], true)) {
            throw ResourceDomainException::notFound();
        }

        $exists = DB::table('students')
            ->where('organization_id', $visibility['membership']['organization_id'])
            ->where('id', $studentId)
            ->exists();
        if (! $exists) {
            throw ResourceDomainException::notFound();
        }

        return $visibility['membership'];
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireCourseTarget(string $sessionId, string $permission, string $courseId): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $course = DB::table('course_enrollments')
            ->where('organization_id', $membership['organization_id'])
            ->where('id', $courseId)
            ->first();
        if ($course === null) {
            throw ResourceDomainException::notFound();
        }

        return $this->requireStudentTarget($sessionId, $permission, (string) $course->student_id);
    }
}
