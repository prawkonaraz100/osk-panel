<?php

namespace App\Modules\CommerceDashboard;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceIdempotency;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CommerceDashboardController
{
    public function __construct(
        private readonly CommerceDashboardService $commerce,
        private readonly ResourceIdempotency $idempotency,
        private readonly TenantAuthorizer $tenantAuthorizer,
    ) {}

    public function ordersList(Request $request): JsonResponse
    {
        [$page, $perPage, $statuses] = $this->pagination($request, true);

        return response()->json($this->commerce->listOrders(
            $this->sessionId($request),
            $page,
            $perPage,
            $statuses,
        ));
    }

    public function ordersGet(Request $request, string $orderId): JsonResponse
    {
        return response()->json($this->commerce->getOrder($this->sessionId($request), $orderId));
    }

    public function orderPaymentsCreate(Request $request, string $orderId): JsonResponse
    {
        $input = $this->validated($request, [
            'method' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'return_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ]);

        return $this->command(
            $request,
            'commerce.payment.start',
            array_merge(['order_id' => $orderId], $input),
            201,
            'platform_payment',
            fn (string $sessionId): array => $this->commerce->startPayment(
                $sessionId,
                $orderId,
                (string) $input['method'],
                $this->requestId($request),
            ),
        );
    }

    public function paymentsList(Request $request): JsonResponse
    {
        [$page, $perPage] = $this->pagination($request, false);

        return response()->json($this->commerce->listPayments(
            $this->sessionId($request),
            $page,
            $perPage,
        ));
    }

    public function purchaseHistoryList(Request $request): JsonResponse
    {
        [$page, $perPage, $statuses] = $this->pagination($request, true);

        return response()->json($this->commerce->purchaseHistory(
            $this->sessionId($request),
            $page,
            $perPage,
            $statuses,
        ));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  callable(string):array<string,mixed>  $callback
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

    /**
     * @return array{0:int,1:int,2?:list<string>}
     */
    private function pagination(Request $request, bool $withStatuses): array
    {
        $pageRaw = $request->query('page', 1);
        $perPageRaw = $request->query('per_page', 25);
        if (is_numeric($pageRaw) === false || (int) $pageRaw < 1) {
            throw ValidationException::withMessages(['page' => ['page must be an integer greater than or equal to 1.']]);
        }
        if (is_numeric($perPageRaw) === false || (int) $perPageRaw < 1 || (int) $perPageRaw > 100) {
            throw ValidationException::withMessages(['per_page' => ['per_page must be between 1 and 100.']]);
        }

        if ($withStatuses === false) {
            return [(int) $pageRaw, (int) $perPageRaw];
        }

        $raw = $request->query('status', []);
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (is_array($raw) === false) {
            throw ValidationException::withMessages(['status' => ['status must be a repeated query parameter or array.']]);
        }

        $statuses = [];
        foreach ($raw as $value) {
            if (is_string($value) === false || in_array($value, CommerceDashboardService::PURCHASE_STATUSES, true) === false) {
                throw ValidationException::withMessages(['status' => ['Unsupported purchase status.']]);
            }
            if (in_array($value, $statuses, true) === false) {
                $statuses[] = $value;
            }
        }

        return [(int) $pageRaw, (int) $perPageRaw, $statuses];
    }

    /**
     * @param array<string,array<int,string>> $rules
     * @return array<string,mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $input = $request->all();
        $unknown = array_values(array_diff(array_keys($input), array_keys($rules)));
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
        if (is_string($sessionId) === false || $sessionId === '') {
            throw new AuthenticationException('Authenticated application session required.');
        }

        return $sessionId;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || Str::isUuid($key) === false) {
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
