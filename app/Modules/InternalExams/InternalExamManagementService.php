<?php

namespace App\Modules\InternalExams;

use App\Modules\StudentsCourses\StudentCourseScopeAuthorizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type StatisticsRow object{exam_count:mixed,passed_count:mixed,failed_count:mixed}
 * @phpstan-type ManagementRow object{student_id:mixed,course_enrollment_id:mixed,exam_part:mixed,assignment_eligible_now:mixed,first_name:mixed,last_name:mixed,full_name:mixed,email:mixed,login:mixed,course_category:mixed,status:mixed,latest_attempt_id:mixed,latest_attempt_sequence:mixed,latest_attempt_status:mixed,latest_exam_category:mixed,latest_exam_at:mixed,latest_exam_language:mixed,latest_attempt_started_at:mixed,latest_attempt_finished_at:mixed,exam_count:mixed,passed_count:mixed,failed_count:mixed,pass_rate:mixed}
 */
final class InternalExamManagementService
{
    private const STATUSES = ['not_assigned', 'not_conducted', 'failed', 'passed'];

    private const SORTS = [
        'identity_or_login',
        'student_full_name',
        'latest_exam_category',
        'latest_exam_status',
        'latest_exam_at',
        'latest_exam_language',
        'exam_count',
    ];

    public function __construct(private readonly StudentCourseScopeAuthorizer $scope) {}

    /**
     * @param  list<string>  $categories
     * @param  list<string>  $statuses
     * @return array{data:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function subjects(
        string $sessionId,
        int $page,
        int $perPage,
        ?string $search,
        array $categories,
        array $statuses,
        bool $hideFinished,
        string $sort,
        string $direction,
    ): array {
        $visibility = $this->scope->visibility($sessionId, 'exams.view');
        $organizationId = $visibility['membership']['organization_id'];

        if (in_array($sort, self::SORTS, true) === false) {
            $sort = 'student_full_name';
        }
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        if ($visibility['unrestricted'] === false && $visibility['student_ids'] === []) {
            return $this->empty($page, $perPage);
        }

        $filtered = DB::query()->fromSub($this->projection($organizationId), 'm');

        if ($visibility['unrestricted'] === false) {
            $filtered->whereIn('m.student_id', $visibility['student_ids']);
        }

        $search = $search === null ? null : mb_strtolower(trim($search));
        if ($search !== null && $search !== '') {
            $like = '%'.$search.'%';
            $filtered->where(function (Builder $query) use ($like): void {
                $query
                    ->whereRaw('LOWER(COALESCE(m.first_name, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(m.last_name, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(m.email, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(m.search_logins, \'\')) LIKE ?', [$like]);
            });
        }

        $categories = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => mb_strtoupper(trim($value)),
            $categories,
        ))));
        if ($categories !== []) {
            $filtered->whereIn('m.course_category', $categories);
        }

        $statuses = array_values(array_unique(array_filter(
            $statuses,
            static fn (string $value): bool => in_array($value, self::STATUSES, true),
        )));
        if ($statuses !== []) {
            $filtered->whereIn('m.status', $statuses);
        }

        if ($hideFinished) {
            $filtered->whereNotIn('m.status', ['passed', 'failed']);
        }

        $countQuery = clone $filtered;
        $total = (int) $countQuery->count();

        $statisticsQuery = clone $filtered;
        /** @var StatisticsRow|null $statistics */
        $statistics = $statisticsQuery
            ->selectRaw('COALESCE(SUM(m.exam_count), 0) AS exam_count')
            ->selectRaw('COALESCE(SUM(m.passed_count), 0) AS passed_count')
            ->selectRaw('COALESCE(SUM(m.failed_count), 0) AS failed_count')
            ->first();

        $passed = $statistics === null ? 0 : (int) $statistics->passed_count;
        $failed = $statistics === null ? 0 : (int) $statistics->failed_count;
        $validConducted = $passed + $failed;

        $this->applySort($filtered, $sort, $direction);

