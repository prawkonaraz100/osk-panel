<?php

namespace App\Modules\CalendarTraining;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CalendarDrivingLessonService
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly CalendarEventScopeAuthorizer $scopeAuthorizer,
        private readonly TrainingSessionService $training,
    ) {}

    /**
     * @param  array{course_enrollment_id?:?string,student_id?:?string,name?:?string,starts_at:string,ends_at:string,instructor_id:string,vehicle_id?:?string,location_id?:?string,custom_meeting_place?:?string}  $input
     * @return array<string,mixed>
     */
    public function create(string $sessionId, array $input, string $requestId): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $input, $requestId): array {
            DB::table('organizations')->where('id', $snapshot['organization_id'])->lockForUpdate()->firstOrFail();
            $courseId = $this->resolveCourse(
                $snapshot['organization_id'],
                $this->nullableUuid($input['course_enrollment_id'] ?? null),
                $this->nullableUuid($input['student_id'] ?? null),
            );

            $session = $this->training->create(
                $sessionId,
                $courseId,
                [
                    'session_type' => 'practical',
                    'starts_at' => $input['starts_at'],
                    'ends_at' => $input['ends_at'],
                    'instructor_id' => $input['instructor_id'],
                    'vehicle_id' => $input['vehicle_id'] ?? null,
                    'location_id' => $input['location_id'] ?? null,
                    'display_name' => $input['name'] ?? null,
                    'custom_meeting_place' => $input['custom_meeting_place'] ?? null,
                ],
                $requestId,
            );

            return $this->projectionForOrganization($snapshot['organization_id'], (string) $session['id']);
        });
    }

    /**
     * @param  array{from?:?string,to?:?string,student_id?:?string,staff_id?:?string,vehicle_id?:?string,location_id?:?string,event_type?:list<string>}  $filters
     * @return list<array<string,mixed>>
     */
    public function list(string $sessionId, array $filters): array
    {
        if (isset($filters['event_type']) && ! in_array('driving_lesson', $filters['event_type'], true)) {
            return [];
        }

        $visibility = $this->scopeAuthorizer->visibility($sessionId);
        $query = DB::table('training_sessions as ts')
            ->join('course_enrollments as c', function ($join): void {
                $join->on('c.id', '=', 'ts.course_enrollment_id')
                    ->on('c.organization_id', '=', 'ts.organization_id');
            })
            ->where('ts.organization_id', $visibility['membership']['organization_id'])
            ->where('ts.session_type', 'practical')
            ->select(['ts.*', 'c.student_id as course_student_id']);

        $this->applyFilters($query, $filters);

        if (! $visibility['unrestricted']) {
            $query->where(function (Builder $scope) use ($visibility): void {
                $hasClause = false;
                if ($visibility['own_instructor_id'] !== null) {
                    $scope->where('ts.instructor_id', $visibility['own_instructor_id']);
                    $hasClause = true;
                }
                if ($visibility['assigned_student_ids'] !== []) {
                    $hasClause
                        ? $scope->orWhereIn('c.student_id', $visibility['assigned_student_ids'])
                        : $scope->whereIn('c.student_id', $visibility['assigned_student_ids']);
                    $hasClause = true;
                }
                if ($visibility['assigned_location_ids'] !== []) {
                    $hasClause
                        ? $scope->orWhereIn('ts.location_id', $visibility['assigned_location_ids'])
                        : $scope->whereIn('ts.location_id', $visibility['assigned_location_ids']);
                }
            });
        }

        return array_values($query
            ->orderBy('ts.starts_at')
            ->orderBy('ts.id')
            ->get()
            ->map(fn (\stdClass $row): array => $this->presentProjection($row))
            ->all());
    }

    /** @return array<string,mixed> */
    public function get(string $sessionId, string $trainingSessionId): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $row = $this->projectionRow($membership['organization_id'], $trainingSessionId);
        $this->scopeAuthorizer->requireTrainingProjectionTarget($sessionId, $row);

        return $this->presentProjection($row);
    }

    /** @param array<string,mixed> $projection */
    public function etag(array $projection): string
    {
        return '"v'.(int) $projection['version'].'"';
    }

    private function resolveCourse(string $organizationId, ?string $courseId, ?string $studentId): string
    {
        if ($courseId !== null) {
            $course = DB::table('course_enrollments')
                ->where('organization_id', $organizationId)
                ->where('id', $courseId)
                ->first();
            if ($course === null) {
                throw ResourceDomainException::notFound('CourseEnrollment not found.');
            }
            $this->assertActiveCourse($course);
            if ($studentId !== null && ! hash_equals((string) $course->student_id, $studentId)) {
                throw ResourceDomainException::rule('Selected Student does not match the explicit CourseEnrollment.');
            }

            return $courseId;
        }

        if ($studentId === null) {
            throw ResourceDomainException::rule('Formal driving lesson requires course_enrollment_id or student_id.');
        }

        $student = DB::table('students')
            ->where('organization_id', $organizationId)
            ->where('id', $studentId)
            ->lockForUpdate()
            ->first();
        if ($student === null || $student->archived_at !== null) {
            throw ResourceDomainException::notFound('Active Student is required.');
        }

        $courses = DB::table('course_enrollments')
            ->where('organization_id', $organizationId)
            ->where('student_id', $studentId)
            ->whereNull('completed_at')
            ->whereNull('interrupted_at')
            ->whereNull('cancelled_at')
            ->lockForUpdate()
            ->get();

        if ($courses->count() !== 1) {
            throw ResourceDomainException::rule(
                $courses->isEmpty()
                    ? 'Student has no eligible active CourseEnrollment for a formal driving lesson.'
                    : 'Student has multiple eligible active CourseEnrollments; explicit course_enrollment_id is required.',
            );
        }

        $course = $courses->first();
        if ($course === null) {
            throw ResourceDomainException::rule('Student has no eligible active CourseEnrollment for a formal driving lesson.');
        }

        return (string) $course->id;
    }

    private function assertActiveCourse(\stdClass $course): void
    {
        if ($course->completed_at !== null || $course->interrupted_at !== null || $course->cancelled_at !== null) {
            throw ResourceDomainException::conflict('Formal driving lesson requires an active CourseEnrollment.');
        }
    }

    /** @return array<string,mixed> */
    private function projectionForOrganization(string $organizationId, string $trainingSessionId): array
    {
        return $this->presentProjection($this->projectionRow($organizationId, $trainingSessionId));
    }

    private function projectionRow(string $organizationId, string $trainingSessionId): \stdClass
    {
        $row = DB::table('training_sessions as ts')
            ->join('course_enrollments as c', function ($join): void {
                $join->on('c.id', '=', 'ts.course_enrollment_id')
                    ->on('c.organization_id', '=', 'ts.organization_id');
            })
            ->where('ts.organization_id', $organizationId)
            ->where('ts.id', $trainingSessionId)
            ->where('ts.session_type', 'practical')
            ->select(['ts.*', 'c.student_id as course_student_id'])
            ->first();

        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $row;
    }

    /**
     * @param  array{from?:?string,to?:?string,student_id?:?string,staff_id?:?string,vehicle_id?:?string,location_id?:?string,event_type?:list<string>}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (($filters['from'] ?? null) !== null) {
            $query->where('ts.ends_at', '>', CarbonImmutable::parse((string) $filters['from'])->toIso8601String());
        }
        if (($filters['to'] ?? null) !== null) {
            $query->where('ts.starts_at', '<', CarbonImmutable::parse((string) $filters['to'])->toIso8601String());
        }
        foreach ([
            'student_id' => 'c.student_id',
            'staff_id' => 'ts.instructor_id',
            'vehicle_id' => 'ts.vehicle_id',
            'location_id' => 'ts.location_id',
        ] as $filter => $column) {
            if (($filters[$filter] ?? null) !== null) {
                $query->where($column, $filters[$filter]);
            }
        }
    }

    private function calendarDetails(string $organizationId, string $trainingSessionId): ?\stdClass
    {
        $rows = DB::table('training_session_calendar_details')
            ->where('organization_id', $organizationId)
            ->where('training_session_id', $trainingSessionId)
            ->limit(2)
            ->get();
        if ($rows->count() > 1) {
            throw ResourceDomainException::conflict('TrainingSession has ambiguous calendar metadata.');
        }

        return $rows->first();
    }

    /** @return array<string,mixed> */
    private function presentProjection(\stdClass $row): array
    {
        $details = $this->calendarDetails((string) $row->organization_id, (string) $row->id);

        return [
            'id' => (string) $row->id,
            'source_kind' => 'training_session',
            'source_id' => (string) $row->id,
            'course_enrollment_id' => (string) $row->course_enrollment_id,
            'event_type' => 'driving_lesson',
            'name' => $details === null ? null : $this->nullableText($details->display_name),
            'starts_at' => (string) $row->starts_at,
            'ends_at' => (string) $row->ends_at,
            'student_id' => (string) $row->course_student_id,
            'instructor_id' => (string) $row->instructor_id,
            'vehicle_id' => $this->nullableUuid($row->vehicle_id),
            'location_id' => $this->nullableUuid($row->location_id),
            'custom_meeting_place' => $details === null ? null : $this->nullableText($details->custom_meeting_place),
            'status' => (string) $row->status,
            'version' => (int) $row->version,
        ];
    }

    private function nullableUuid(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
