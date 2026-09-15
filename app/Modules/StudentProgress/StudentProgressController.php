<?php

namespace App\Modules\StudentProgress;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class StudentProgressController
{
    public function __construct(
        private readonly StudentProgressService $progress,
    ) {}

    public function get(Request $request, string $studentId): JsonResponse
    {
        $input = Validator::make($request->query(), [
            'learning_account_id' => ['required', 'uuid'],
            'category' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9+]+$/'],
        ])->validate();

        return response()->json($this->progress->get(
            $this->sessionId($request),
            $studentId,
            (string) $input['learning_account_id'],
            (string) $input['category'],
        ));
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
