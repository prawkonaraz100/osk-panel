<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class StudentProgressAuthorityContractTest extends TestCase
{
    public function test_student_progress_authority_is_contextual_truthful_and_projection_safe(): void
    {
        $root = dirname(__DIR__, 2);
        $design = (string) file_get_contents($root.'/specs/design/student-progress.yml');
        $migration = (string) file_get_contents($root.'/specs/database/student-progress-projection-migration-extension.yml');
        $components = (string) file_get_contents($root.'/specs/api/openapi-components-v1.yaml');
        $baseline = (string) file_get_contents($root.'/specs/implementation-baseline-v1.yml');

        self::assertMatchesRegularExpression('/status: (?:CANDIDATE|PASS)/', $design);
        self::assertStringContainsString('operation: students.progress', $design);
        self::assertStringContainsString('permission: students.progress.view', $design);
        self::assertStringContainsString('infer_binding_from_email: forbidden', $design);
        self::assertStringContainsString('infer_binding_from_login_identifier: forbidden', $design);
        self::assertStringContainsString('source_access_ref_global_active_uniqueness: required', $design);
        self::assertStringContainsString('missing_binding_numeric_zeroes: forbidden', $design);
        self::assertStringContainsString('source_call_inside_open_PostgreSQL_transaction: forbidden', $design);
        self::assertStringContainsString('return_stale_last_good', $design);
        self::assertStringContainsString('repository_default: 300', $design);
        self::assertStringContainsString('no_activity_percentages_with_zero_denominator: null', $design);
        self::assertStringContainsString('infer_from_correct_answer_percentage: forbidden', $design);
        self::assertStringContainsString('total_attempts_equals_correct_attempts_plus_incorrect_attempts', $design);
        self::assertStringContainsString('observed_competitor_totals_as_constants: forbidden', $design);
        self::assertStringContainsString('STEP_VIEWED_counts_as_completion: false', $design);
        self::assertStringContainsString('state: outside_core_v1', $design);
        self::assertStringContainsString('hardcoded_2185: forbidden', $design);
        self::assertStringContainsString('hardcoded_774: forbidden', $design);

        self::assertMatchesRegularExpression('/status: (?:CANDIDATE|PASS)/', $migration);
        self::assertStringContainsString('root: database/migrations/stage5/student-progress', $migration);
        self::assertStringContainsString('S5PROG-TBL-LEARNING-PROGRESS-SOURCE-BINDINGS', $migration);
        self::assertStringContainsString('S5PROG-TBL-STUDENT-LEARNING-PROGRESS-PROJECTIONS', $migration);
        self::assertStringContainsString('source_access_context_global_uniqueness', $migration);
        self::assertStringContainsString('infer_existing_bindings_from_login_or_email: forbidden', $migration);
        self::assertStringContainsString('backfill_fake_zero_progress: forbidden', $migration);
        self::assertStringContainsString('automatic_legacy_binding_count: zero', $migration);

        self::assertStringContainsString('StudentProgressMetricState:', $components);
        self::assertStringContainsString('enum: [available, no_activity, unavailable, outside_core_v1]', $components);
        self::assertStringContainsString('StudentProgressTests:', $components);
        self::assertStringContainsString('StudentProgressQuestions:', $components);
        self::assertStringContainsString('StudentProgressContent:', $components);
        self::assertStringContainsString('StudentProgressTopic:', $components);
        self::assertStringContainsString('projection_state:', $components);

        self::assertStringContainsString('- student_progress_projection_and_UI', $baseline);
        self::assertStringContainsString('- lectures', $baseline);
    }
}
