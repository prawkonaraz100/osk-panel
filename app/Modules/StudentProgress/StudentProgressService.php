<?php

namespace App\Modules\StudentProgress;

use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\StudentsCourses\StudentCourseScopeAuthorizer;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type AccountRow object{id:mixed,version:mixed}
 * @phpstan-type CategoryRow object{id:mixed,code:mixed}
 * @phpstan-type BindingRow object{id:mixed}
 * @phpstan-type ProjectionRow object{
 *   learning_account_version:mixed,
 *   source_observed_at:mixed,
 *   projected_at:mixed,
 *   tests_json:mixed,
 *   questions_json:mixed,
 *   handbook_json:mixed,
 *   lectures_json:mixed,
 *   topics_json:mixed
 * }
 */
final class StudentProgressService
{
    private const METRIC_STATES = ['available', 'no_activity', 'unavailable', 'outside_core_v1'];

    public function __construct(
        private readonly StudentCourseScopeAuthorizer $scopeAuthorizer,
    ) {}

    /** @return array<string,mixed> */
    public function get(
        string $sessionId,
        string $studentId,
        string $learningAccountId,
        string $categoryCode,
    ): array {
        $actor = $this->scopeAuthorizer->requireStudentTarget(
            $sessionId,
            'students.progress.view',
            $studentId,
        );

        /** @var AccountRow|null $account */
        $account = DB::table('student_learning_accounts')
            ->where('organization_id', $actor['organization_id'])
            ->where('student_id', $studentId)
            ->where('id', $learningAccountId)
            ->first(['id', 'version']);
        if ($account === null) {
            throw ResourceDomainException::notFound();
        }

        /** @var CategoryRow|null $category */
        $category = DB::table('driving_categories')
            ->where('code', strtoupper(trim($categoryCode)))
            ->where('active', true)
            ->first(['id', 'code']);
        if ($category === null) {
            throw ResourceDomainException::rule('Unknown or inactive driving category.');
        }

        /** @var BindingRow|null $binding */
        $binding = DB::table('learning_progress_source_bindings')
            ->where('organization_id', $actor['organization_id'])
            ->where('student_id', $studentId)
            ->where('student_learning_account_id', $learningAccountId)
            ->where('status', 'active')
            ->first(['id']);

        if ($binding === null) {
            return $this->unavailable($learningAccountId, (string) $category->code, 'unbound');
        }

        /** @var ProjectionRow|null $projection */
        $projection = DB::table('student_learning_progress_projections')
            ->where('organization_id', $actor['organization_id'])
            ->where('student_id', $studentId)
            ->where('student_learning_account_id', $learningAccountId)
            ->where('learning_progress_source_binding_id', (string) $binding->id)
            ->where('driving_category_id', (string) $category->id)
            ->first([
                'learning_account_version',
                'source_observed_at',
                'projected_at',
                'tests_json',
                'questions_json',
                'handbook_json',
                'lectures_json',
                'topics_json',
            ]);

        if ($projection === null) {
            return $this->unavailable(
                $learningAccountId,
                (string) $category->code,
                'source_unavailable',
            );
        }

        $tests = $this->metricObject($projection->tests_json, 'tests');
        $questions = $this->metricObject($projection->questions_json, 'questions');
        $handbook = $this->metricObject($projection->handbook_json, 'handbook');
        $lectures = $this->metricObject($projection->lectures_json, 'lectures');
        $topics = $this->topics($projection->topics_json);

        foreach ([$tests, $questions, $handbook, $lectures] as $metric) {
            $state = $metric['state'] ?? null;
            if (! is_string($state) || ! in_array($state, self::METRIC_STATES, true)) {
                throw ResourceDomainException::conflict('Stored progress projection has an invalid metric state.');
            }
        }

        $maxAgeSeconds = max(1, (int) config('learning_progress.max_age_seconds', 300));
        $projectedTimestamp = strtotime((string) $projection->projected_at);
        $stale = (int) $projection->learning_account_version !== (int) $account->version
            || $projectedTimestamp === false
            || $projectedTimestamp < time() - $maxAgeSeconds;

        return [
            'learning_account_id' => $learningAccountId,
            'category_code' => (string) $category->code,
            'projection_state' => $stale ? 'stale' : 'ready',
            'freshness' => $stale ? 'stale' : 'fresh',
            'source_observed_at' => (string) $projection->source_observed_at,
            'projected_at' => (string) $projection->projected_at,
            'tests' => $tests,
            'questions' => $questions,
            'handbook' => $handbook,
            'lectures' => $lectures,
            'topics' => $topics,
        ];
    }

    /** @return array<string,mixed> */
    private function unavailable(
        string $learningAccountId,
        string $categoryCode,
        string $projectionState,
    ): array {
        return [
            'learning_account_id' => $learningAccountId,
            'category_code' => $categoryCode,
            'projection_state' => $projectionState,
            'freshness' => 'unavailable',
            'source_observed_at' => null,
            'projected_at' => null,
            'tests' => [
                'state' => 'unavailable',
                'passed_count' => null,
                'failed_count' => null,
                'conducted_count' => null,
                'passed_percent' => null,
                'failed_percent' => null,
            ],
            'questions' => [
                'state' => 'unavailable',
                'answered_count' => null,
                'available_count' => null,
                'total_attempts' => null,
                'correct_attempts' => null,
                'incorrect_attempts' => null,
                'correct_percent' => null,
                'incorrect_percent' => null,
            ],
            'handbook' => $this->unavailableContent('unavailable'),
            'lectures' => $this->unavailableContent('outside_core_v1'),
            'topics' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function unavailableContent(string $state): array
    {
        return [
            'state' => $state,
            'completed_units' => null,
            'total_units' => null,
            'progress_percent' => null,
            'completed_control_questions' => null,
            'available_control_questions' => null,
            'control_questions_percent' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function metricObject(mixed $value, string $label): array
    {
        $decoded = $this->decodeJson($value, $label);
        if (array_is_list($decoded)) {
            throw ResourceDomainException::conflict("Stored {$label} progress projection must be an object.");
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /** @return list<array<string,mixed>> */
    private function topics(mixed $value): array
    {
        $decoded = $this->decodeJson($value, 'topics');
        if (! array_is_list($decoded)) {
            throw ResourceDomainException::conflict('Stored topics progress projection must be a list.');
        }

        $topics = [];
        foreach ($decoded as $topic) {
            if (! is_array($topic) || array_is_list($topic)) {
                throw ResourceDomainException::conflict('Stored progress topic projection is invalid.');
            }

            /** @var array<string,mixed> $topic */
            $group = $topic['group'] ?? null;
            if (! in_array($group, ['basic', 'specialized'], true)) {
                throw ResourceDomainException::conflict('Stored progress topic group is invalid.');
            }

            $topics[] = $topic;
        }

        return $topics;
    }

    /** @return array<mixed> */
    private function decodeJson(mixed $value, string $label): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            throw ResourceDomainException::conflict("Stored {$label} progress projection is invalid.");
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            throw ResourceDomainException::conflict("Stored {$label} progress projection is invalid.");
        }

        return $decoded;
    }
}
