<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CourseCompletionUiContractTest extends TestCase
{
    public function test_training_completed_reuses_backend_stage_transition_without_client_side_formal_authority(): void
    {
        $root = dirname(__DIR__, 2);
        $workspace = file_get_contents($root.'/resources/js/modules/StudentsCourses/StudentCourseWorkspace.vue');

        $this->assertIsString($workspace);
        $this->assertStringContainsString('/stage-transitions', $workspace);
        $this->assertStringContainsString("'If-Match'", $workspace);
        $this->assertStringContainsString(
            "target === 'training_completed' ? 'Szkolenie zostało zakończone.'",
            $workspace,
        );
        $this->assertStringNotContainsString(
            'Zakończenie szkolenia zostanie odblokowane po podpięciu ewidencji godzin i egzaminu wewnętrznego.',
            $workspace,
        );
        $this->assertStringNotContainsString("if (target === 'training_completed') {", $workspace);
    }
}
