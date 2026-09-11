<?php

namespace App\Modules\CalendarTraining;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceIdempotency;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CalendarDrivingLessonController
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ResourceIdempotency $idempotency,
        private readonly CalendarDrivingLessonService $drivingLessons,
    ) {}

    public function create(Request $request): JsonResponse
    {
        /** @var array{course_enrollment_id?:?string,student_id?:?string,name?:?string,starts_at:string,ends_at:string,instructor_id:string,vehicle_id?:?string,location_id?:?string,custom_meeting_place?:?string} $input */
        $input = $this->validated($request, [
            'course_enrollment_id' => ['sometimes', 'nullable', 'uuid'],
            'student_id' => ['sometimes', 'nullable', 'uuid'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'instructor_id' => ['required', 'uuid'],
            'vehicle_id' => ['sometimes', 'nullable', 'uuid'],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'custom_meeting_place' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'calendar.driving_lessons.create',
            $this->idempotencyKey($request),
            $input,
            function () use ($sessionId, $input, $request): array {
                $body = $this->drivingLessons->create($sessionId, $input, $this->requestId($request));

                return [
                    'status' => 201,
                    'resource_type' => 'training_session',
                    'resource_id' => (string) $body['source_id'],
                    'body' => $body,
                ];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->drivingLessons->etag($result['body']));
    }

    public function get(Request $request, string $sessionId): JsonResponse
    {
        $body = $this->drivingLessons->get($this->sessionId($request), $sessionId);

        return response()->json($body)->header('ETag', $this->drivingLessons->etag($body));
    }

    /**
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $input = $request->all();
        $unknown = array_values(array_diff(array_keys($input), array_keys($rules)));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['request' => ['Unknown fields: '.implode(', ', $unknown)]]);
        }

        return Validator::make($input, $rules)->validate();
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
}
