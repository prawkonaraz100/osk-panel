<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InternalExamUiContractTest extends TestCase
{
    public function test_new_exam_candidate_handoff_uses_persistent_student_and_course_then_resumes_exact_student(): void
    {
        $root = dirname(__DIR__, 2);
        $examWorkspace = file_get_contents($root.'/resources/js/modules/InternalExams/InternalExamWorkspace.vue');
        $studentWorkspace = file_get_contents($root.'/resources/js/modules/StudentsCourses/StudentCourseWorkspace.vue');

        $this->assertIsString($examWorkspace);
        $this->assertIsString($studentWorkspace);

        $this->assertStringContainsString('window.location.href = \'/kursanci?exam_handoff=1\'', $examWorkspace);
        $this->assertStringContainsString('const studentId = params.get(\'resume_student_id\')', $examWorkspace);
        $this->assertStringContainsString('student_id: studentId', $examWorkspace);
        $this->assertStringContainsString('row.assignment_eligible_now', $examWorkspace);

        $this->assertStringContainsString('pageQuery.get(\'exam_handoff\') === \'1\'', $studentWorkspace);
        $this->assertStringContainsString('studentForm.value.add_course = examHandoff', $studentWorkspace);
        $this->assertStringContainsString('body.initial_course = coursePayload(courseForm.value, true)', $studentWorkspace);
        $this->assertStringContainsString(
            'window.location.href = \'/egzamin-wewnetrzny/panel?resume_student_id=\' + encodeURIComponent(result.data.id)',
            $studentWorkspace,
        );

        $this->assertStringNotContainsString('return_to=', $studentWorkspace);
    }
}
