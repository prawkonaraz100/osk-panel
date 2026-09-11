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

final class AvailabilitySlotController
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ResourceIdempotency $idempotency,
        private readonly AvailabilitySlotService $slots,
        private readonly AvailabilityFormalizationService $formalization,
    ) {}

    public function list(Request $request): JsonResponse
    {
        /** @var array{from?:?string,to?:?string,staff_id?:?string,vehicle_id?:?string,location_id?:?string} $filters */
        $filters = $this->validatedInput($request->query(), [
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'staff_id' => ['sometimes', 'nullable', 'uuid'],
            'vehicle_id' => ['sometimes', 'nullable', 'uuid'],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return response()->json($this->slots->list($this->sessionId($request), $filters));
    }

    public function create(Request $request): JsonResponse
    {
        /** @var array{instructor_id?:?string,vehicle_id?:?string,location_id?:?string,starts_at:string,ends_at:string} $input */
        $input = $this->validatedInput($request->all(), $this->slotRules(true));
        $authSessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($authSessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'availability.create',
            $this->idempotencyKey($request),
            $input,
            function () use ($authSessionId, $input, $request): array {
                $body = $this->slots->create($authSessionId, $input, $this->requestId($request));

                return ['status' => 201, 'resource_type' => 'availability_slot', 'resource_id' => (string) $body['id'], 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->slots->etag($result['body']));
    }

    public function update(Request $request, string $slotId): JsonResponse
    {
        /** @var array{instructor_id?:?string,vehicle_id?:?string,location_id?:?string,starts_at?:string,ends_at?:string} $input */
        $input = $this->validatedInput($request->all(), $this->slotRules(false));
        $body = $this->slots->update(
            $this->sessionId($request),
            $slotId,
            $input,
            $this->requestId($request),
            $request->header('If-Match'),
        );

        return response()->json($body)->header('ETag', $this->slots->etag($body));
    }

    public function book(Request $request, string $slotId): JsonResponse
    {
        /** @var array{student_id:string} $input */
        $input = $this->validatedInput($request->all(), ['student_id' => ['required', 'uuid']]);

        return $this->idempotentCommand(
            $request,
            $slotId,
            'availability.book',
            ['student_id' => $input['student_id'], 'if_match' => $request->header('If-Match')],
            fn (string $authSessionId): array => $this->slots->book(
                $authSessionId,
                $slotId,
                $input['student_id'],
                $this->requestId($request),
                $request->header('If-Match'),
            ),
        );
    }

    public function formalize(Request $request, string $slotId): JsonResponse
    {
        /** @var array{course_enrollment_id?:?string,display_name?:?string,custom_meeting_place?:?string} $input */
        $input = $this->validatedInput($request->all(), [
            'course_enrollment_id' => ['sometimes', 'nullable', 'uuid'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'custom_meeting_place' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $authSessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($authSessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'availability.formalize',
            $this->idempotencyKey($request),
            ['slot_id' => $slotId, 'if_match' => $request->header('If-Match'), ...$input],
            function () use ($authSessionId, $slotId, $input, $request): array {
                $body = $this->formalization->formalize(
                    $authSessionId,
                    $slotId,
                    $input,
                    $this->requestId($request),
                    $request->header('If-Match'),
                );

                return [
                    'status' => 201,
                    'resource_type' => 'training_session',
                    'resource_id' => (string) $body['training_session']['id'],
                    'body' => $body,
                ];
            },
        );

        /** @var array{availability_slot:array<string,mixed>,training_session:array<string,mixed>} $body */
        $body = $result['body'];

        return response()->json($body, $result['status'])
            ->header('ETag', '"v'.(int) $body['availability_slot']['version'].'"');
    }

    public function cancel(Request $request, string $slotId): JsonResponse
    {
        /** @var array{reason?:?string} $input */
        $input = $this->validatedInput($request->all(), [
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        return $this->idempotentCommand(
            $request,
            $slotId,
            'availability.cancel',
            ['reason' => $input['reason'] ?? null, 'if_match' => $request->header('If-Match')],
            fn (string $authSessionId): array => $this->slots->cancel(
                $authSessionId,
                $slotId,
                $input['reason'] ?? null,
                $this->requestId($request),
                $request->header('If-Match'),
            ),
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  callable(string):array<string,mixed>  $callback
     */
    private function idempotentCommand(
        Request $request,
        string $slotId,
        string $operation,
        array $payload,
        callable $callback,
    ): JsonResponse {
        $authSessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($authSessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            $operation,
            $this->idempotencyKey($request),
            ['slot_id' => $slotId, ...$payload],
            function () use ($authSessionId, $callback): array {
                $body = $callback($authSessionId);

                return ['status' => 200, 'resource_type' => 'availability_slot', 'resource_id' => (string) $body['id'], 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->slots->etag($result['body']));
    }

    /** @return array<string,array<int,string>> */
    private function slotRules(bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';

        return [
            'instructor_id' => ['sometimes', 'nullable', 'uuid'],
            'vehicle_id' => ['sometimes', 'nullable', 'uuid'],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'starts_at' => [$required, 'date'],
            'ends_at' => [$required, 'date'],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>
     */
    private function validatedInput(array $input, array $rules): array
    {
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
