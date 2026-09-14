<?php

namespace App\Modules\OrganizationSettings;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\ResourcesCore\ResourceIdempotency;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OrganizationSettingsController
{
    public function __construct(
        private readonly OrganizationSettingsService $settings,
        private readonly ResourceIdempotency $idempotency,
        private readonly TenantAuthorizer $authorizer,
    ) {}

    public function organizationGet(Request $request): JsonResponse
    {
        return response()->json(
            $this->settings->organization($this->sessionId($request)),
        );
    }

    public function organizationUpdate(Request $request): JsonResponse
    {
        $input = $this->validateOnly($request->all(), [
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'nip' => ['sometimes', 'nullable', 'string', 'max:16'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'timezone' => ['sometimes', 'timezone'],
        ]);

        if ($input === []) {
            throw ValidationException::withMessages([
                'request' => ['At least one organization field is required.'],
            ]);
        }

        return response()->json(
            $this->settings->updateOrganization(
                $this->sessionId($request),
                $this->expectedVersion($request, 'organization settings'),
                $input,
                $this->requestId($request),
            ),
        );
    }

    public function settingsGet(Request $request): JsonResponse
    {
        return response()->json(
            $this->settings->settings($this->sessionId($request)),
        );
    }

    public function settingsUpdate(Request $request): JsonResponse
    {
        $input = $this->validateOnly($request->all(), [
            'basic_data' => ['sometimes', 'array:first_name,last_name'],
            'basic_data.first_name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'basic_data.last_name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'company_data' => ['sometimes', 'array:company_name,street,house_number,unit_number,city,postal_code,phone'],
            'company_data.company_name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'company_data.street' => ['sometimes', 'string', 'min:1', 'max:255'],
            'company_data.house_number' => ['sometimes', 'string', 'min:1', 'max:32'],
            'company_data.unit_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'company_data.city' => ['sometimes', 'string', 'min:1', 'max:160'],
            'company_data.postal_code' => ['sometimes', 'string', 'min:1', 'max:20'],
            'company_data.phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $changes = $this->flattenSettingsInput($input);
        $sessionId = $this->sessionId($request);
        $membership = $this->authorizer->activeMembershipForSession($sessionId);
        $expectedVersion = $this->expectedVersion($request, 'organization settings');
        $organizationId = $membership['organization_id'];

        $result = $this->idempotency->execute(
            $organizationId,
            'organization.settings.update',
            $this->idempotencyKey($request),
            ['expected_version' => $expectedVersion, 'changes' => $changes],
            function () use ($sessionId, $expectedVersion, $changes, $request, $organizationId): array {
                $body = $this->settings->update(
                    $sessionId,
                    $expectedVersion,
                    $changes,
                    $this->requestId($request),
                );

                return [
                    'status' => 200,
                    'resource_type' => 'organization_settings',
                    'resource_id' => $organizationId,
                    'body' => $body,
                ];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function acceptedTermsGet(Request $request): JsonResponse
    {
        return response()->json(
            $this->settings->acceptedTerms($this->sessionId($request)),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, array<int, string>>  $rules
     * @return array<string, mixed>
     */
    private function validateOnly(array $input, array $rules): array
    {
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

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function flattenSettingsInput(array $input): array
    {
        $changes = [];

        $basic = $input['basic_data'] ?? [];
        if (is_array($basic)) {
            foreach (['first_name', 'last_name'] as $field) {
                if (array_key_exists($field, $basic)) {
                    $changes[$field] = $basic[$field];
                }
            }
        }

        $company = $input['company_data'] ?? [];
        if (is_array($company)) {
            if (array_key_exists('company_name', $company)) {
                $changes['company_name'] = $company['company_name'];
            }
            if (array_key_exists('phone', $company)) {
                $changes['phone'] = $company['phone'];
            }

            $address = [];
            foreach ([
                'street' => 'street',
                'house_number' => 'house_number',
                'unit_number' => 'unit_number',
                'postal_code' => 'postal_code',
                'city' => 'city_name',
            ] as $requestField => $serviceField) {
                if (array_key_exists($requestField, $company)) {
                    $address[$serviceField] = $company[$requestField];
                }
            }
            if ($address !== []) {
                $changes['address'] = $address;
            }
        }

        if ($changes === []) {
            throw ValidationException::withMessages([
                'request' => ['At least one provider-neutral settings field is required.'],
            ]);
        }

        return $changes;
    }

    private function sessionId(Request $request): string
    {
        $sessionId = $request->session()->get('auth_session_id');
        if (! is_string($sessionId) || $sessionId === '') {
            throw new AuthenticationException('Authenticated application session required.');
        }

        return $sessionId;
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
