<?php

namespace App\Modules\OrganizationSettings;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrganizationSettingsController
{
    public function __construct(
        private readonly OrganizationSettingsService $settings,
    ) {}

    public function organizationGet(Request $request): JsonResponse
    {
        return response()->json(
            $this->settings->organization($this->sessionId($request)),
        );
    }

    public function acceptedTermsGet(Request $request): JsonResponse
    {
        return response()->json(
            $this->settings->acceptedTerms($this->sessionId($request)),
        );
    }

    private function sessionId(Request $request): string
    {
        $sessionId = $request->session()->get('auth_session_id');
        if (! is_string($sessionId) || $sessionId === '') {
            throw new AuthenticationException('Authenticated application session required.');
        }

        return $sessionId;
    }
}
