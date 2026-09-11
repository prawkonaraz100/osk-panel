<?php

namespace App\Modules\InternalExams;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\ResourcesCore\ResourceIdempotency;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

final class InternalExamController
{
    public function __construct(
        private readonly InternalExamService $exams,
        private readonly InternalExamTokenService $tokens,
        private readonly ResourceIdempotency $idempotency,
        private readonly TenantAuthorizer $tenantAuthorizer,
    ) {}

    public function attemptsForCourse(Request $request, string $courseEnrollmentId): JsonResponse
    {
        return response()->json(
            $this->exams->attemptsForCourse($this->sessionId($request), $courseEnrollmentId),
        );
    }

    public function attemptCreate(Request $request, string $courseEnrollmentId): JsonResponse
    {
        $input = $this->validated($request, [
            'exam_part' => ['required', 'string', 'in:theory,practical'],
            'language_code' => ['required', 'string', 'max:16'],
        ]);

        return $this->staffCommand(
            $request,
            'internal_exams.attempt.create',
            ['course_enrollment_id' => $courseEnrollmentId, ...$input],
            201,
            'internal_exam_attempt',
            fn (string $sessionId): array => $this->exams->createAttempt(
                $sessionId,
                $courseEnrollmentId,
                (string) $input['exam_part'],
                (string) $input['language_code'],
                $this->requestId($request),
            ),
        );
    }

    public function attemptGet(Request $request, string $attemptId): JsonResponse
    {
        return response()->json(
            $this->exams->attemptGet($this->sessionId($request), $attemptId),
        );
    }

    public function accessCreate(Request $request, string $attemptId): JsonResponse
    {
        $input = $this->validated($request, [
            'mode' => ['required', 'string', 'in:remote_link,local_current_workstation,assigned_exam_station'],
            'station_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $mode = (string) $input['mode'];
        $stationId = isset($input['station_id']) && is_string($input['station_id'])
            ? $input['station_id']
            : null;

        if ($mode === 'local_current_workstation') {
            throw new ResourceDomainException(
                'STATION_AUTH_REQUIRED',
                409,
                'Authenticated exam station context is required for current-workstation access.',
            );
        }

        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];
        $payload = [
            'attempt_id' => $attemptId,
            'mode' => $mode,
            'station_id' => $stationId,
        ];

        $result = $this->idempotency->executeWithSanitizedReplay(
            $organizationId,
            'internal_exams.access.create',
            $this->idempotencyKey($request),
            $payload,
            function () use ($sessionId, $attemptId, $mode, $stationId, $request): array {
                $expiresAt = $mode === 'remote_link' ? $this->remoteAccessExpiresAt() : null;
                $body = $this->exams->createAccess(
                    $sessionId,
                    $attemptId,
                    $mode,
                    $mode === 'assigned_exam_station' ? $stationId : null,
                    null,
                    $expiresAt,
                    $this->requestId($request),
                );
                $public = $this->publicAccessBody($body);

                return [
                    'status' => 201,
                    'resource_type' => 'internal_exam_access',
                    'resource_id' => (string) $body['id'],
                    'body' => $public,
                    'replay_body' => $this->withoutOneTimeUrl($public, 'one_time_remote_url'),
                ];
            },
        );

        return $this->secretResponse($result['body'], $result['status']);
    }

    public function accessSend(Request $request, string $accessId): JsonResponse
    {
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];
        $result = $this->idempotency->executeWithSanitizedReplay(
            $organizationId,
            'internal_exams.access.send',
            $this->idempotencyKey($request),
            ['access_id' => $accessId],
            function () use ($sessionId, $accessId, $request): array {
                $body = $this->exams->sendRemoteAccess(
                    $sessionId,
                    $accessId,
                    $this->requestId($request),
                );
                $public = $this->publicAccessBody($body);

                return [
                    'status' => 202,
                    'resource_type' => 'internal_exam_access',
                    'resource_id' => $accessId,
                    'body' => $public,
                    'replay_body' => $this->withoutOneTimeUrl($public, 'one_time_remote_url'),
                ];
            },
        );

        return $this->secretResponse($result['body'], $result['status']);
    }

