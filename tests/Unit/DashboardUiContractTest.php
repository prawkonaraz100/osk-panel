<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DashboardUiContractTest extends TestCase
{
    public function test_main_dashboard_uses_real_projection_and_confirmed_navigation_contract(): void
    {
        $root = dirname(__DIR__, 2);
        $app = file_get_contents($root.'/resources/js/App.vue');
        $dashboard = file_get_contents($root.'/resources/js/modules/CommerceDashboard/DashboardWorkspace.vue');

        $this->assertIsString($app);
        $this->assertIsString($dashboard);

        $this->assertStringContainsString("const isDashboardRoute = window.location.pathname === '/'", $app);
        $this->assertStringContainsString('<DashboardWorkspace v-else-if="isDashboardRoute" />', $app);

        $this->assertStringContainsString("api<DashboardPayload>('/api/v1/dashboard')", $dashboard);
        $this->assertStringContainsString('Aktywne licencje', $dashboard);
        $this->assertStringContainsString('Dostępne licencje', $dashboard);
        $this->assertStringContainsString('Dostępne egzaminy', $dashboard);
        $this->assertStringContainsString('Powiadomienia', $dashboard);
        $this->assertStringContainsString('Pełny kalendarz', $dashboard);
        $this->assertStringContainsString('href="/kalendarz?action=create"', $dashboard);
        $this->assertStringContainsString("setCalendarView('month')", $dashboard);
        $this->assertStringContainsString("setCalendarView('week')", $dashboard);
        $this->assertStringContainsString("setCalendarView('day')", $dashboard);
        $this->assertStringContainsString('/api/v1/calendar/events?', $dashboard);

        $this->assertStringNotContainsString('15</strong>', $dashboard);
        $this->assertStringNotContainsString('93</strong>', $dashboard);
        $this->assertStringNotContainsString('160</strong>', $dashboard);
    }
}
