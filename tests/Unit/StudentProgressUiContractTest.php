<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class StudentProgressUiContractTest extends TestCase
{
    public function test_student_progress_tab_is_enabled_and_truthful_unavailable_states_are_rendered(): void
    {
        $root = dirname(__DIR__, 2);
        $workspace = (string) file_get_contents($root.'/resources/js/modules/StudentsCourses/StudentCourseWorkspace.vue');
        $panel = (string) file_get_contents($root.'/resources/js/modules/StudentProgress/StudentProgressPanel.vue');

        self::assertStringContainsString("detailTab === 'progress'", $workspace);
        self::assertStringContainsString("@click=\"detailTab = 'progress'\"", $workspace);
        self::assertStringContainsString('<StudentProgressPanel', $workspace);
        self::assertStringContainsString("progress.projection_state === 'unbound'", $panel);
        self::assertStringContainsString("progress.projection_state === 'source_unavailable'", $panel);
        self::assertStringContainsString("progress.projection_state === 'stale'", $panel);
        self::assertStringContainsString('Nie zastępujemy brakujących danych zerami.', $panel);
        self::assertStringContainsString("state === 'outside_core_v1'", $panel);
        self::assertStringNotContainsString('2185', $panel);
        self::assertStringNotContainsString('774', $panel);
    }
}
