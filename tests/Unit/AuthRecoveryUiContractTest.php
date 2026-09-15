<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AuthRecoveryUiContractTest extends TestCase
{
    public function test_auth_recovery_ui_uses_existing_authority_without_secret_persistence(): void
    {
        $root = dirname(__DIR__, 2);
        $app = (string) file_get_contents($root.'/resources/js/App.vue');
        $workspace = (string) file_get_contents($root.'/resources/js/modules/IdentityTenant/AuthWorkspace.vue');
        $api = (string) file_get_contents($root.'/resources/js/modules/ResourcesCore/api.ts');
        $routes = (string) file_get_contents($root.'/routes/web.php');
        $spec = (string) file_get_contents($root.'/specs/design/auth-recovery-ui.yml');

        foreach (['/login', '/forgot-password', '/reset-password'] as $route) {
            self::assertStringContainsString("Route::view('".$route."', 'app');", $routes);
            self::assertStringContainsString("'".$route."'", $app);
        }

        self::assertStringContainsString('<AuthWorkspace v-if="isAuthRoute" />', $app);
        self::assertStringContainsString('/api/v1/auth/login', $workspace);
        self::assertStringContainsString('/api/v1/auth/password/forgot', $workspace);
        self::assertStringContainsString('/api/v1/auth/password/reset', $workspace);
        self::assertStringContainsString("searchParams.get('token')", $workspace);
        self::assertStringContainsString("window.history.replaceState({}, '', '/reset-password')", $workspace);
        self::assertStringContainsString('Jeśli konto kwalifikuje się do samodzielnego resetu', $workspace);
        self::assertStringNotContainsString('localStorage', $workspace);
        self::assertStringNotContainsString('sessionStorage', $workspace);
        self::assertStringNotContainsString('/api/v1/auth/register', $workspace);
        self::assertStringContainsString("rawBody.trim() === ''", $api);
        self::assertStringContainsString('response.status === 401', $api);
        self::assertStringContainsString("return '/login?return_url=' + encodeURIComponent(returnUrl)", $api);
        self::assertStringContainsString('hardcoded_terms_version: forbidden', $spec);
        self::assertStringContainsString('PKK_PWPW: FROZEN_UNTIL_EXPLICIT_UNFREEZE', $spec);
    }
}
