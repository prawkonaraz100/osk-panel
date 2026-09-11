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

final class CalendarEventController
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ResourceIdempotency $idempotency,
        private readonly CalendarEventService $calendar,
    ) {}

    public function list(Request $request): JsonResponse
    {
        /** @var array{from?:?string,to?:?string,student_id?:?string,staff_id?:?string,vehicle_id?:?string,location_id?:?string,event_type?:list<string>} $filters */
        $filters = $this->validatedInput($request->query(), [
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'student_id' => ['sometimes', 'nullable', 'uuid'],
            'staff_id' => ['sometimes', 'nullable', 'uuid'],
            'vehicle_id' => ['sometimes', 'nullable', 'uuid'],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'event_type' => ['sometimes', 'array'],
            'event_type.*' => ['string'],
        ]);

        return response()->json($this->calendar->list($this->sessionId($request), $filters));
    }

    public function create(Request $request): JsonResponse
    {
        /** @var array{event_type:string,name?:?string,starts_at:string,ends_at:string,student_id?:?string,instructor_id?:?string,vehicle_id?:?string,location_id?:?string,custom_meeting_place?:?string} $input */
        $input = $this->validatedInput($request->all(), $this->eventRules(true));
        $authSessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($authSessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'calendar.events.create',
            $this->idempotencyKey($request),
            $input,
            function () use ($authSessionId, $input, $request): array {
                $body = $this->calendar->create($authSessionId, $input, $this->requestId($request));

                return ['status' => 201, 'resource_type' => 'calendar_event', 'resource_id' => (string) $body['id'], 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->calendar->etag($result['body']));
    }

    public function get(Request $request, string $eventId): JsonResponse
    {
        $body = $this->calendar->get($this->sessionId($request), $eventId);

        return response()->json($body)->header('ETag', $this->calendar->etag($body));
    }

    public function update(Request $request, string $eventId): JsonResponse
    {
        /** @var array{event_type?:string,name?:?string,starts_at?:string,ends_at?:string,student_id?:?string,instructor_id?:?string,vehicle_id?:?string,location_id?:?string,custom_meeting_place?:?string} $input */
        $input = $this->validatedInput($request->all(), $this->eventRules(false));
        $body = $this->calendar->update(
            $this->sessionId($request),
            $eventId,
            $input,
            $this->requestId($request),
            $request->header('If-Match'),
        );

        return response()->json($body)->header('ETag', $this->calendar->etag($body));
    }

    public function cancel(Request $request, string $eventId): JsonResponse
    {
        /** @var array{reason?:?string} $input */
        $input = $this->validatedInput($request->all(), ['reason' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        return $this->terminal(
            $request,
            $eventId,
            'calendar.events.cancel',
            ['reason' => $input['reason'] ?? null, 'if_match' => $request->header('If-Match')],
            fn (string $authSessionId): array => $this->calendar->cancel(
                $authSessionId,
                $eventId,
                $input['reason'] ?? null,
                $this->requestId($request),
                $request->header('If-Match'),
            ),
        );
    }

    public function complete(Request $request, string $eventId): JsonResponse
    {
        $this->validatedInput($request->all(), []);

        return $this->terminal(
            $request,
            $eventId,
            'calendar.events.complete',
            ['if_match' => $request->header('If-Match')],
            fn (string $authSessionId): array => $this->calendar->complete(
                $authSessionId,
                $eventId,
                $this->requestId($request),
                $request->header('If-Match'),
            ),
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  callable(string):array<string,mixed>  $callback
     */
    private function terminal(Request $request, string $eventId, string $operation, array $payload, callable $callback): JsonResponse
    {
        $authSessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($authSessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            $operation,
            $this->idempotencyKey($request),
            ['event_id' => $eventId, ...$payload],
            function () use ($authSessionId, $callback): array {
                $body = $callback($authSessionId);

                return ['status' => 200, 'resource_type' => 'calendar_event', 'resource_id' => (string) $body['id'], 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->calendar->etag($result['body']));
    }

    /** @return array<string,array<int,string>> */
    private function eventRules(bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';

        return [
            'event_type' => [$required, 'string', 'in:general_event'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'starts_at' => [$required, 'date'],
            'ends_at' => [$required, 'date'],
            'student_id' => ['sometimes', 'nullable', 'uuid'],
            'instructor_id' => ['sometimes', 'nullable', 'uuid'],
            'vehicle_id' => ['sometimes', 'nullable', 'uuid'],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'custom_meeting_place' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>
     */
    private function validatedInput(array $input, array $rules): array
    {
        $allowedRoots = array_values(array_unique(array_map(
            static function (string $key): string {
                $dot = strpos($key, '.');

                return $dot === false ? $key : substr($key, 0, $dot);
            },
            array_keys($rules),
        )));
        $unknown = array_values(array_diff(array_keys($input), $allowedRoots));
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
            throw ValidationException::withMessages(['Idempotency-Key' => ['A UUID Idempotency-Key header is required.']]);
        }

        return $key;
    }

    private function requestId(Request $request): string
    {
        return (string) $request->attributes->get('request_id');
    }
}
