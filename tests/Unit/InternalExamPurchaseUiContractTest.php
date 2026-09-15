<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InternalExamPurchaseUiContractTest extends TestCase
{
    public function test_internal_exam_purchase_ui_uses_server_offer_and_existing_order_authority(): void
    {
        $root = dirname(__DIR__, 2);
        $app = (string) file_get_contents($root.'/resources/js/App.vue');
        $workspace = (string) file_get_contents($root.'/resources/js/modules/InternalExams/InternalExamPurchaseWorkspace.vue');
        $management = (string) file_get_contents($root.'/resources/js/modules/InternalExams/InternalExamWorkspace.vue');
        $routes = (string) file_get_contents($root.'/routes/web.php');
        $spec = (string) file_get_contents($root.'/specs/screens/internal-exam-purchase.yml');
        $api = (string) file_get_contents($root.'/specs/api/paths/internal-exams.yaml');

        self::assertStringContainsString("Route::view('/egzamin-wewnetrzny/wykup', 'app');", $routes);
        self::assertStringContainsString("Route::get('/internal-exam/purchase-offer'", $routes);
        self::assertStringContainsString("window.location.pathname === '/egzamin-wewnetrzny/wykup'", $app);
        self::assertStringContainsString('<InternalExamPurchaseWorkspace v-else-if="isInternalExamPurchaseRoute" />', $app);
        self::assertStringContainsString('href="/egzamin-wewnetrzny/wykup"', $management);

        self::assertStringContainsString('/api/v1/internal-exam/purchase-offer', $workspace);
        self::assertStringContainsString('/api/v1/internal-exam/orders', $workspace);
        self::assertStringContainsString('idempotent: true', $workspace);
        self::assertStringContainsString('quantity: normalizedQuantity.value', $workspace);
        self::assertStringContainsString('payment_method: paymentMethod.value', $workspace);
        self::assertStringContainsString('result.data.total.amount_minor', $workspace);
        self::assertStringContainsString('offer?.sample_data', $workspace);
        self::assertStringContainsString('value="bank_transfer"', $workspace);
        self::assertStringContainsString('value="online_payment"', $workspace);

        self::assertStringNotContainsString('list_unit_amount_minor', $workspace);
        self::assertStringNotContainsString('charged_unit_amount_minor', $workspace);
        self::assertStringNotContainsString('vat_rate_basis_points', $workspace);
        self::assertStringNotContainsString('1.23', $workspace);
        self::assertStringNotContainsString('62.73', $workspace);
        self::assertStringNotContainsString('PayU', $workspace);

        self::assertStringContainsString('gate: INTERNAL-EXAM-PURCHASE-UI-001', $spec);
        self::assertStringContainsString('browser_price_authority: forbidden', $spec);
        self::assertStringContainsString('provider_specific_online_payment_runtime: deferred', $spec);

        self::assertStringContainsString('/internal-exam/purchase-offer:', $api);
        self::assertStringContainsString('same_server_pricing_catalog_as_exam_orders_create', $api);
        self::assertStringContainsString('client_price_authority_forbidden', $api);
    }
}
