<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DashboardPurchaseHistoryUiContractTest extends TestCase
{
    public function test_dashboard_and_purchase_history_use_accepted_runtime_without_fabricating_checkout(): void
    {
        $root = dirname(__DIR__, 2);
        $app = file_get_contents($root.'/resources/js/App.vue');
        $dashboard = file_get_contents($root.'/resources/js/modules/CommerceDashboard/DashboardWorkspace.vue');
        $purchases = file_get_contents($root.'/resources/js/modules/CommerceDashboard/PurchaseHistoryWorkspace.vue');
        $routes = file_get_contents($root.'/routes/web.php');

        $this->assertIsString($app);
        $this->assertIsString($dashboard);
        $this->assertIsString($purchases);
        $this->assertIsString($routes);

        $this->assertStringContainsString("window.location.pathname === '/'", $app);
        $this->assertStringContainsString("window.location.pathname === '/historia-zakupow'", $app);
        $this->assertStringContainsString("Route::view('/historia-zakupow', 'app');", $routes);

        $this->assertStringContainsString("api<DashboardProjection>('/api/v1/dashboard')", $dashboard);
        $this->assertStringContainsString('/api/v1/calendar/events?', $dashboard);
        $this->assertStringContainsString('Miesiąc', $dashboard);
        $this->assertStringContainsString('Tydzień', $dashboard);
        $this->assertStringContainsString('Dzień', $dashboard);
        $this->assertStringContainsString('Kup licencje', $dashboard);
        $this->assertStringContainsString('disabled', $dashboard);

        $this->assertStringContainsString('const PAGE_SIZES = [10, 25, 50, 100] as const', $purchases);
        $this->assertStringContainsString('/api/v1/purchase-history?', $purchases);
        $this->assertStringContainsString('/api/v1/orders/${order.id}/payments', $purchases);
        $this->assertStringContainsString("method: 'bank_transfer'", $purchases);
        $this->assertStringContainsString("order.status === 'unpaid'", $purchases);
        $this->assertStringContainsString('public_payment_reference', $purchases);

        $this->assertStringNotContainsString('/payment-webhooks/', $dashboard);
        $this->assertStringNotContainsString('/payment-webhooks/', $purchases);
        $this->assertStringNotContainsString("status: 'paid'", $purchases);
    }
}
