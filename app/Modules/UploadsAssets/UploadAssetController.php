<?php

namespace App\Modules\UploadsAssets;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceIdempotency;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UploadAssetController
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ResourceIdempotency $idempotency,
        private readonly UploadAssetService $uploads,
    ) {}

    public function presign(Request $request): JsonResponse
    {
        $input = $this->validatedBody($request, [
            'purpose' => ['required', 'string', 'max:64'],
            'filename' => ['required', 'string', 'max:255'],
            'declared_mime' => ['required', 'string', 'max:128'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'sha256' => ['sometimes', 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'parent_type' => ['required', 'string', 'in:organization,staff_profile,vehicle,formal_training_document'],
            'parent_id' => ['required', 'uuid'],
        ]);

        return response()->json(
            $this->uploads->presign($this->sessionId($request), $input),
            201,
        );
    }

    public function complete(Request $request, string $uploadId): JsonResponse
    {
        if (! Str::isUuid($uploadId)) {
            throw ValidationException::withMessages([
                'uploadId' => ['Upload id must be a UUID.'],
            ]);
        }

        $input = $this->validatedBody($request, [
            'sha256' => ['sometimes', 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'uploads.complete',
            $this->idempotencyKey($request),
            [
                'upload_id' => $uploadId,
                ...$input,
            ],
            function () use ($sessionId, $uploadId, $input, $request): array {
                try {
                    $body = $this->uploads->complete($sessionId, $uploadId, $input);

                    return [
                        'status' => 200,
                        'resource_type' => 'file_asset',
                        'resource_id' => (string) $body['id'],
                        'body' => $body,
                    ];
                } catch (UploadRejectedException $exception) {
                    return [
                        'status' => $exception->httpStatus,
                        'resource_type' => 'file_asset',
                        'resource_id' => $exception->assetId,
                        'body' => [
                            'error' => [
                                'code' => $exception->machineCode,
                                'message' => $exception->getMessage(),
                                'request_id' => $this->requestId($request),
                            ],
                        ],
                    ];
                }
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    /**
     * @param  array<string,array<int,string>>  $rules
     * @return array<string,mixed>
     */
    private function validatedBody(Request $request, array $rules): array
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
        if (! is_string($sessionId) || $sessionId === '') {
            throw new AuthenticationException('Authenticated application session required.');
        }

        return $sessionId;
    }

    private function requestId(Request $request): string
    {
        return (string) $request->attributes->get('request_id');
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
}
