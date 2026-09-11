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

final class TrainingSessionController
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ResourceIdempotency $idempotency,
        private readonly TrainingSessionService $training,
    ) {}

    public function list(Request $request, string $courseEnrollmentId): JsonResponse
    {
        return response()->json($this->training->listForCourse($this->sessionId($request), $courseEnrollmentId));
    }

    public function create(Request $request, string $courseEnrollmentId): JsonResponse
    {
        /** @var array{session_type:string,starts_at:string,ends_at:string,instructor_id:string,vehicle_id?:?string,location_id?:?string} $input */
        $input = $this->validated($request, $this->sessionRules(true));
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'training_sessions.create',
            $this->idempotencyKey($request),
            ['course_enrollment_id' => $courseEnrollmentId, ...$input],
            function () use ($sessionId, $courseEnrollmentId, $input, $request): array {
                $body = $this->training->create($sessionId, $courseEnrollmentId, $input, $this->requestId($request));

                return ['status' => 201, 'resource_type' => 'training_session', 'resource_id' => (string) $body['id'], 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->training->etag($result['body']));
    }

    public function get(Request $request, string $sessionId): JsonResponse
    {
        $body = $this->training->get($this->sessionId($request), $sessionId);

        return response()->json($body)->header('ETag', $this->training->etag($body));
    }

    public function update(Request $request, string $sessionId): JsonResponse
    {
        $body = $this->training->update(
            $this->sessionId($request),
            $sessionId,
            $this->validated($request, $this->sessionRules(false)),
            $this->requestId($request),
            $request->header('If-Match'),
        );

        return response()->json($body)->header('ETag', $this->training->etag($body));
    }

    public function attendance(Request $request, string $sessionId): JsonResponse
    {
        $input = $this->validated($request, ['status' => ['required', 'string', 'in:present,absent']]);
        $body = $this->training->recordAttendance(
            $this->sessionId($request),
            $sessionId,
            (string) $input['status'],
            $this->requestId($request),
            $request->header('If-Match'),
        );

        return response()->json($body)->header('ETag', '"v'.(int) $body['session_version'].'"');
    }

    public function complete(Request $request, string $sessionId): JsonResponse
    {
        $this->validated($request, []);

        return $this->sessionCommand(
            $request,
            $sessionId,
            'training_sessions.complete',
            [],
            fn (string $authSessionId): array => $this->training->complete($authSessionId, $sessionId, $this->requestId($request)),
        );
    }

    public function cancel(Request $request, string $sessionId): JsonResponse
    {
        $input = $this->validated($request, ['reason' => ['required', 'string', 'max:1000']]);

        return $this->sessionCommand(
            $request,
            $sessionId,
            'training_sessions.cancel',
            ['reason' => (string) $input['reason']],
            fn (string $authSessionId): array => $this->training->cancel(
                $authSessionId,
                $sessionId,
                (string) $input['reason'],
                $this->requestId($request),
            ),
        );
    }

    public function hours(Request $request, string $courseEnrollmentId): JsonResponse
    {
        return response()->json($this->training->hours($this->sessionId($request), $courseEnrollmentId));
    }

    public function correctHours(Request $request, string $courseEnrollmentId): JsonResponse
    {
        $input = $this->validated($request, [
            'training_part' => ['required', 'string', 'in:theory,practical'],
            'minutes' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:1000'],
            'source_entry_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $authSessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($authSessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'training_hours.correct',
            $this->idempotencyKey($request),
            [
                'course_enrollment_id' => $courseEnrollmentId,
                'if_match' => $request->header('If-Match'),
                ...$input,
            ],
            function () use ($authSessionId, $courseEnrollmentId, $input, $request): array {
                $body = $this->training->correctHours(
                    $authSessionId,
                    $courseEnrollmentId,
                    (string) $input['training_part'],
                    (int) $input['minutes'],
                    (string) $input['reason'],
                    isset($input['source_entry_id']) ? (string) $input['source_entry_id'] : null,
                    $this->requestId($request),
                    $request->header('If-Match'),
                );

                return ['status' => 201, 'resource_type' => 'training_hour_ledger_entry', 'resource_id' => (string) $body['id'], 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->training->courseEtag((int) $result['body']['course_version']));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  callable(string):array<string,mixed>  $callback
     */
    private function sessionCommand(
        Request $request,
        string $trainingSessionId,
        string $operationKey,
        array $payload,
        callable $callback,
    ): JsonResponse {
        $authSessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($authSessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            $operationKey,
            $this->idempotencyKey($request),
            ['training_session_id' => $trainingSessionId, ...$payload],
            function () use ($authSessionId, $callback): array {
                $body = $callback($authSessionId);

                return ['status' => 200, 'resource_type' => 'training_session', 'resource_id' => (string) $body['id'], 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->training->etag($result['body']));
    }

    /**
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>
     */
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
    private function sessionRules(bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';

        return [
            'session_type' => $create ? ['required', 'string', 'in:theory,practical'] : ['prohibited'],
            'starts_at' => [$required, 'date'],
            'ends_at' => [$required, 'date'],
            'instructor_id' => [$required, 'uuid'],
            'vehicle_id' => ['sometimes', 'nullable', 'uuid'],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
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
}
