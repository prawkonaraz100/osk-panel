<?php

namespace App\Modules\CalendarTraining;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class TrainingSessionScopeAuthorizer
{
    public function __construct(private readonly TenantAuthorizer $tenantAuthorizer) {}

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function membership(string $sessionId): array
    {
        return $this->tenantAuthorizer->activeMembershipForSession($sessionId);
    }

    /**
     * @return array{
     *   membership:array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int},
     *   scopes:list<string>,
     *   staff_profile_id:?string,
     *   assigned_student_ids:list<string>
     * }
     */
    private function context(string $sessionId, string $permission): array
    {
        $membership = $this->membership($sessionId);
        $scopes = $this->tenantAuthorizer->grantedScopes($membership['id'], $permission);
        if ($scopes === []) {
            throw new AuthorizationException('Permission denied.');
        }

        $needsStaff = in_array('own', $scopes, true) || in_array('assigned_students', $scopes, true);
        $staffProfileId = $needsStaff ? $this->linkedStaffProfileId($membership) : null;
        $assignedStudentIds = in_array('assigned_students', $scopes, true) && $staffProfileId !== null
            ? $this->assignedStudentIds($membership['organization_id'], $staffProfileId)
            : [];

        return [
            'membership' => $membership,
            'scopes' => array_values($scopes),
            'staff_profile_id' => $staffProfileId,
            'assigned_student_ids' => $assignedStudentIds,
        ];
    }

    /**
     * @return array{
     *   membership:array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int},
     *   unrestricted:bool,
     *   own_instructor_id:?string
     * }
     */
    public function courseListAccess(string $sessionId, string $permission, string $courseId): array
    {
        $context = $this->context($sessionId, $permission);
        $course = $this->course($context['membership']['organization_id'], $courseId);

        if (in_array('organization', $context['scopes'], true)
            || (in_array('assigned_students', $context['scopes'], true)
                && in_array((string) $course->student_id, $context['assigned_student_ids'], true))) {
            return [
                'membership' => $context['membership'],
                'unrestricted' => true,
                'own_instructor_id' => null,
            ];
        }

        if (in_array('own', $context['scopes'], true) && $context['staff_profile_id'] !== null) {
            $ownsAny = DB::table('training_sessions')
                ->where('organization_id', $context['membership']['organization_id'])
                ->where('course_enrollment_id', $courseId)
                ->where('instructor_id', $context['staff_profile_id'])
                ->exists();
            if ($ownsAny) {
                return [
                    'membership' => $context['membership'],
                    'unrestricted' => false,
                    'own_instructor_id' => $context['staff_profile_id'],
                ];
            }
        }

        throw ResourceDomainException::notFound();
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireCourseForCreate(
        string $sessionId,
        string $permission,
        string $courseId,
        string $proposedInstructorId,
    ): array {
        $context = $this->context($sessionId, $permission);
        $course = $this->course($context['membership']['organization_id'], $courseId);

        if ($this->courseStudentScopeAllows($context, (string) $course->student_id)
            || (in_array('own', $context['scopes'], true)
                && $context['staff_profile_id'] !== null
                && hash_equals($context['staff_profile_id'], $proposedInstructorId))) {
            return $context['membership'];
        }

        throw ResourceDomainException::notFound();
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireSessionTarget(string $sessionId, string $permission, string $trainingSessionId): array
    {
        $context = $this->context($sessionId, $permission);
        $row = DB::table('training_sessions as ts')
            ->join('course_enrollments as c', function ($join): void {
                $join->on('c.id', '=', 'ts.course_enrollment_id')
                    ->on('c.organization_id', '=', 'ts.organization_id');
            })
            ->where('ts.organization_id', $context['membership']['organization_id'])
            ->where('ts.id', $trainingSessionId)
            ->select(['ts.instructor_id', 'c.student_id'])
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        if ($this->courseStudentScopeAllows($context, (string) $row->student_id)
            || (in_array('own', $context['scopes'], true)
                && $context['staff_profile_id'] !== null
                && hash_equals($context['staff_profile_id'], (string) $row->instructor_id))) {
            return $context['membership'];
        }

        throw ResourceDomainException::notFound();
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireCourseProjectionTarget(string $sessionId, string $permission, string $courseId): array
    {
        $context = $this->context($sessionId, $permission);
        $course = $this->course($context['membership']['organization_id'], $courseId);
        if ($this->courseStudentScopeAllows($context, (string) $course->student_id)) {
            return $context['membership'];
        }

        if (in_array('own', $context['scopes'], true) && $context['staff_profile_id'] !== null) {
            $ownsAny = DB::table('training_sessions')
                ->where('organization_id', $context['membership']['organization_id'])
                ->where('course_enrollment_id', $courseId)
                ->where('instructor_id', $context['staff_profile_id'])
                ->exists();
            if ($ownsAny) {
                return $context['membership'];
            }
        }

        throw ResourceDomainException::notFound();
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireCourseStudentTarget(string $sessionId, string $permission, string $courseId): array
    {
        $context = $this->context($sessionId, $permission);
        $course = $this->course($context['membership']['organization_id'], $courseId);
        if ($this->courseStudentScopeAllows($context, (string) $course->student_id)) {
            return $context['membership'];
        }

        throw ResourceDomainException::notFound();
    }

    /**
     * @param array{
     *   membership:array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int},
     *   scopes:list<string>,
     *   staff_profile_id:?string,
     *   assigned_student_ids:list<string>
     * } $context
     */
    private function courseStudentScopeAllows(array $context, string $studentId): bool
    {
        return in_array('organization', $context['scopes'], true)
            || (in_array('assigned_students', $context['scopes'], true)
                && in_array($studentId, $context['assigned_student_ids'], true));
    }

    private function course(string $organizationId, string $courseId): object
    {
        $course = DB::table('course_enrollments')
            ->where('organization_id', $organizationId)
            ->where('id', $courseId)
            ->first();
        if ($course === null) {
            throw ResourceDomainException::notFound();
        }

        return $course;
    }

    /**
     * @param array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} $membership
     */
    private function linkedStaffProfileId(array $membership): ?string
    {
        $value = DB::table('staff_membership_links as l')
            ->join('staff_profiles as s', function ($join): void {
                $join->on('s.id', '=', 'l.staff_profile_id')
                    ->on('s.organization_id', '=', 'l.organization_id');
            })
            ->where('l.organization_id', $membership['organization_id'])
            ->where('l.organization_membership_id', $membership['id'])
            ->whereNull('l.unlinked_at')
            ->whereNull('s.archived_at')
            ->value('l.staff_profile_id');

        return is_string($value) && $value !== '' ? $value : null;
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
            ->whereNull('c.cancelled_at')
            ->pluck('c.student_id')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        return array_values(array_unique([...$courseStudents, ...$sessionStudents]));
    }
}
