<?php

namespace App\Modules\LearningAccess;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\ResourcesCore\ResourceIdempotency;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LearningAccessController
{
    public function __construct(
        private readonly LearningAccountService $accounts,
        private readonly LicenseService $licenses,
        private readonly ResourceIdempotency $idempotency,
        private readonly TenantAuthorizer $tenantAuthorizer,
    ) {}

    public function accountsList(Request $request, string $studentId): JsonResponse
    {
        return response()->json($this->accounts->list($this->sessionId($request), $studentId));
    }

    public function accountsCreate(Request $request, string $studentId): JsonResponse
    {
        $input = $this->validated($request, [
            'login_identifier' => ['required', 'string', 'max:320'],
            'language_code' => ['required', 'string', 'max:16'],
            'initial_password' => ['sometimes', 'nullable', 'string', 'min:12', 'max:255'],
        ]);

        return $this->command(
            $request,
            'learning_access.account.create',
            ['student_id' => $studentId, ...$input],
            201,
            'student_learning_account',
            fn (string $sessionId): array => $this->accounts->create(
                $sessionId, $studentId, $input, $this->requestId($request),
            ),
        );
    }

    public function accountsUpdate(Request $request, string $studentId, string $accountId): JsonResponse
    {
        $input = $this->validated($request, [
            'login_identifier' => ['sometimes', 'string', 'max:320'],
            'language_code' => ['sometimes', 'string', 'max:16'],
        ]);
        if ($input === []) {
            throw ValidationException::withMessages(['request' => ['At least one field is required.']]);
        }

        $body = $this->accounts->update(
            $this->sessionId($request),
            $studentId,
            $accountId,
            $input,
            $this->requestId($request),
            $request->header('If-Match'),
        );

        return response()->json($body)->withHeaders(['ETag' => '"v'.(int) $body['version'].'"']);
    }

    public function resetPassword(Request $request, string $studentId, string $accountId): JsonResponse
    {
        $expected = $this->expectedVersion($request, 'credential');
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];
        $result = $this->idempotency->executeWithSanitizedReplay(
            $organizationId,
            'learning_access.password.reset',
            $this->idempotencyKey($request),
            [
                'student_id' => $studentId,
                'account_id' => $accountId,
                'expected_credential_version' => $expected,
            ],
            function () use ($sessionId, $studentId, $accountId, $expected, $request): array {
                $result = $this->accounts->resetPassword(
                    $sessionId,
                    $studentId,
                    $accountId,
                    $expected,
                    $this->requestId($request),
                );

                return [
                    'status' => 200,
                    'resource_type' => 'student_access_handoff',
                    'resource_id' => (string) $result['body']['id'],
                    'body' => $result['body'],
                    'replay_body' => $result['replay_body'],
                ];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function createHandoff(Request $request, string $studentId, string $accountId): JsonResponse
    {
        return $this->command(
            $request,
            'learning_access.handoff.create',
            ['student_id' => $studentId, 'account_id' => $accountId],
            201,
            'student_access_handoff',
            fn (string $sessionId): array => $this->accounts->createNonSecretHandoff(
                $sessionId, $studentId, $accountId, $this->requestId($request),
            ),
        );
    }

    public function products(Request $request): JsonResponse
    {
        return response()->json($this->licenses->products($this->sessionId($request)));
    }

    public function productLanguages(Request $request, string $productId): JsonResponse
    {
        return response()->json($this->licenses->productLanguages($this->sessionId($request), $productId));
    }

    public function inventory(Request $request): JsonResponse
    {
        $statuses = $request->query('status', []);
        if (is_string($statuses)) {
            $statuses = [$statuses];
        }
        if (! is_array($statuses)) {
            $statuses = [];
        }

        return response()->json($this->licenses->inventory(
            $this->sessionId($request),
            $request->query('product_id'),
            array_values(array_map('strval', $statuses)),
        ));
    }

    public function assignments(Request $request): JsonResponse
    {
        return response()->json($this->licenses->assignments($this->sessionId($request), [
            'page' => $request->integer('page', 1),
            'per_page' => $request->integer('per_page', 25),
            'search' => $request->query('search'),
            'hide_finished' => $request->boolean('hide_finished'),
        ]));
    }

    public function assignmentCreate(Request $request): JsonResponse
    {
        $input = $this->validated($request, [
            'license_inventory_entry_id' => ['required', 'uuid'],
            'target' => ['required', 'array'],
            'target.existing_learning_account_id' => ['sometimes', 'uuid'],
            'target.student_id' => ['sometimes', 'uuid'],
            'target.new_learning_account' => ['sometimes', 'array'],
            'target.new_learning_account.login_identifier' => ['required_with:target.new_learning_account', 'string', 'max:320'],
            'target.new_learning_account.language_code' => ['required_with:target.new_learning_account', 'string', 'max:16'],
            'target.new_learning_account.initial_password' => ['sometimes', 'nullable', 'string', 'min:12', 'max:255'],
            'language_code' => ['required', 'string', 'max:16'],
        ]);

        return $this->command(
            $request,
            'learning_access.assignment.create',
            $input,
            201,
            'license_assignment',
            fn (string $sessionId): array => $this->licenses->createAssignment(
                $sessionId, $input, $this->requestId($request),
            ),
        );
    }

    public function assignmentGet(Request $request, string $assignmentId): JsonResponse
    {
        $body = $this->licenses->getAssignment($this->sessionId($request), $assignmentId);

        return response()->json($body)->withHeaders(['ETag' => '"v'.(int) $body['version'].'"']);
    }

    public function assignmentActivate(Request $request, string $assignmentId): JsonResponse
    {
        return $this->command(
            $request,
            'learning_access.assignment.activate',
            ['assignment_id' => $assignmentId, 'expected_version' => $this->expectedVersion($request, 'assignment')],
            200,
            'license_activation',
            fn (string $sessionId): array => $this->licenses->activate(
                $sessionId,
                $assignmentId,
                $this->requestId($request),
                $request->header('If-Match'),
            ),
        );
    }

    public function assignmentRevoke(Request $request, string $assignmentId): JsonResponse
    {
        $input = $this->validated($request, ['reason' => ['required', 'string', 'max:1000']]);

        return $this->command(
            $request,
            'learning_access.assignment.revoke',
            [
                'assignment_id' => $assignmentId,
                'reason' => (string) $input['reason'],
                'expected_version' => $this->expectedVersion($request, 'assignment'),
            ],
            200,
            'license_assignment',
            fn (string $sessionId): array => $this->licenses->revokeUnactivated(
                $sessionId,
                $assignmentId,
                (string) $input['reason'],
                $this->requestId($request),
                $request->header('If-Match'),
            ),
        );
    }

    public function history(Request $request, string $studentId, string $accountId): JsonResponse
    {
        return response()->json($this->licenses->history($this->sessionId($request), $studentId, $accountId));
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

    /** @param array<string,array<int,string>> $rules
     * @return array<string,mixed>
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

    private function expectedVersion(Request $request, string $label): int
    {
        $tag = trim((string) $request->header('If-Match'));
        $tag = trim(trim($tag), '"');
        $tag = str_starts_with($tag, 'v') ? substr($tag, 1) : $tag;
        if ($tag === '' || ! ctype_digit($tag)) {
            throw new ResourceDomainException(
                'PRECONDITION_REQUIRED',
                428,
                'If-Match with current '.$label.' version is required.',
            );
        }

        return (int) $tag;
    }

    private function requestId(Request $request): string
    {
        return (string) $request->attributes->get('request_id');
    }
}