        $rows = $filtered
            ->select(['m.*'])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn (object $row): array => $this->present($row))
            ->values()
            ->all();

        return [
            'data' => array_values($rows),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'statistics' => [
                    'subject_count' => $total,
                    'exam_count' => $statistics === null ? 0 : (int) $statistics->exam_count,
                    'passed_count' => $passed,
                    'failed_count' => $failed,
                    'valid_conducted_count' => $validConducted,
                    'pass_rate' => $validConducted === 0 ? null : $passed / $validConducted,
                ],
            ],
        ];
    }

    private function projection(string $organizationId): Builder
    {
        $profileJoin = static function ($join): void {
            $join->on('p.organization_id', '=', 'c.organization_id')
                ->on('p.course_enrollment_id', '=', 'c.id')
                ->on('p.requirements_revision', '=', 'c.requirements_revision')
                ->whereNull('p.superseded_at');
        };

        $theory = DB::table('course_enrollments as c')
            ->join('training_requirement_profiles as p', $profileJoin)
            ->where('c.organization_id', $organizationId)
            ->where('p.internal_theory_exam_required', true)
            ->select([
                'c.organization_id',
                'c.id as course_enrollment_id',
                'c.student_id',
                DB::raw('\'theory\'::varchar AS exam_part'),
            ]);

        $practical = DB::table('course_enrollments as c')
            ->join('training_requirement_profiles as p', $profileJoin)
            ->where('c.organization_id', $organizationId)
            ->where('p.internal_practical_exam_required', true)
            ->select([
                'c.organization_id',
                'c.id as course_enrollment_id',
                'c.student_id',
                DB::raw('\'practical\'::varchar AS exam_part'),
            ]);

        $history = DB::table('internal_exam_attempts as a')
            ->where('a.organization_id', $organizationId)
            ->select([
                'a.organization_id',
                'a.course_enrollment_id',
                'a.student_id',
                'a.exam_part',
            ])
            ->distinct();

        $contexts = $theory->union($practical)->union($history);

        $attempts = DB::table('internal_exam_attempts as a')
            ->where('a.organization_id', $organizationId)
            ->groupBy('a.organization_id', 'a.course_enrollment_id', 'a.exam_part')
            ->select([
                'a.organization_id',
                'a.course_enrollment_id',
                'a.exam_part',
            ])
            ->selectRaw('MAX(a.course_attempt_sequence) AS latest_sequence')
            ->selectRaw('COUNT(*) AS exam_count')
            ->selectRaw('SUM(CASE WHEN a.status = \'passed\' THEN 1 ELSE 0 END) AS passed_count')
            ->selectRaw('SUM(CASE WHEN a.status = \'failed\' THEN 1 ELSE 0 END) AS failed_count');

        $logins = DB::table('student_learning_accounts as la')
            ->join('auth_login_identifiers as li', function ($join): void {
                $join->on('li.id', '=', 'la.auth_login_identifier_id')
                    ->on('li.user_id', '=', 'la.user_id');
            })
            ->where('la.organization_id', $organizationId)
            ->where('la.status', 'active')
            ->whereNull('li.revoked_at')
            ->groupBy('la.organization_id', 'la.student_id')
            ->select(['la.organization_id', 'la.student_id'])
            ->selectRaw('MIN(li.identifier_normalized) AS login')
            ->selectRaw('string_agg(DISTINCT li.identifier_normalized, \' \') AS search_logins');

        return DB::query()
            ->fromSub($contexts, 'ctx')
            ->join('course_enrollments as c', function ($join): void {
                $join->on('c.organization_id', '=', 'ctx.organization_id')
                    ->on('c.id', '=', 'ctx.course_enrollment_id')
                    ->on('c.student_id', '=', 'ctx.student_id');
            })
            ->join('students as s', function ($join): void {
                $join->on('s.organization_id', '=', 'ctx.organization_id')
                    ->on('s.id', '=', 'ctx.student_id');
            })
            ->join('driving_categories as dc', 'dc.id', '=', 'c.driving_category_id')
            ->leftJoin('training_requirement_profiles as p', function ($join): void {
                $join->on('p.organization_id', '=', 'c.organization_id')
                    ->on('p.course_enrollment_id', '=', 'c.id')
                    ->on('p.requirements_revision', '=', 'c.requirements_revision')
                    ->whereNull('p.superseded_at');
            })
            ->leftJoinSub($attempts, 'agg', function ($join): void {
                $join->on('agg.organization_id', '=', 'ctx.organization_id')
                    ->on('agg.course_enrollment_id', '=', 'ctx.course_enrollment_id')
                    ->on('agg.exam_part', '=', 'ctx.exam_part');
            })
            ->leftJoin('internal_exam_attempts as latest', function ($join): void {
                $join->on('latest.organization_id', '=', 'ctx.organization_id')
                    ->on('latest.course_enrollment_id', '=', 'ctx.course_enrollment_id')
                    ->on('latest.exam_part', '=', 'ctx.exam_part')
                    ->on('latest.course_attempt_sequence', '=', 'agg.latest_sequence');
            })
            ->leftJoin('driving_categories as latest_dc', 'latest_dc.id', '=', 'latest.driving_category_id')
            ->leftJoinSub($logins, 'login', function ($join): void {
                $join->on('login.organization_id', '=', 'ctx.organization_id')
                    ->on('login.student_id', '=', 'ctx.student_id');
            })
            ->where('ctx.organization_id', $organizationId)
            ->select([
                'ctx.student_id',
                'ctx.course_enrollment_id',
                'ctx.exam_part',
                's.first_name',
                's.last_name',
                's.contact_email_normalized as email',
                'login.login',
                'login.search_logins',
                'dc.code as course_category',
                'latest.id as latest_attempt_id',
                'agg.latest_sequence as latest_attempt_sequence',
                'latest.status as latest_attempt_status',
                'latest_dc.code as latest_exam_category',
                'latest.language_code as latest_exam_language',
                'latest.created_at as latest_exam_at',
                'latest.started_at as latest_attempt_started_at',
                'latest.finished_at as latest_attempt_finished_at',
            ])
            ->selectRaw('TRIM(CONCAT_WS(\' \', s.first_name, s.last_name)) AS full_name')
            ->selectRaw(
                'CASE
                    WHEN p.id IS NULL THEN FALSE
                    WHEN ctx.exam_part = \'theory\' THEN p.internal_theory_exam_required
                    ELSE p.internal_practical_exam_required
                END AS assignment_eligible_now',
            )
            ->selectRaw(
                'CASE
                    WHEN latest.id IS NULL THEN \'not_assigned\'
                    WHEN latest.status = \'passed\' THEN \'passed\'
                    WHEN latest.status = \'failed\' THEN \'failed\'
                    ELSE \'not_conducted\'
                END AS status',
            )
            ->selectRaw('COALESCE(agg.exam_count, 0) AS exam_count')
            ->selectRaw('COALESCE(agg.passed_count, 0) AS passed_count')
            ->selectRaw('COALESCE(agg.failed_count, 0) AS failed_count')
            ->selectRaw(
                'CASE
                    WHEN COALESCE(agg.passed_count, 0) + COALESCE(agg.failed_count, 0) = 0 THEN NULL
                    ELSE COALESCE(agg.passed_count, 0)::numeric
                        / (COALESCE(agg.passed_count, 0) + COALESCE(agg.failed_count, 0))
                END AS pass_rate',
            );
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $descending = $direction === 'desc';

        if ($sort === 'identity_or_login') {
            $query->orderByRaw(
                $descending
                    ? "LOWER(COALESCE(m.email, m.login, '')) DESC"
                    : "LOWER(COALESCE(m.email, m.login, '')) ASC",
            );
        } elseif ($sort === 'student_full_name') {
            $query->orderByRaw($descending ? 'LOWER(m.last_name) DESC' : 'LOWER(m.last_name) ASC')
                ->orderByRaw($descending ? 'LOWER(m.first_name) DESC' : 'LOWER(m.first_name) ASC');
        } elseif ($sort === 'latest_exam_status') {
            $query->orderByRaw(
                $descending
                    ? "CASE m.status
                        WHEN 'not_assigned' THEN 1
                        WHEN 'not_conducted' THEN 2
                        WHEN 'failed' THEN 3
                        WHEN 'passed' THEN 4
                        ELSE 5
                    END DESC"
                    : "CASE m.status
                        WHEN 'not_assigned' THEN 1
                        WHEN 'not_conducted' THEN 2
                        WHEN 'failed' THEN 3
                        WHEN 'passed' THEN 4
                        ELSE 5
                    END ASC",
            );
        } elseif ($sort === 'exam_count') {
            $query->orderBy('m.exam_count', $descending ? 'desc' : 'asc');
        } elseif ($sort === 'latest_exam_category') {
            $query->orderByRaw($descending ? 'm.latest_exam_category DESC NULLS LAST' : 'm.latest_exam_category ASC NULLS LAST');
        } elseif ($sort === 'latest_exam_language') {
            $query->orderByRaw($descending ? 'm.latest_exam_language DESC NULLS LAST' : 'm.latest_exam_language ASC NULLS LAST');
        } else {
            $query->orderByRaw($descending ? 'm.latest_exam_at DESC NULLS LAST' : 'm.latest_exam_at ASC NULLS LAST');
        }

        $query->orderBy('m.course_enrollment_id')
            ->orderBy('m.exam_part');
    }

    /** @return array<string,mixed> */
    private function present(object $row): array
    {
        /** @var ManagementRow $projection */
        $projection = $row;

        return [
            'student_id' => (string) $projection->student_id,
            'course_enrollment_id' => (string) $projection->course_enrollment_id,
            'exam_part' => (string) $projection->exam_part,
            'assignment_eligible_now' => (bool) $projection->assignment_eligible_now,
            'first_name' => (string) $projection->first_name,
            'last_name' => (string) $projection->last_name,
            'full_name' => (string) $projection->full_name,
            'email' => $projection->email === null ? null : (string) $projection->email,
            'login' => $projection->login === null ? null : (string) $projection->login,
            'course_category' => (string) $projection->course_category,
            'status' => (string) $projection->status,
            'latest_attempt_id' => $projection->latest_attempt_id === null ? null : (string) $projection->latest_attempt_id,
            'latest_attempt_sequence' => $projection->latest_attempt_sequence === null ? null : (int) $projection->latest_attempt_sequence,
            'latest_attempt_status' => $projection->latest_attempt_status === null ? null : (string) $projection->latest_attempt_status,
            'latest_exam_category' => $projection->latest_exam_category === null ? null : (string) $projection->latest_exam_category,
            'latest_exam_at' => $projection->latest_exam_at === null ? null : (string) $projection->latest_exam_at,
            'latest_exam_language' => $projection->latest_exam_language === null ? null : (string) $projection->latest_exam_language,
            'latest_attempt_started_at' => $projection->latest_attempt_started_at === null ? null : (string) $projection->latest_attempt_started_at,
            'latest_attempt_finished_at' => $projection->latest_attempt_finished_at === null ? null : (string) $projection->latest_attempt_finished_at,
            'exam_count' => (int) $projection->exam_count,
            'passed_count' => (int) $projection->passed_count,
            'failed_count' => (int) $projection->failed_count,
            'pass_rate' => $projection->pass_rate === null ? null : (float) $projection->pass_rate,
        ];
    }

    /** @return array{data:list<array<string,mixed>>,meta:array<string,mixed>} */
    private function empty(int $page, int $perPage): array
    {
        return [
            'data' => [],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => 0,
                'last_page' => 1,
                'statistics' => [
                    'subject_count' => 0,
                    'exam_count' => 0,
                    'passed_count' => 0,
                    'failed_count' => 0,
                    'valid_conducted_count' => 0,
                    'pass_rate' => null,
                ],
            ],
        ];
    }
}
