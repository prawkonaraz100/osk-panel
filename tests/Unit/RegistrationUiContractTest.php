<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RegistrationUiContractTest extends TestCase
{
    public function test_registration_ui_uses_discovered_legal_authority_without_hardcoded_terms(): void
    {
        $root = dirname(__DIR__, 2);
        $app = (string) file_get_contents($root.'/resources/js/App.vue');
        $workspace = (string) file_get_contents($root.'/resources/js/modules/IdentityTenant/RegistrationWorkspace.vue');
        $authWorkspace = (string) file_get_contents($root.'/resources/js/modules/IdentityTenant/AuthWorkspace.vue');
        $api = (string) file_get_contents($root.'/resources/js/modules/ResourcesCore/api.ts');
        $routes = (string) file_get_contents($root.'/routes/web.php');
        $spec = (string) file_get_contents($root.'/specs/design/auth-registration.yml');

        self::assertStringContainsString("Route::view('/register', 'app');", $routes);
        self::assertStringContainsString("window.location.pathname === '/register'", $app);
        self::assertStringContainsString('<RegistrationWorkspace v-if="isRegistrationRoute" />', $app);
        self::assertStringContainsString('href="/register"', $authWorkspace);

        self::assertStringContainsString('/api/v1/development/sample/legal/terms/current', $workspace);
        self::assertStringContainsString('/api/v1/auth/register', $workspace);
        self::assertStringContainsString('accepted_terms_version: terms.value.version', $workspace);
        self::assertStringContainsString('marketing_consent: false', $workspace);
        self::assertStringContainsString('termsAccepted', $workspace);
        self::assertStringContainsString('error.status === 404', $workspace);

        self::assertStringNotContainsString('sample-terms-v1', $workspace);
        self::assertStringNotContainsString('localStorage', $workspace);
        self::assertStringNotContainsString('sessionStorage', $workspace);
        self::assertStringNotContainsString('marketing_consent_version', $workspace);

        self::assertStringContainsString("'/register'", $api);
        self::assertStringContainsString('production_legal_document_still_required_before_go_live: true', $spec);
        self::assertStringContainsString('registration_bypass: false', $spec);
    }
}