    public function accessRevoke(Request $request, string $accessId): JsonResponse
    {
        $input = $this->validated($request, [
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->staffCommand(
            $request,
            'internal_exams.access.revoke',
            ['access_id' => $accessId, 'reason' => (string) $input['reason']],
            200,
            'internal_exam_access',
            fn (string $sessionId): array => $this->exams->revokePrestart(
                $sessionId,
                $accessId,
                (string) $input['reason'],
                $this->requestId($request),
            ),
        );
    }

    public function accessStart(Request $request, string $accessId): JsonResponse
    {
        $rawToken = $this->optionalBearerToken($request);
        if ($rawToken === null) {
            $this->sessionId($request);

            throw new ResourceDomainException(
                'STATION_AUTH_REQUIRED',
                409,
                'Authenticated exam station context is required for local or assigned-station start.',
            );
        }

        $binding = $this->tokens->resolveBindingForIdempotency($rawToken, 'exam_execution');
        if ($binding['access_id'] !== $accessId) {
            throw $this->invalidExamToken();
        }

        $result = $this->idempotency->execute(
            $binding['organization_id'],
            'internal_exams.access.start',
            $this->idempotencyKey($request),
            ['access_id' => $accessId],
            function () use ($rawToken, $accessId, $request): array {
                $body = $this->exams->startRemote(
                    $rawToken,
                    $accessId,
                    $this->requestId($request),
                );

                return [
                    'status' => 200,
                    'resource_type' => 'internal_exam_attempt',
                    'resource_id' => (string) $body['id'],
                    'body' => $body,
                ];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function attemptSubmit(Request $request, string $attemptId): JsonResponse
    {
        $input = $this->validated($request, [
            'answers' => ['required', 'array', 'min:1'],
            'answers.*' => ['required', 'array'],
            'answers.*.ordinal' => ['required', 'integer', 'min:1'],
            'answers.*.answer' => ['present'],
        ]);
        /** @var list<array<string,mixed>> $answers */
        $answers = array_values($input['answers']);
        $rawToken = $this->optionalBearerToken($request);

        if ($rawToken !== null) {
            $binding = $this->tokens->resolveBindingForIdempotency($rawToken, 'exam_execution');
            if ($binding['attempt_id'] !== $attemptId) {
                throw $this->invalidExamToken();
            }

            $result = $this->idempotency->executeWithSanitizedReplay(
                $binding['organization_id'],
                'internal_exams.attempt.submit.remote',
                $this->idempotencyKey($request),
                ['attempt_id' => $attemptId, 'answers' => $answers],
                function () use ($rawToken, $attemptId, $answers, $request): array {
                    $body = $this->exams->submitRemote(
                        $rawToken,
                        $attemptId,
                        $answers,
                        $this->requestId($request),
                    );
                    $public = $this->publicResultBody($body);

                    return [
                        'status' => 200,
                        'resource_type' => 'internal_exam_result',
                        'resource_id' => $attemptId,
                        'body' => $public,
                        'replay_body' => $this->withoutOneTimeUrl($public, 'one_time_result_url'),
                    ];
                },
            );

            return $this->secretResponse($result['body'], $result['status']);
        }

        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];
        $result = $this->idempotency->executeWithSanitizedReplay(
            $organizationId,
            'internal_exams.attempt.submit.staff',
            $this->idempotencyKey($request),
            ['attempt_id' => $attemptId, 'answers' => $answers],
            function () use ($sessionId, $attemptId, $answers, $request): array {
                $body = $this->exams->submitAsStaff(
                    $sessionId,
                    $attemptId,
                    $answers,
                    $this->requestId($request),
                );
                $public = $this->publicResultBody($body);

                return [
                    'status' => 200,
                    'resource_type' => 'internal_exam_result',
                    'resource_id' => $attemptId,
                    'body' => $public,
                    'replay_body' => $this->withoutOneTimeUrl($public, 'one_time_result_url'),
                ];
            },
        );

        return $this->secretResponse($result['body'], $result['status']);
    }

    public function technicalAbort(Request $request, string $attemptId): JsonResponse
    {
        $input = $this->validated($request, [
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->staffCommand(
            $request,
            'internal_exams.attempt.technical_abort',
            ['attempt_id' => $attemptId, 'reason' => (string) $input['reason']],
            200,
            'internal_exam_attempt',
            fn (string $sessionId): array => $this->exams->technicalAbort(
                $sessionId,
                $attemptId,
                (string) $input['reason'],
                $this->requestId($request),
            ),
        );
    }

    public function result(Request $request, string $attemptId): JsonResponse
    {
        $rawToken = $this->optionalBearerToken($request);
        $body = $rawToken === null
            ? $this->exams->resultAsStaff($this->sessionId($request), $attemptId)
            : $this->exams->resultWithToken($rawToken, $attemptId);

        return response()->json($body)->withHeaders([
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function questions(Request $request, string $attemptId): JsonResponse
    {
        $rawToken = $this->optionalBearerToken($request);
        $body = $rawToken === null
            ? $this->exams->questionsAsStaff($this->sessionId($request), $attemptId)
            : $this->exams->questionsWithToken($rawToken, $attemptId);

        return response()->json($body)->withHeaders([
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  callable(string):array<string,mixed>  $callback
     */
    private function staffCommand(
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
                    'resource_id' => (string) ($body['id'] ?? $body['attempt_id'] ?? ''),
                    'body' => $body,
                ];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    /** @param  array<string,array<int,string>>  $rules
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

    private function optionalBearerToken(Request $request): ?string
    {
        $authorization = trim((string) $request->header('Authorization'));
        if ($authorization === '') {
            return null;
        }

        if (! preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            throw $this->invalidExamToken();
        }

        $token = trim($matches[1]);
        if ($token === '') {
            throw $this->invalidExamToken();
        }

        return $token;
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

    private function remoteAccessExpiresAt(): CarbonImmutable
    {
        $value = config('internal_exams.remote_access_ttl_minutes');
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new LogicException('INTERNAL_EXAM_REMOTE_ACCESS_TTL_MINUTES must be explicitly configured.');
        }
        $minutes = (int) $value;
        if ($minutes < 1) {
            throw new LogicException('Internal exam remote access TTL must be positive.');
        }

        return CarbonImmutable::now()->addMinutes($minutes);
    }

    /** @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function publicAccessBody(array $body): array
    {
        $rawToken = $body['one_time_remote_token'] ?? null;
        unset($body['one_time_remote_token']);
        $body['one_time_remote_url'] = is_string($rawToken) && $rawToken !== ''
            ? $this->oneTimeUrl($rawToken)
            : null;

        return $body;
    }

    /** @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function publicResultBody(array $body): array
    {
        $rawToken = $body['one_time_result_token'] ?? null;
        unset($body['one_time_result_token']);
        $body['one_time_result_url'] = is_string($rawToken) && $rawToken !== ''
            ? $this->oneTimeUrl($rawToken)
            : null;

        return $body;
    }

    private function oneTimeUrl(string $rawToken): string
    {
        $base = config('internal_exams.remote_public_base_url');
        if (! is_string($base) || $base === '') {
            throw new LogicException('INTERNAL_EXAM_REMOTE_PUBLIC_BASE_URL must be explicitly configured.');
        }

        $parts = parse_url($base);
        if ($parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['fragment'])) {
            throw new LogicException('Internal exam remote public base URL must be an absolute HTTPS URL without a fragment.');
        }

        return $base.'#exam_access_token='.rawurlencode($rawToken);
    }

    /** @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function withoutOneTimeUrl(array $body, string $key): array
    {
        $body[$key] = null;

        return $body;
    }

    /** @param  array<string,mixed>  $body */
    private function secretResponse(array $body, int $status): JsonResponse
    {
        return response()->json($body, $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function invalidExamToken(): ResourceDomainException
    {
        return new ResourceDomainException(
            'INVALID_EXAM_ACCESS_TOKEN',
            401,
            'Invalid or expired internal exam access token.',
        );
    }

    private function requestId(Request $request): string
    {
        return (string) $request->attributes->get('request_id');
    }
}
