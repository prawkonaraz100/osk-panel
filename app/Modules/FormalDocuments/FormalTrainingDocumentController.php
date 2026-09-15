<?php

namespace App\Modules\FormalDocuments;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceIdempotency;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class FormalTrainingDocumentController
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ResourceIdempotency $idempotency,
        private readonly FormalTrainingDocumentService $documents,
    ) {}

    public function preview(Request $request, string $courseEnrollmentId): JsonResponse
    {
        $input = $this->validatedQuery($request, [
            'document_type' => ['required', 'string', 'in:training_record_card,theory_delivery_journal'],
        ]);

        return response()->json($this->documents->preview(
            $this->sessionId($request),
            $courseEnrollmentId,
            (string) $input['document_type'],
        ));
    }

    public function list(Request $request, string $courseEnrollmentId): JsonResponse
    {
        return response()->json($this->documents->list(
            $this->sessionId($request),
            $courseEnrollmentId,
        ));
    }

    public function freshness(Request $request, string $courseEnrollmentId): JsonResponse
    {
        return response()->json($this->documents->freshness(
            $this->sessionId($request),
            $courseEnrollmentId,
        ));
    }

    public function approve(Request $request, string $courseEnrollmentId): JsonResponse
    {
        $input = $this->validatedBody($request, [
            'document_type' => ['required', 'string', 'in:training_record_card,theory_delivery_journal'],
            'requirements_revision' => ['required', 'integer', 'min:1'],
            'evidence_bundle_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'template_id' => ['required', 'uuid'],
            'template_version' => ['required', 'string', 'max:64'],
            'renderer_version' => ['required', 'string', 'max:64'],
            'template_content_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];
        $ifMatch = $request->header('If-Match');

        $result = $this->idempotency->execute(
            $organizationId,
            'formal_documents.approve',
            $this->idempotencyKey($request),
            [
                'course_enrollment_id' => $courseEnrollmentId,
                'if_match' => $ifMatch,
                ...$input,
            ],
            function () use ($sessionId, $courseEnrollmentId, $input, $request, $ifMatch): array {
                $body = $this->documents->approve(
                    $sessionId,
                    $courseEnrollmentId,
                    $input,
                    $this->requestId($request),
                    $ifMatch,
                );

                return [
                    'status' => 201,
                    'resource_type' => 'formal_training_document',
                    'resource_id' => (string) $body['id'],
                    'body' => $body,
                ];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function events(Request $request, string $documentId): JsonResponse
    {
        return response()->json($this->documents->events(
            $this->sessionId($request),
            $documentId,
        ));
    }

    public function deliveryEvent(Request $request, string $documentId): JsonResponse
    {
        $input = $this->validatedBody($request, [
            'event_type' => ['required', 'string', 'in:printed,signed_scan_attached,electronic_presented'],
            'optional_asset_id' => ['nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'formal_documents.delivery.record',
            $this->idempotencyKey($request),
            [
                'formal_training_document_id' => $documentId,
                ...$input,
            ],
            function () use ($sessionId, $documentId, $input, $request): array {
                $body = $this->documents->recordDeliveryEvent(
                    $sessionId,
                    $documentId,
                    $input,
                    $this->requestId($request),
                );

                return [
                    'status' => 201,
                    'resource_type' => 'formal_training_document_event',
                    'resource_id' => (string) $body['id'],
                    'body' => $body,
                ];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function download(Request $request, string $documentId): Response
    {
        $result = $this->documents->download(
            $this->sessionId($request),
            $documentId,
            $this->requestId($request),
        );

        return response($result['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'ETag' => '"sha256-'.$result['content_hash'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param  array<string,array<int,string>>  $rules
     * @return array<string,mixed>
     */
    private function validatedBody(Request $request, array $rules): array
    {
        return $this->strictValidate($request->all(), $rules);
    }

    /**
     * @param  array<string,array<int,string>>  $rules
     * @return array<string,mixed>
     */
    private function validatedQuery(Request $request, array $rules): array
    {
        return $this->strictValidate($request->query(), $rules);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,array<int,string>>  $rules
     * @return array<string,mixed>
     */
    private function strictValidate(array $input, array $rules): array
    {
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
