<?php

namespace App\Modules\IdentityTenant;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

final class SocialAuthController
{
    public function __construct(
        private readonly SocialAuthService $social,
        private readonly AuthSessionService $sessions,
    ) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $binding = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $request->session()->put('social_oauth_binding', $binding);

        $url = $this->social->begin(
            $provider,
            $binding,
            $this->sessionId($request),
            $request->query('return_url'),
        );

        return redirect()->away($url);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $binding = $request->session()->get('social_oauth_binding');
        if (! is_string($binding) || $binding === '') {
            return redirect()->to($this->errorUrl('/'));
        }

        try {
            $result = $this->social->callback(
                $provider,
                $binding,
                $this->sessionId($request),
                $request->query('state'),
                $request->query('code'),
                $request->query('error'),
            );
        } catch (SocialAuthFlowException $exception) {
            $request->session()->forget('social_oauth_binding');

            return redirect()->to($this->errorUrl($exception->returnUrl));
        }

        $request->session()->forget('social_oauth_pending');

        if ($result['mode'] === 'authenticated_link') {
            return redirect()->to($result['return_url']);
        }

        $previous = $this->sessionId($request);
        if ($previous !== null) {
            $this->sessions->revokeCurrent($previous, 'replaced_by_social_login');
        }

        $request->session()->regenerate();

        try {
            $session = $this->sessions->startSession(
                $result['user_id'],
                $result['organization_membership_id'],
                $request->session()->getId(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (Throwable $exception) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw $exception;
        }

        $request->session()->put('auth_session_id', $session['id']);
        $request->session()->put('auth_remember_me', false);

        return redirect()->to($result['return_url']);
    }

    private function sessionId(Request $request): ?string
    {
        $sessionId = $request->session()->get('auth_session_id');

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    private function errorUrl(string $returnUrl): string
    {
        $fragment = '';
        $base = $returnUrl;
        $position = strpos($base, '#');
        if ($position !== false) {
            $fragment = substr($base, $position);
            $base = substr($base, 0, $position);
        }

        return $base.(str_contains($base, '?') ? '&' : '?').'social_auth=error'.$fragment;
    }
}
