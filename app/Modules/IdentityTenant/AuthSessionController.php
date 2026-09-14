<?php

namespace App\Modules\IdentityTenant;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class AuthSessionController
{
    public function __construct(
        private readonly AuthSessionService $sessions,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $input = $this->validated($request, [
            'identifier' => ['required', 'string', 'max:320'],
            'password' => ['required', 'string', 'max:1024'],
            'remember_me' => ['sometimes', 'boolean'],
            'return_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);
        $returnUrl = $this->sessions->safeReturnUrl($input['return_url'] ?? null);
        $credentials = $this->sessions->verifyCredentials(
            (string) $input['identifier'],
            (string) $input['password'],
            $request->ip(),
        );

        $previous = $request->session()->get('auth_session_id');
        if (is_string($previous) && $previous !== '') {
            $this->sessions->revokeCurrent($previous, 'replaced_by_login');
        }

        $request->session()->regenerate();

        try {
            $session = $this->sessions->startSession(
                $credentials['user_id'],
                $credentials['organization_membership_id'],
                $request->session()->getId(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (\Throwable $exception) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw $exception;
        }

        $request->session()->put('auth_session_id', $session['id']);
        $request->session()->put('auth_remember_me', (bool) ($input['remember_me'] ?? false));

        return response()->json([
            ...$session,
            'return_url' => $returnUrl,
        ]);
    }

    public function logout(Request $request): Response
    {
        $sessionId = $request->session()->get('auth_session_id');
        if (is_string($sessionId) && $sessionId !== '') {
            $this->sessions->revokeCurrent($sessionId);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function list(Request $request): JsonResponse
    {
        return response()->json($this->sessions->listOwn($this->sessionId($request)));
    }

    public function revoke(Request $request, string $sessionId): Response
    {
        $currentSessionId = $this->sessionId($request);
        $result = $this->sessions->revokeOwn($currentSessionId, $sessionId);

        if ($result['revoked_current']) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /**
     * @param  array<string,array<int,string>>  $rules
     * @return array<string,mixed>
     */
    private function validated(Request $request, array $rules): array
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

        if (! DB::table('auth_sessions')->where('id', $sessionId)->whereNull('revoked_at')->exists()) {
            throw new AuthenticationException('Authenticated application session required.');
        }

        return $sessionId;
    }
}
