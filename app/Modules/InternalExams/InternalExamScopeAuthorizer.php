<?php

namespace App\Modules\InternalExams;

use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\StudentsCourses\StudentCourseScopeAuthorizer;
use Illuminate\Support\Facades\DB;

final class InternalExamScopeAuthorizer
{
    public function __construct(private readonly StudentCourseScopeAuthorizer $courses) {}

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireCourse(string $sessionId, string $permission, string $courseId): array
    {
        return $this->courses->requireCourseTarget($sessionId, $permission, $courseId);
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireAttempt(string $sessionId, string $permission, string $attemptId): array
    {
        $attempt = DB::table('internal_exam_attempts')->where('id', $attemptId)->first();
        if ($attempt === null) {
            throw ResourceDomainException::notFound();
        }

        return $this->courses->requireStudentTarget($sessionId, $permission, (string) $attempt->student_id);
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    public function requireAccess(string $sessionId, string $permission, string $accessId): array
    {
        $row = DB::table('internal_exam_accesses as a')
            ->join('internal_exam_attempts as e', 'e.id', '=', 'a.internal_exam_attempt_id')
            ->where('a.id', $accessId)
            ->select(['e.student_id'])
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $this->courses->requireStudentTarget($sessionId, $permission, (string) $row->student_id);
    }
}
