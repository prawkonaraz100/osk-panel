<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PurchaseHistoryUiContractTest extends TestCase
{
    public function test_purchase_history_renders_confirmed_columns_page_sizes_and_safe_pay_action(): void
    {
        $root = dirname(__DIR__, 2);
        $app = file_get_contents($root.'/resources/js/App.vue');
        $routes = file_get_contents($root.'/routes/web.php');
        $workspace = file_get_contents($root.'/resources/js/modules/CommerceDashboard/PurchaseHistoryWorkspace.vue');

        $this->assertIsString($app);
        $this->assertIsString($routes);
        $this->assertIsString($workspace);

        $this->assertStringContainsString("const isPurchaseHistoryRoute = window.location.pathname === '/historia-zakupow'", $app);
        $this->assertStringContainsString("Route::view('/historia-zakupow', 'app');", $routes);
        $this->assertStringContainsString('/api/v1/purchase-history?', $workspace);

        foreach (['Number', 'Zamówienie', 'Data księgowania', 'Kwota', 'Status', 'Opłać'] as $label) {
            $this->assertStringContainsString($label, $workspace);
        }

        foreach ([':value="10"', ':value="25"', ':value="50"', ':value="100"'] as $pageSize) {
            $this->assertStringContainsString($pageSize, $workspace);
        }

        $this->assertStringContainsString("order.status === 'unpaid'", $workspace);
        $this->assertStringContainsString("'/api/v1/orders/' + order.id + '/payments'", $workspace);
        $this->assertStringContainsString('idempotent: true', $workspace);
        $this->assertStringContainsString("method: 'bank_transfer'", $workspace);
        $this->assertStringContainsString("result.data.status !== 'pending'", $workspace);
        $this->assertStringContainsString('pozostaje nieopłacone do czasu wiarygodnego potwierdzenia', $workspace);
        $this->assertStringNotContainsString("order.status = 'completed'", $workspace);
    }
}
