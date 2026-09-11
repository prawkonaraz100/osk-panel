<?php

namespace App\Modules\StudentsCourses;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceIdempotency;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StudentCourseController
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ResourceIdempotency $idempotency,
        private readonly StudentService $students,
        private readonly CourseEnrollmentService $courses,
    ) {}

    public function studentsList(Request $request): JsonResponse
    {
        $categories = $this->queryList($request, 'category');
        $stages = $this->queryList($request, 'training_stage');

        return response()->json($this->students->list(
            $this->sessionId($request),
            $this->page($request),
            $this->perPage($request),
            $this->nullableQuery($request, 'q'),
            $this->nullableQuery($request, 'sort'),
            (string) $request->query('direction', 'asc'),
            $categories,
            $stages,
            filter_var($request->query('internal_exam_not_passed', false), FILTER_VALIDATE_BOOL),
            filter_var($request->query('include_archived', false), FILTER_VALIDATE_BOOL),
        ));
    }

    public function studentsCreate(Request $request): JsonResponse
    {
        $input = $this->validated($request, $this->studentRules(true));
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'students.create',
            $this->idempotencyKey($request),
            $input,
            function () use ($sessionId, $input, $request): array {
                $body = $this->students->create($sessionId, $input, $this->requestId($request));

                return ['status' => 201, 'resource_type' => 'student', 'resource_id' => (string) $body['id'], 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->students->etag($result['body']));
    }

    public function studentsGet(Request $request, string $studentId): JsonResponse
    {
        $body = $this->students->get($this->sessionId($request), $studentId);

        return response()->json($body)->header('ETag', $this->students->etag($body));
    }

    public function studentsPreview(Request $request, string $studentId): JsonResponse
    {
        return response()->json($this->students->preview($this->sessionId($request), $studentId));
    }

    public function studentsUpdate(Request $request, string $studentId): JsonResponse
    {
        $body = $this->students->update(
            $this->sessionId($request),
            $studentId,
            $this->validated($request, $this->studentRules(false)),
            $this->requestId($request),
            $request->header('If-Match'),
        );

        return response()->json($body)->header('ETag', $this->students->etag($body));
    }

    public function studentsArchive(Request $request, string $studentId): JsonResponse
    {
        $input = $this->validated($request, ['reason' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'students.archive',
            $this->idempotencyKey($request),
            ['student_id' => $studentId, ...$input],
            function () use ($sessionId, $studentId, $input, $request): array {
                $body = $this->students->archive(
                    $sessionId,
                    $studentId,
                    $this->requestId($request),
                    $input['reason'] ?? null,
                );

                return ['status' => 200, 'resource_type' => 'student', 'resource_id' => $studentId, 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->students->etag($result['body']));
    }

    public function studentsRestore(Request $request, string $studentId): JsonResponse
    {
        $this->validated($request, []);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'students.restore',
            $this->idempotencyKey($request),
            ['student_id' => $studentId],
            function () use ($sessionId, $studentId, $request): array {
                $body = $this->students->restore($sessionId, $studentId, $this->requestId($request));

                return ['status' => 200, 'resource_type' => 'student', 'resource_id' => $studentId, 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->students->etag($result['body']));
    }

    public function coursesList(Request $request, string $studentId): JsonResponse
    {
        return response()->json($this->courses->listForStudent($this->sessionId($request), $studentId));
    }

    public function coursesCreate(Request $request, string $studentId): JsonResponse
    {
        $input = $this->validated($request, $this->courseRules(true));
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'course_enrollments.create',
            $this->idempotencyKey($request),
            ['student_id' => $studentId, ...$input],
            function () use ($sessionId, $studentId, $input, $request): array {
                $body = $this->courses->create($sessionId, $studentId, $input, $this->requestId($request));

                return ['status' => 201, 'resource_type' => 'course_enrollment', 'resource_id' => (string) $body['id'], 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->courses->etag($result['body']));
    }

    public function coursesGet(Request $request, string $courseEnrollmentId): JsonResponse
    {
        $body = $this->courses->get($this->sessionId($request), $courseEnrollmentId);

        return response()->json($body)->header('ETag', $this->courses->etag($body));
    }

    public function coursesUpdate(Request $request, string $courseEnrollmentId): JsonResponse
    {
        $body = $this->courses->update(
            $this->sessionId($request),
            $courseEnrollmentId,
            $this->validated($request, $this->courseRules(false)),
            $this->requestId($request),
            $request->header('If-Match'),
        );

        return response()->json($body)->header('ETag', $this->courses->etag($body));
    }

    public function coursesCancel(Request $request, string $courseEnrollmentId): JsonResponse
    {
        $input = $this->validated($request, ['reason' => ['required', 'string', 'max:1000']]);

        return $this->courseIdempotent(
            $request,
            $courseEnrollmentId,
            'course_enrollments.cancel',
            ['reason' => $input['reason']],
            fn (string $sessionId): array => $this->courses->cancel(
                $sessionId,
                $courseEnrollmentId,
                (string) $input['reason'],
                $this->requestId($request),
                $request->header('If-Match'),
            ),
        );
    }

    public function coursesRestore(Request $request, string $courseEnrollmentId): JsonResponse
    {
        $this->validated($request, []);

        return $this->courseIdempotent(
            $request,
            $courseEnrollmentId,
            'course_enrollments.restore',
            [],
            fn (string $sessionId): array => $this->courses->restore(
                $sessionId,
                $courseEnrollmentId,
                $this->requestId($request),
                $request->header('If-Match'),
            ),
        );
    }

    public function coursesChangeStage(Request $request, string $courseEnrollmentId): JsonResponse
    {
        $input = $this->validated($request, [
            'target_stage' => ['required', 'string', 'max:64'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        return $this->courseIdempotent(
            $request,
            $courseEnrollmentId,
            'course_enrollments.stage_transition',
            $input,
            fn (string $sessionId): array => $this->courses->changeStage(
                $sessionId,
                $courseEnrollmentId,
                (string) $input['target_stage'],
                $input['reason'] ?? null,
                $this->requestId($request),
                $request->header('If-Match'),
            ),
        );
    }

    public function courseRequirementsGet(Request $request, string $courseEnrollmentId): JsonResponse
    {
        return response()->json($this->courses->currentRequirements($this->sessionId($request), $courseEnrollmentId));
    }

    public function courseRequirementsUpdateContext(Request $request, string $courseEnrollmentId): JsonResponse
    {
        $input = $this->validated($request, [
            'state_theory_passed' => ['sometimes', 'boolean'],
            'evidence_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'held_categories' => ['sometimes', 'array'],
            'held_categories.*' => ['string', 'max:16'],
        ]);

        return $this->courseIdempotent(
            $request,
            $courseEnrollmentId,
            'course_requirements.update_context',
            $input,
            fn (string $sessionId): array => $this->courses->updateRequirementContext(
                $sessionId,
                $courseEnrollmentId,
                $input,
                $this->requestId($request),
                $request->header('If-Match'),
            ),
            'training_requirement_profile',
        );
    }

    public function courseRequirementsAddExemptionDecision(Request $request, string $courseEnrollmentId): JsonResponse
    {
        $input = $this->validated($request, [
            'basis_code' => ['required', 'string', 'max:128'],
            'evidence_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->courseIdempotent(
            $request,
            $courseEnrollmentId,
            'course_requirements.exemption_decision',
            $input,
            fn (string $sessionId): array => $this->courses->addExemptionDecision(
                $sessionId,
                $courseEnrollmentId,
                (string) $input['basis_code'],
                $input['evidence_reference'] ?? null,
                (string) $input['reason'],
                $this->requestId($request),
                $request->header('If-Match'),
            ),
            'training_requirement_profile',
            201,
        );
    }

    public function externalTrainingList(Request $request, string $courseEnrollmentId): JsonResponse
    {
        return response()->json($this->courses->listExternalTraining($this->sessionId($request), $courseEnrollmentId));
    }

    public function externalTrainingCreate(Request $request, string $courseEnrollmentId): JsonResponse
    {
        $input = $this->validated($request, [
            'training_part' => ['required', 'string', 'in:theory,practical'],
            'recognized_minutes' => ['required', 'integer', 'min:1'],
            'source_school_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'evidence_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->courseIdempotent(
            $request,
            $courseEnrollmentId,
            'external_training.create',
            $input,
            fn (string $sessionId): array => $this->courses->recognizeExternalTraining(
                $sessionId,
                $courseEnrollmentId,
                (string) $input['training_part'],
                (int) $input['recognized_minutes'],
                $input['source_school_reference'] ?? null,
                $input['evidence_reference'] ?? null,
                (string) $input['reason'],
                $this->requestId($request),
                $request->header('If-Match'),
            ),
            'recognized_external_training',
            201,
        );
    }

    public function externalTrainingRevoke(Request $request, string $courseEnrollmentId, string $recordId): JsonResponse
    {
        $input = $this->validated($request, ['reason' => ['required', 'string', 'max:1000']]);

        return $this->courseIdempotent(
            $request,
            $courseEnrollmentId,
            'external_training.revoke',
            ['record_id' => $recordId, ...$input],
            fn (string $sessionId): array => $this->courses->revokeExternalTraining(
                $sessionId,
                $courseEnrollmentId,
                $recordId,
                (string) $input['reason'],
                $this->requestId($request),
                $request->header('If-Match'),
            ),
            'recognized_external_training',
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @param callable(string):array<string,mixed> $callback
     */
    private function courseIdempotent(
        Request $request,
        string $courseId,
        string $operationKey,
        array $payload,
        callable $callback,
        string $resourceType = 'course_enrollment',
        int $status = 200,
    ): JsonResponse {
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];
        $idempotencyPayload = [
            'course_enrollment_id' => $courseId,
            'if_match' => $request->header('If-Match'),
            ...$payload,
        ];

        $result = $this->idempotency->execute(
            $organizationId,
            $operationKey,
            $this->idempotencyKey($request),
            $idempotencyPayload,
            function () use ($sessionId, $callback, $resourceType, $courseId, $status): array {
                $body = $callback($sessionId);

                return [
                    'status' => $status,
                    'resource_type' => $resourceType,
                    'resource_id' => (string) ($body['id'] ?? $courseId),
                    'body' => $body,
                ];
            },
        );

        $response = response()->json($result['body'], $result['status']);
        if ($resourceType === 'course_enrollment' && isset($result['body']['version'])) {
            $response->headers->set('ETag', $this->courses->etag($result['body']));
        }

        return $response;
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, array $rules): array
    {
        $input = $request->all();
        $allowedRoots = array_values(array_unique(array_map(
            static function (string $key): string {
                $dot = strpos($key, '.');

                return $dot === false ? $key : substr($key, 0, $dot);
            },
            array_keys($rules),
        )));
        $unknown = array_values(array_diff(array_keys($input), $allowedRoots));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'request' => ['Unknown fields: '.implode(', ', $unknown)],
            ]);
        }

        return Validator::make($input, $rules)->validate();
    }

    /** @return array<string,array<int,string>> */
    private function studentRules(bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';

        return [
            'first_name' => [$required, 'string', 'max:120'],
            'last_name' => [$required, 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:320'],
            'pesel' => ['sometimes', 'nullable', 'string', 'max:32'],
            'no_pesel' => ['sometimes', 'boolean'],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'initial_course' => ['sometimes', 'nullable', 'array'],
            'initial_course.training_type' => ['required_with:initial_course', 'string', 'in:basic,supplementary'],
            'initial_course.driving_category_code' => ['required_with:initial_course', 'string', 'max:16'],
            'initial_course.pkk_number' => ['required_with:initial_course', 'string', 'max:128'],
            'initial_course.started_at' => ['required_with:initial_course', 'date'],
            'initial_course.initial_cost' => ['sometimes', 'nullable', 'array'],
            'initial_course.declared_theory_minutes' => ['sometimes', 'integer', 'min:0'],
            'initial_course.declared_practical_minutes' => ['sometimes', 'integer', 'min:0'],
            'initial_course.recognized_external_theory_minutes' => ['sometimes', 'integer', 'min:0'],
            'initial_course.recognized_external_practical_minutes' => ['sometimes', 'integer', 'min:0'],
            'initial_course.lead_instructor_id' => ['required_with:initial_course', 'uuid'],
            'initial_course.location_id' => ['sometimes', 'nullable', 'uuid'],
            'initial_course.import_existing_current_osk_hours' => ['sometimes', 'boolean'],
            'initial_license' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /** @return array<string,array<int,string>> */
    private function courseRules(bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';

        return [
            'training_type' => [$required, 'string', 'in:basic,supplementary'],
            'driving_category_code' => [$required, 'string', 'max:16'],
            'pkk_number' => [$required, 'string', 'max:128'],
            'started_at' => [$required, 'date'],
            'initial_cost' => ['sometimes', 'nullable', 'array'],
            'declared_theory_minutes' => ['sometimes', 'integer', 'min:0'],
            'declared_practical_minutes' => ['sometimes', 'integer', 'min:0'],
            'recognized_external_theory_minutes' => $create ? ['sometimes', 'integer', 'min:0'] : ['prohibited'],
            'recognized_external_practical_minutes' => $create ? ['sometimes', 'integer', 'min:0'] : ['prohibited'],
            'lead_instructor_id' => [$required, 'uuid'],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'import_existing_current_osk_hours' => $create ? ['sometimes', 'boolean'] : ['prohibited'],
        ];
    }

    private function sessionId(Request $request): string
    {
        $sessionId = $request->session()->get('auth_session_id');
        if (! is_string($sessionId) || $sessionId === '') {
            throw new AuthenticationException('Authenticated application session required.');
        }

        return $sessionId;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || ! Str::isUuid($key)) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['A UUID Idempotency-Key header is required.'],
            ]);
        }

        return $key;
    }

    private function requestId(Request $request): string
    {
        return (string) $request->attributes->get('request_id');
    }

    private function page(Request $request): int
    {
        return max(1, (int) $request->query('page', 1));
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->query('per_page', 25)));
    }

    private function nullableQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return list<string> */
    private function queryList(Request $request, string $key): array
    {
        $value = $request->query($key, []);
        if (is_string($value)) {
            $value = [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
