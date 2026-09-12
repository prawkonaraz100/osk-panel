<?php

namespace App\Modules\CommerceDashboard;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardController
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    public function get(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->get($this->sessionId($request)));
    }

    private function sessionId(Request $request): string
    {
        $sessionId = $request->session()->get('auth_session_id');
        if (is_string($sessionId) === false || $sessionId === '') {
            throw new AuthenticationException('Authenticated application session required.');
        }

        return $sessionId;
    }
}
