<?php

namespace App\Modules\ResourcesCore;

use App\Modules\IdentityTenant\TenantAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ResourceController
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ResourceIdempotency $idempotency,
        private readonly ResourceCatalogService $catalogs,
        private readonly LocationService $locations,
        private readonly StaffService $staff,
        private readonly VehicleService $vehicles,
    ) {}

    public function locationsList(Request $request): JsonResponse
    {
        return response()->json($this->locations->list($this->sessionId($request)));
    }

    public function locationsCreate(Request $request): JsonResponse
    {
        $input = $this->validated($request, [
            'type_code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'street_and_number' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city_reference' => ['required', 'string', 'max:160'],
        ]);
        /** @var array{type_code:string,name:string,street_and_number:string,postal_code:string,city_reference:string} $input */
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'locations.create',
            $this->idempotencyKey($request),
            $input,
            function () use ($sessionId, $input, $request): array {
                $body = $this->locations->create($sessionId, $input, $this->requestId($request));

                return ['status' => 201, 'resource_type' => 'location', 'resource_id' => (string) $body['id'], 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->locations->etag($result['body']));
    }

    public function locationsGet(Request $request, string $locationId): JsonResponse
    {
        $body = $this->locations->get($this->sessionId($request), $locationId);

        return response()->json($body)->header('ETag', $this->locations->etag($body));
    }

    public function locationsUpdate(Request $request, string $locationId): JsonResponse
    {
        $input = $this->validated($request, [
            'type_code' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'street_and_number' => ['sometimes', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'string', 'max:20'],
            'city_reference' => ['sometimes', 'string', 'max:160'],
        ]);

        $body = $this->locations->update(
            $this->sessionId($request),
            $locationId,
            $input,
            $this->requestId($request),
            $request->header('If-Match'),
        );

        return response()->json($body)->header('ETag', $this->locations->etag($body));
    }

    public function locationsArchive(Request $request, string $locationId): JsonResponse
    {
        $input = $this->validated($request, ['reason' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'locations.archive',
            $this->idempotencyKey($request),
            ['location_id' => $locationId, ...$input],
            function () use ($sessionId, $locationId, $input, $request): array {
                $body = $this->locations->archive(
                    $sessionId,
                    $locationId,
                    $this->requestId($request),
                    $input['reason'] ?? null,
                );

                return ['status' => 200, 'resource_type' => 'location', 'resource_id' => $locationId, 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function locationsRestore(Request $request, string $locationId): JsonResponse
    {
        $this->validated($request, []);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'locations.restore',
            $this->idempotencyKey($request),
            ['location_id' => $locationId],
            function () use ($sessionId, $locationId, $request): array {
                $body = $this->locations->restore($sessionId, $locationId, $this->requestId($request));

                return ['status' => 200, 'resource_type' => 'location', 'resource_id' => $locationId, 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function locationTypes(Request $request): JsonResponse
    {
        return response()->json($this->catalogs->locationTypes($this->sessionId($request)));
    }

    public function cities(Request $request): JsonResponse
    {
        return response()->json($this->catalogs->cities($this->sessionId($request), $this->nullableQuery($request, 'q')));
    }

    public function drivingCategories(Request $request): JsonResponse
    {
        return response()->json($this->catalogs->drivingCategories($this->sessionId($request)));
    }

    public function languages(Request $request): JsonResponse
    {
        return response()->json($this->catalogs->languages($this->sessionId($request)));
    }

    public function staffTypes(Request $request): JsonResponse
    {
        return response()->json($this->catalogs->staffTypes($this->sessionId($request)));
    }

    public function staffList(Request $request): JsonResponse
    {
        return response()->json($this->staff->list(
            $this->sessionId($request),
            $this->page($request),
            $this->perPage($request),
            $this->nullableQuery($request, 'q'),
            $this->nullableQuery($request, 'sort'),
            (string) $request->query('direction', 'asc'),
        ));
    }

    public function staffCreate(Request $request): JsonResponse
    {
        $input = $this->validated($request, $this->staffRules(true));
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'staff.create',
            $this->idempotencyKey($request),
            $input,
            function () use ($sessionId, $input, $request): array {
                $body = $this->staff->create($sessionId, $input, $this->requestId($request));

                return ['status' => 201, 'resource_type' => 'staff_profile', 'resource_id' => (string) $body['id'], 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->staff->etag($result['body']));
    }

    public function staffGet(Request $request, string $staffId): JsonResponse
    {
        $body = $this->staff->get($this->sessionId($request), $staffId);

        return response()->json($body)->header('ETag', $this->staff->etag($body));
    }

    public function staffUpdate(Request $request, string $staffId): JsonResponse
    {
        $body = $this->staff->update(
            $this->sessionId($request),
            $staffId,
            $this->validated($request, $this->staffRules(false)),
            $this->requestId($request),
            $request->header('If-Match'),
        );

        return response()->json($body)->header('ETag', $this->staff->etag($body));
    }

    public function staffArchive(Request $request, string $staffId): JsonResponse
    {
        $input = $this->validated($request, ['reason' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'staff.archive',
            $this->idempotencyKey($request),
            ['staff_id' => $staffId, ...$input],
            function () use ($sessionId, $staffId, $input, $request): array {
                $body = $this->staff->archive(
                    $sessionId,
                    $staffId,
                    $this->requestId($request),
                    $input['reason'] ?? null,
                );

                return ['status' => 200, 'resource_type' => 'staff_profile', 'resource_id' => $staffId, 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function staffRestore(Request $request, string $staffId): JsonResponse
    {
        $this->validated($request, []);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'staff.restore',
            $this->idempotencyKey($request),
            ['staff_id' => $staffId],
            function () use ($sessionId, $staffId, $request): array {
                $body = $this->staff->restore($sessionId, $staffId, $this->requestId($request));

                return ['status' => 200, 'resource_type' => 'staff_profile', 'resource_id' => $staffId, 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function staffAccountCreate(Request $request, string $staffId): JsonResponse
    {
        $this->validated($request, []);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'staff.account.create',
            $this->idempotencyKey($request),
            ['staff_id' => $staffId],
            function () use ($sessionId, $staffId, $request): array {
                $body = $this->staff->createPanelAccount($sessionId, $staffId, $this->requestId($request));

                return ['status' => 201, 'resource_type' => 'staff_profile', 'resource_id' => $staffId, 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function staffAccountRevoke(Request $request, string $staffId): Response
    {
        $this->validated($request, []);
        $this->staff->revokePanelAccount($this->sessionId($request), $staffId, $this->requestId($request));

        return response()->noContent();
    }

    public function staffPermissionsGet(Request $request, string $staffId): JsonResponse
    {
        return response()->json($this->staff->permissions($this->sessionId($request), $staffId));
    }

    public function staffPermissionsReplace(Request $request, string $staffId): JsonResponse
    {
        $input = $this->validated($request, [
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'max:128'],
        ]);
        /** @var list<string> $permissions */
        $permissions = array_values($input['permissions']);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'staff.permissions.replace',
            $this->idempotencyKey($request),
            ['staff_id' => $staffId, 'permissions' => $permissions],
            function () use ($sessionId, $staffId, $permissions, $request): array {
                $body = $this->staff->replacePermissions(
                    $sessionId,
                    $staffId,
                    $permissions,
                    $this->requestId($request),
                );

                return ['status' => 200, 'resource_type' => 'staff_permissions', 'resource_id' => $staffId, 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function vehiclesList(Request $request): JsonResponse
    {
        return response()->json($this->vehicles->list(
            $this->sessionId($request),
            $this->page($request),
            $this->perPage($request),
            $this->nullableQuery($request, 'q'),
            $this->nullableQuery($request, 'sort'),
            (string) $request->query('direction', 'asc'),
        ));
    }

    public function vehiclesCreate(Request $request): JsonResponse
    {
        $input = $this->validated($request, $this->vehicleRules(true));
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'vehicles.create',
            $this->idempotencyKey($request),
            $input,
            function () use ($sessionId, $input, $request): array {
                $body = $this->vehicles->create($sessionId, $input, $this->requestId($request));

                return ['status' => 201, 'resource_type' => 'vehicle', 'resource_id' => (string) $body['id'], 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status'])
            ->header('ETag', $this->vehicles->etag($result['body']));
    }

    public function vehiclesGet(Request $request, string $vehicleId): JsonResponse
    {
        $body = $this->vehicles->get($this->sessionId($request), $vehicleId);

        return response()->json($body)->header('ETag', $this->vehicles->etag($body));
    }

    public function vehiclesUpdate(Request $request, string $vehicleId): JsonResponse
    {
        $body = $this->vehicles->update(
            $this->sessionId($request),
            $vehicleId,
            $this->validated($request, $this->vehicleRules(false)),
            $this->requestId($request),
            $request->header('If-Match'),
        );

        return response()->json($body)->header('ETag', $this->vehicles->etag($body));
    }

    public function vehiclesArchive(Request $request, string $vehicleId): JsonResponse
    {
        $input = $this->validated($request, ['reason' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'vehicles.archive',
            $this->idempotencyKey($request),
            ['vehicle_id' => $vehicleId, ...$input],
            function () use ($sessionId, $vehicleId, $input, $request): array {
                $body = $this->vehicles->archive(
                    $sessionId,
                    $vehicleId,
                    $this->requestId($request),
                    $input['reason'] ?? null,
                );

                return ['status' => 200, 'resource_type' => 'vehicle', 'resource_id' => $vehicleId, 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function vehiclesRestore(Request $request, string $vehicleId): JsonResponse
    {
        $this->validated($request, []);
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'vehicles.restore',
            $this->idempotencyKey($request),
            ['vehicle_id' => $vehicleId],
            function () use ($sessionId, $vehicleId, $request): array {
                $body = $this->vehicles->restore($sessionId, $vehicleId, $this->requestId($request));

                return ['status' => 200, 'resource_type' => 'vehicle', 'resource_id' => $vehicleId, 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function vehicleDocumentsList(Request $request, string $vehicleId): JsonResponse
    {
        return response()->json($this->vehicles->documents($this->sessionId($request), $vehicleId));
    }

    public function vehicleDocumentsCreate(Request $request, string $vehicleId): JsonResponse
    {
        $input = $this->validated($request, $this->vehicleDocumentRules(true));
        $sessionId = $this->sessionId($request);
        $organizationId = $this->tenantAuthorizer->activeMembershipForSession($sessionId)['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'vehicles.documents.create',
            $this->idempotencyKey($request),
            ['vehicle_id' => $vehicleId, ...$input],
            function () use ($sessionId, $vehicleId, $input, $request): array {
                $body = $this->vehicles->replaceDocument(
                    $sessionId,
                    $vehicleId,
                    (string) $input['document_type'],
                    $input['valid_until'] ?? null,
                    $input['asset_id'] ?? null,
                    $this->requestId($request),
                );

                return ['status' => 201, 'resource_type' => 'vehicle_document', 'resource_id' => (string) $body['id'], 'body' => $body];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function vehicleDocumentsUpdate(Request $request, string $vehicleId, string $documentId): JsonResponse
    {
        $input = $this->validated($request, $this->vehicleDocumentRules(false));
        $sessionId = $this->sessionId($request);
        $documents = $this->vehicles->documents($sessionId, $vehicleId);
        $current = collect($documents)->firstWhere('id', $documentId);

        if (! is_array($current)) {
            throw ResourceDomainException::notFound();
        }
        if (isset($input['document_type']) && $input['document_type'] !== $current['document_type']) {
            throw ResourceDomainException::rule('Changing the vehicle document type in-place is not supported.');
        }

        $tag = $request->header('If-Match');
        if ($tag !== null && ! hash_equals(
            '"'.hash('sha256', json_encode($current, JSON_THROW_ON_ERROR)).'"',
            trim($tag),
        )) {
            throw ResourceDomainException::conflict('Vehicle document changed since it was loaded.');
        }

        $validUntil = array_key_exists('valid_until', $input) ? $input['valid_until'] : $current['valid_until'];
        $assetId = array_key_exists('asset_id', $input) ? $input['asset_id'] : $current['asset_id'];
        if ($validUntil === null && $assetId === null) {
            throw ResourceDomainException::rule('Vehicle document must retain a validity date or asset.');
        }

        $body = $this->vehicles->replaceDocument(
            $sessionId,
            $vehicleId,
            (string) $current['document_type'],
            $validUntil,
            $assetId,
            $this->requestId($request),
        );

        return response()->json($body)
            ->header('ETag', '"'.hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)).'"');
    }

    /** @param array<string,array<int,string>> $rules
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
    private function staffRules(bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';

        return [
            'email' => [$required, 'email', 'max:320'],
            'first_name' => [$required, 'string', 'max:120'],
            'last_name' => [$required, 'string', 'max:120'],
            'staff_type_codes' => [$required, 'array', 'min:1'],
            'staff_type_codes.*' => ['string', 'max:64'],
            'pesel' => ['sometimes', 'nullable', 'string', 'max:32'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'authorization_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['uuid'],
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['uuid'],
            'card_valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'medical_exam_valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'psychological_exam_valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'photo_asset_id' => ['sometimes', 'nullable', 'uuid'],
            'create_login_account' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string,array<int,string>> */
    private function vehicleRules(bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';

        return [
            'registration_number' => [$required, 'string', 'max:32'],
            'side_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'make' => [$required, 'string', 'max:120'],
            'model' => [$required, 'string', 'max:120'],
            'production_year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'engine_capacity_cm3' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'vin' => ['sometimes', 'nullable', 'string', 'max:32'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['uuid'],
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['uuid'],
            'next_inspection_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'oc_valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ac_valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'photo_asset_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /** @return array<string,array<int,string>> */
    private function vehicleDocumentRules(bool $create): array
    {
        return [
            'document_type' => [$create ? 'required' : 'sometimes', 'string', 'max:64'],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'asset_id' => ['sometimes', 'nullable', 'uuid'],
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

    private function page(Request $request): int
    {
        return max(1, (int) $request->query('page', 1));
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->query('per_page', 25)));
    }

    private function nullableQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
