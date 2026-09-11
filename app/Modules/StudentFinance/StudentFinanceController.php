<?php

namespace App\Modules\StudentFinance;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceIdempotency;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StudentFinanceController
{
    public function __construct(
        private readonly StudentFinanceService $finance,
        private readonly ResourceIdempotency $idempotency,
        private readonly TenantAuthorizer $tenantAuthorizer,
    ) {}

    public function chargesList(Request $request, string $studentId): JsonResponse
    {
        return response()->json($this->finance->listCharges($this->sessionId($request), $studentId));
    }

    public function chargesCreate(Request $request, string $studentId): JsonResponse
    {
        $input = $this->validated($request, [
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'array'],
            'amount.amount_minor' => ['required', 'integer', 'min:0'],
            'amount.currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'course_enrollment_id' => ['sometimes', 'nullable', 'uuid'],
            'due_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return $this->command(
            $request,
            'student_finance.charge.create',
            ['student_id' => $studentId, ...$input],
            201,
            'student_charge',
            fn (string $sessionId): array => $this->finance->createCharge($sessionId, $studentId, $input, $this->requestId($request)),
        );
    }

    public function chargesCancel(Request $request, string $studentId, string $chargeId): JsonResponse
    {
        $input = $this->validated($request, ['reason' => ['required', 'string', 'max:1000']]);

        return $this->command(
            $request,
            'student_finance.charge.cancel',
            ['student_id' => $studentId, 'charge_id' => $chargeId, ...$input],
            200,
            'student_charge',
            fn (string $sessionId): array => $this->finance->cancelCharge(
                $sessionId, $studentId, $chargeId, (string) $input['reason'], $this->requestId($request),
            ),
        );
    }

    public function paymentsList(Request $request, string $studentId): JsonResponse
    {
        return response()->json($this->finance->listPayments($this->sessionId($request), $studentId));
    }

    public function paymentsRecord(Request $request, string $studentId): JsonResponse
    {
        $input = $this->validated($request, [
            'charge_id' => ['required', 'uuid'],
            'amount' => ['required', 'array'],
            'amount.amount_minor' => ['required', 'integer', 'min:1'],
            'amount.currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'paid_at' => ['required', 'date'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:64'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return $this->command(
            $request,
            'student_finance.payment.record',
            ['student_id' => $studentId, ...$input],
            201,
            'student_payment',
            fn (string $sessionId): array => $this->finance->recordPayment($sessionId, $studentId, $input, $this->requestId($request)),
        );
    }

    public function paymentsReverse(Request $request, string $studentId, string $paymentId): JsonResponse
    {
        $input = $this->validated($request, ['reason' => ['required', 'string', 'max:1000']]);

        return $this->command(
            $request,
            'student_finance.payment.reverse',
            ['student_id' => $studentId, 'payment_id' => $paymentId, ...$input],
            200,
            'student_payment',
            fn (string $sessionId): array => $this->finance->reversePayment(
                $sessionId, $studentId, $paymentId, (string) $input['reason'], $this->requestId($request),
            ),
        );
    }

    public function summary(Request $request, string $studentId): JsonResponse
    {
        return response()->json($this->finance->summary($this->sessionId($request), $studentId));
    }

    /**
     * @param array<string,mixed> $payload
     * @param callable(string):array<string,mixed> $callback
     */
    private function command(
        Request $request,
        string $operationKey,
        array $payload,
        int $status,
        string $resourceType,
        callable $callback,
    ): JsonResponse {
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];
        $result = $this->idempotency->execute(
            $organizationId,
            $operationKey,
            $this->idempotencyKey($request),
            $payload,
            function () use ($sessionId, $status, $resourceType, $callback): array {
                $body = $callback($sessionId);

                return [
                    'status' => $status,
                    'resource_type' => $resourceType,
                    'resource_id' => (string) ($body['id'] ?? ''),
                    'body' => $body,
                ];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    /** @param array<string,array<int,string>> $rules
     *  @return array<string,mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $input = $request->all();
        $allowedRoots = array_values(array_unique(array_map(
            static fn (string $key): string => explode('.', $key, 2)[0],
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
