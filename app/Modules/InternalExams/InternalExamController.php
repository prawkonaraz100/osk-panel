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
        private readonly InternalExamManagementService $management,
        private readonly InternalExamReadService $reads,
        private readonly InternalExamTokenService $tokens,
        private readonly ExamStationCredentialService $stationCredentials,
        private readonly ExamStationService $stations,
        private readonly ResourceIdempotency $idempotency,
        private readonly TenantAuthorizer $tenantAuthorizer,
    ) {}

    public function inventory(Request $request): JsonResponse
    {
        return response()->json(
            $this->reads->inventory($this->sessionId($request)),
        );
    }

    public function capabilities(Request $request): JsonResponse
    {
        $input = $this->validated($request, [
            'category' => ['required', 'string', 'max:16'],
            'part' => ['required', 'string', 'in:theory,practical'],
        ]);

        return response()->json($this->reads->capability(
            $this->sessionId($request),
            (string) $input['category'],
            (string) $input['part'],
        ));
    }

    public function subjects(Request $request): JsonResponse
    {
        $input = $this->validated($request, [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:320'],
            'category' => ['sometimes', 'array'],
            'category.*' => ['string', 'max:16'],
            'status' => ['sometimes', 'array'],
            'status.*' => ['string', 'in:not_assigned,not_conducted,failed,passed'],
            'hide_finished' => ['sometimes', 'in:0,1,true,false'],
            'sort' => ['sometimes', 'string', 'in:identity_or_login,student_full_name,latest_exam_category,latest_exam_status,latest_exam_at,latest_exam_language,exam_count'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        /** @var list<string> $categories */
        $categories = array_values($input['category'] ?? []);
        /** @var list<string> $statuses */
        $statuses = array_values($input['status'] ?? []);
        $hideFinished = in_array($input['hide_finished'] ?? false, [true, 1, '1', 'true'], true);

        return response()->json($this->management->subjects(
            $this->sessionId($request),
            (int) ($input['page'] ?? 1),
            (int) ($input['per_page'] ?? 25),
            isset($input['q']) && is_string($input['q']) ? $input['q'] : null,
            $categories,
            $statuses,
            $hideFinished,
            (string) ($input['sort'] ?? 'student_full_name'),
            (string) ($input['direction'] ?? 'asc'),
        ));
    }

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
        $body = $this->exams->attemptGet($this->sessionId($request), $attemptId);

        return response()->json($body)
            ->header('ETag', $this->exams->etag($body));
    }

    public function attemptPatch(Request $request, string $attemptId): JsonResponse
    {
        $input = $this->validated($request, [
            'candidate_snapshot' => ['required', 'array', 'min:1'],
            'candidate_snapshot.first_name' => ['sometimes', 'string', 'max:120'],
            'candidate_snapshot.last_name' => ['sometimes', 'string', 'max:120'],
            'candidate_snapshot.birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'candidate_snapshot.contact_email' => ['sometimes', 'nullable', 'email', 'max:320'],
            'candidate_snapshot.no_pesel_declared' => ['sometimes', 'boolean'],
        ]);

        /** @var array<string,mixed> $patch */
        $patch = $input['candidate_snapshot'];
        $body = $this->exams->editCandidateSnapshot(
            $this->sessionId($request),
            $attemptId,
            $patch,
            $request->header('If-Match'),
        );

        return response()->json($body)
            ->header('ETag', $this->exams->etag($body));
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

        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];
        $trustedStationId = null;

        if ($mode === 'local_current_workstation') {
            if ($stationId !== null) {
                throw ResourceDomainException::rule(
                    'Current-workstation access derives station identity from the authenticated station credential.',
                );
            }

            $stationContext = $this->stationCredentials->heartbeat(
                $this->requiredStationCredential($request),
                $organizationId,
            );
            $trustedStationId = $stationContext['station_id'];
        }

        $payload = [
            'attempt_id' => $attemptId,
            'mode' => $mode,
            'station_id' => $mode === 'local_current_workstation' ? $trustedStationId : $stationId,
        ];

        $result = $this->idempotency->executeWithSanitizedReplay(
            $organizationId,
            'internal_exams.access.create',
            $this->idempotencyKey($request),
            $payload,
            function () use ($sessionId, $attemptId, $mode, $stationId, $trustedStationId, $request): array {
                $expiresAt = $mode === 'remote_link' ? $this->remoteAccessExpiresAt() : null;
                $body = $this->exams->createAccess(
                    $sessionId,
                    $attemptId,
                    $mode,
                    $mode === 'assigned_exam_station' ? $stationId : null,
                    $mode === 'local_current_workstation' ? $trustedStationId : null,
                    $expiresAt,
                    $this->requestId($request),
                );
                $public = $this->publicAccessBody($body);

                return [
                    'status' => 201,
                    'resource_type' => 'internal_exam_access',
                    'resource_id' => (string) $body['id'],
                    'body' => $public,
                    'replay_body' => $this->withoutOneTimeValue($public, 'one_time_remote_url'),
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
                    'replay_body' => $this->withoutOneTimeValue($public, 'one_time_remote_url'),
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
        if ($rawToken !== null) {
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

        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];
        $rawStationCredential = $this->requiredStationCredential($request);
        $stationBinding = $this->stationCredentials->resolveCurrent(
            $rawStationCredential,
            $organizationId,
        );

        $result = $this->idempotency->execute(
            $organizationId,
            'internal_exams.access.start',
            $this->idempotencyKey($request),
            [
                'access_id' => $accessId,
                'station_id' => $stationBinding['station_id'],
            ],
            function () use ($sessionId, $accessId, $rawStationCredential, $request): array {
                $body = $this->exams->startLocal(
                    $sessionId,
                    $accessId,
                    $rawStationCredential,
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

    public function stationsList(Request $request): JsonResponse
    {
        return response()->json(
            $this->stations->list($this->sessionId($request)),
        );
    }

    public function stationRegister(Request $request): JsonResponse
    {
        $this->validated($request, []);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->executeWithSanitizedReplay(
            $organizationId,
            'internal_exams.station.register',
            $this->idempotencyKey($request),
            [],
            function () use ($sessionId, $request): array {
                $body = $this->stations->register($sessionId, $this->requestId($request));

                return [
                    'status' => 201,
                    'resource_type' => 'exam_station',
                    'resource_id' => (string) $body['id'],
                    'body' => $body,
                    'replay_body' => $this->withoutOneTimeValue($body, 'one_time_station_credential'),
                ];
            },
        );

        return $this->secretResponse($result['body'], $result['status']);
    }

    public function stationCredentialProvision(Request $request, string $stationId): JsonResponse
    {
        $this->validated($request, []);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->executeWithSanitizedReplay(
            $organizationId,
            'internal_exams.station.credential.provision',
            $this->idempotencyKey($request),
            ['station_id' => $stationId],
            function () use ($sessionId, $stationId, $request): array {
                $body = $this->stations->provisionCredential(
                    $sessionId,
                    $stationId,
                    $this->requestId($request),
                );

                return [
                    'status' => 200,
                    'resource_type' => 'exam_station',
                    'resource_id' => $stationId,
                    'body' => $body,
                    'replay_body' => $this->withoutOneTimeValue($body, 'one_time_station_credential'),
                ];
            },
        );

        return $this->secretResponse($result['body'], $result['status']);
    }

    public function stationCredentialRotate(Request $request, string $stationId): JsonResponse
    {
        $input = $this->validated($request, [
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->executeWithSanitizedReplay(
            $organizationId,
            'internal_exams.station.credential.rotate',
            $this->idempotencyKey($request),
            ['station_id' => $stationId, 'reason' => (string) $input['reason']],
            function () use ($sessionId, $stationId, $input, $request): array {
                $body = $this->stations->rotateCredential(
                    $sessionId,
                    $stationId,
                    (string) $input['reason'],
                    $this->requestId($request),
                );

                return [
                    'status' => 200,
                    'resource_type' => 'exam_station',
                    'resource_id' => $stationId,
                    'body' => $body,
                    'replay_body' => $this->withoutOneTimeValue($body, 'one_time_station_credential'),
                ];
            },
        );

        return $this->secretResponse($result['body'], $result['status']);
    }

    public function stationHeartbeat(Request $request): JsonResponse
    {
        $context = $this->stationCredentials->heartbeat(
            $this->stationCredentialOrInvalid($request),
        );

        return response()->json([
            'station_id' => $context['station_id'],
            'authenticated_at' => $context['authenticated_at'],
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function stationTransfer(Request $request, string $attemptId): JsonResponse
    {
        $input = $this->validated($request, [
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];
        $rawTargetCredential = $this->requiredStationCredential($request);
        $targetBinding = $this->stationCredentials->resolveCurrent(
            $rawTargetCredential,
            $organizationId,
        );

        $result = $this->idempotency->execute(
            $organizationId,
            'internal_exams.station.transfer',
            $this->idempotencyKey($request),
            [
                'attempt_id' => $attemptId,
                'target_station_id' => $targetBinding['station_id'],
                'reason' => (string) $input['reason'],
            ],
            function () use ($sessionId, $attemptId, $rawTargetCredential, $input, $request): array {
                $body = $this->exams->transferStation(
                    $sessionId,
                    $attemptId,
                    $rawTargetCredential,
                    (string) $input['reason'],
                    $this->requestId($request),
                );

                return [
                    'status' => 200,
                    'resource_type' => 'internal_exam_station_session',
                    'resource_id' => (string) $body['station_session_id'],
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
                        'replay_body' => $this->withoutOneTimeValue($public, 'one_time_result_url'),
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
                    'replay_body' => $this->withoutOneTimeValue($public, 'one_time_result_url'),
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

    private function requiredStationCredential(Request $request): string
    {
        $credential = trim((string) $request->header('X-Exam-Station-Credential'));
        if ($credential === '') {
            throw new ResourceDomainException(
                'STATION_AUTH_REQUIRED',
                409,
                'Authenticated exam station context is required for this operation.',
            );
        }

        return $credential;
    }

    private function stationCredentialOrInvalid(Request $request): string
    {
        $credential = trim((string) $request->header('X-Exam-Station-Credential'));
        if ($credential === '') {
            throw $this->invalidStationCredential();
        }

        return $credential;
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
    private function withoutOneTimeValue(array $body, string $key): array
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

    private function invalidStationCredential(): ResourceDomainException
    {
        return new ResourceDomainException(
            'INVALID_EXAM_STATION_CREDENTIAL',
            401,
            'Invalid or revoked exam station credential.',
        );
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
