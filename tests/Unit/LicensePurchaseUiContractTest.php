<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LicensePurchaseUiContractTest extends TestCase
{
    public function test_license_purchase_ui_uses_server_pricing_and_existing_order_authority(): void
    {
        $root = dirname(__DIR__, 2);
        $app = (string) file_get_contents($root.'/resources/js/App.vue');
        $workspace = (string) file_get_contents($root.'/resources/js/modules/LearningAccess/LicensePurchaseWorkspace.vue');
        $management = (string) file_get_contents($root.'/resources/js/modules/LearningAccess/LearningAccessWorkspace.vue');
        $routes = (string) file_get_contents($root.'/routes/web.php');
        $spec = (string) file_get_contents($root.'/specs/screens/license-purchase.yml');

        self::assertStringContainsString("Route::view('/licencje/wykup', 'app');", $routes);
        self::assertStringContainsString("window.location.pathname === '/licencje/wykup'", $app);
        self::assertStringContainsString('<LicensePurchaseWorkspace v-else-if="isLicensePurchaseRoute" />', $app);
        self::assertStringContainsString('href="/licencje/wykup"', $management);

        self::assertStringContainsString('/api/v1/license-products', $workspace);
        self::assertStringContainsString('/api/v1/license-orders', $workspace);
        self::assertStringContainsString('idempotent: true', $workspace);
        self::assertStringContainsString('product_id: line.product.id', $workspace);
        self::assertStringContainsString('quantity: line.quantity', $workspace);
        self::assertStringContainsString('payment_method: paymentMethod.value', $workspace);
        self::assertStringContainsString('result.data.total.amount_minor', $workspace);
        self::assertStringContainsString('product.sample_data', $workspace);
        self::assertStringContainsString("value=\"bank_transfer\"", $workspace);
        self::assertStringContainsString("value=\"online_payment\"", $workspace);

        self::assertStringNotContainsString('list_unit_amount_minor', $workspace);
        self::assertStringNotContainsString('charged_unit_amount_minor', $workspace);
        self::assertStringNotContainsString('14.50', $workspace);
        self::assertStringNotContainsString('19.00', $workspace);
        self::assertStringNotContainsString('29.50', $workspace);
        self::assertStringNotContainsString('PayU', $workspace);

        self::assertStringContainsString('current_runtime_UI_status: CANDIDATE_PR', $spec);
        self::assertStringContainsString('browser_price_authority: forbidden', $spec);
        self::assertStringContainsString('provider_specific_online_payment_runtime: deferred', $spec);
    }
}
