<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OrganizationSettingsUiContractTest extends TestCase
{
    public function test_provider_neutral_settings_ui_preserves_existing_authority(): void
    {
        $root = dirname(__DIR__, 2);
        $app = (string) file_get_contents($root.'/resources/js/App.vue');
        $workspace = (string) file_get_contents($root.'/resources/js/modules/OrganizationSettings/SettingsWorkspace.vue');
        $routes = (string) file_get_contents($root.'/routes/web.php');

        self::assertStringContainsString("Route::view('/ustawienia', 'app');", $routes);
        self::assertStringContainsString("window.location.pathname === '/ustawienia'", $app);
        self::assertStringContainsString('<SettingsWorkspace v-else-if="isSettingsRoute" />', $app);

        self::assertStringContainsString("api<SettingsProjection>('/api/v1/organization/settings')", $workspace);
        self::assertStringContainsString("method: 'PATCH'", $workspace);
        self::assertStringContainsString('idempotent: true', $workspace);
        self::assertStringContainsString("'If-Match':", $workspace);
        self::assertStringContainsString(':value="email"', $workspace);
        self::assertStringContainsString('readonly', $workspace);

        self::assertStringNotContainsString('/api/v1/organization/integrations/pkk', $workspace);
        self::assertStringNotContainsString('pkk_api_data', $workspace);
        self::assertStringContainsString('PKK/PWPW jest opcjonalnym bounded contextem', $workspace);

        self::assertStringContainsString('term.document_url', $workspace);
        self::assertStringContainsString('safeDocumentUrl', $workspace);
        self::assertStringNotContainsString('/regulamin?v=', $workspace);
        self::assertStringContainsString('Treść tej wersji nie jest jeszcze dostępna online', $workspace);
    }
}
