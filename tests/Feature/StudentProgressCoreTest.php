<?php

namespace Tests\Feature;

use App\Modules\LearningAccess\LearningAccountService;
use App\Modules\StudentsCourses\StudentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class StudentProgressCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        FoundationSchema::ensureStudentProgressMigrated();
        DB::table('student_learning_progress_projections')->delete();
        DB::table('learning_progress_source_bindings')->delete();
        FoundationSchema::reset();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_unbound_progress_is_truthful_and_never_fabricates_zero_metrics(): void
    {
        $fixture = $this->fixture('progress-unbound@example.test');

        $response = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->getJson('/api/v1/students/'.$fixture['student']['id'].'/progress?'.http_build_query([
                'learning_account_id' => $fixture['account']['id'],
                'category' => 'B',
            ]))
            ->assertOk()
            ->assertJsonPath('learning_account_id', $fixture['account']['id'])
            ->assertJsonPath('category_code', 'B')
            ->assertJsonPath('projection_state', 'unbound')
            ->assertJsonPath('freshness', 'unavailable')
            ->assertJsonPath('tests.state', 'unavailable')
            ->assertJsonPath('tests.passed_count', null)
            ->assertJsonPath('questions.available_count', null)
            ->assertJsonPath('handbook.state', 'unavailable')
            ->assertJsonPath('lectures.state', 'outside_core_v1');

        self::assertSame([], $response->json('topics'));
    }

    public function test_bound_projection_is_exact_account_category_scoped_and_stales_on_account_version_change(): void
    {
        $fixture = $this->fixture('progress-ready@example.test');
        $categoryId = (string) DB::table('driving_categories')->where('code', 'B')->value('id');
        $bindingId = (string) Str::uuid7();

        DB::table('learning_progress_source_bindings')->insert([
            'id' => $bindingId,
            'organization_id' => $fixture['actor']['organization_id'],
            'student_id' => $fixture['student']['id'],
            'student_learning_account_id' => $fixture['account']['id'],
            'source_system' => 'learning-core',
            'source_subject_ref' => 'subject-ready',
            'source_access_ref' => 'access-ready',
            'status' => 'active',
            'version' => 1,
            'bound_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('student_learning_progress_projections')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $fixture['actor']['organization_id'],
            'student_id' => $fixture['student']['id'],
            'student_learning_account_id' => $fixture['account']['id'],
            'learning_progress_source_binding_id' => $bindingId,
            'driving_category_id' => $categoryId,
            'learning_account_version' => $fixture['account']['version'],
            'source_snapshot_ref' => 'snapshot-ready',
            'source_observed_at' => now(),
            'projected_at' => now(),
            'projection_version' => 1,
            'tests_json' => json_encode([
                'state' => 'available',
                'passed_count' => 3,
                'failed_count' => 1,
                'conducted_count' => 4,
                'passed_percent' => 75.0,
                'failed_percent' => 25.0,
            ], JSON_THROW_ON_ERROR),
            'questions_json' => json_encode([
                'state' => 'available',
                'answered_count' => 40,
                'available_count' => 100,
                'total_attempts' => 50,
                'correct_attempts' => 42,
                'incorrect_attempts' => 8,
                'correct_percent' => 84.0,
                'incorrect_percent' => 16.0,
            ], JSON_THROW_ON_ERROR),
            'handbook_json' => json_encode([
                'state' => 'no_activity',
                'completed_units' => 0,
                'total_units' => 13,
                'progress_percent' => 0.0,
                'completed_control_questions' => 0,
                'available_control_questions' => 0,
                'control_questions_percent' => null,
            ], JSON_THROW_ON_ERROR),
            'lectures_json' => json_encode([
                'state' => 'outside_core_v1',
                'completed_units' => null,
                'total_units' => null,
                'progress_percent' => null,
                'completed_control_questions' => null,
                'available_control_questions' => null,
                'control_questions_percent' => null,
            ], JSON_THROW_ON_ERROR),
            'topics_json' => json_encode([[
                'topic_key' => 'warning_signs',
                'group' => 'basic',
                'label' => 'Warning signs',
                'label_language_code' => 'en',
                'answered_count' => 4,
                'available_count' => 12,
            ]], JSON_THROW_ON_ERROR),
            'snapshot_hash' => hash('sha256', 'snapshot-ready'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $path = '/api/v1/students/'.$fixture['student']['id'].'/progress?'.http_build_query([
            'learning_account_id' => $fixture['account']['id'],
            'category' => 'b',
        ]);

        $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->getJson($path)
            ->assertOk()
            ->assertJsonPath('projection_state', 'ready')
            ->assertJsonPath('freshness', 'fresh')
            ->assertJsonPath('tests.passed_count', 3)
            ->assertJsonPath('questions.correct_percent', 84)
            ->assertJsonPath('topics.0.topic_key', 'warning_signs');

        DB::table('student_learning_accounts')
            ->where('id', $fixture['account']['id'])
            ->update(['version' => (int) $fixture['account']['version'] + 1]);

        $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->getJson($path)
            ->assertOk()
            ->assertJsonPath('projection_state', 'stale')
            ->assertJsonPath('freshness', 'stale')
            ->assertJsonPath('tests.passed_count', 3);
    }

    public function test_wrong_account_category_and_foreign_tenant_contexts_fail_closed(): void
    {
        $first = $this->fixture('progress-first@example.test');
        $secondStudent = app(StudentService::class)->create(
            $first['actor']['session_id'],
            [
                'first_name' => 'Other',
                'last_name' => 'Student',
                'no_pesel' => true,
                'birth_date' => '1991-01-01',
            ],
            (string) Str::uuid7(),
        );
        $secondAccount = app(LearningAccountService::class)->create(
            $first['actor']['session_id'],
            (string) $secondStudent['id'],
            [
                'login_identifier' => 'progress-second@example.test',
                'language_code' => 'pl',
            ],
            (string) Str::uuid7(),
        );

        $this->withSession(['auth_session_id' => $first['actor']['session_id']])
            ->getJson('/api/v1/students/'.$first['student']['id'].'/progress?'.http_build_query([
                'learning_account_id' => $secondAccount['id'],
                'category' => 'B',
            ]))
            ->assertNotFound();

        $this->withSession(['auth_session_id' => $first['actor']['session_id']])
            ->getJson('/api/v1/students/'.$first['student']['id'].'/progress?'.http_build_query([
                'learning_account_id' => $first['account']['id'],
                'category' => 'ZZZ',
            ]))
            ->assertStatus(422);

        $foreign = $this->fixture('progress-foreign@example.test');
        $this->withSession(['auth_session_id' => $first['actor']['session_id']])
            ->getJson('/api/v1/students/'.$foreign['student']['id'].'/progress?'.http_build_query([
                'learning_account_id' => $foreign['account']['id'],
                'category' => 'B',
            ]))
            ->assertNotFound();
    }

    /**
     * @return array{
     *   actor:array{organization_id:string,user_id:string,membership_id:string,session_id:string},
     *   student:array<string,mixed>,
     *   account:array<string,mixed>
     * }
     */
    private function fixture(string $login): array
    {
        $actor = FoundationSchema::actor();
        foreach (['students.create', 'student_access.create', 'students.progress.view'] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        $student = app(StudentService::class)->create(
            $actor['session_id'],
            [
                'first_name' => 'Jan',
                'last_name' => 'Progress',
                'no_pesel' => true,
                'birth_date' => '1990-01-01',
            ],
            (string) Str::uuid7(),
        );
        $account = app(LearningAccountService::class)->create(
            $actor['session_id'],
            (string) $student['id'],
            [
                'login_identifier' => $login,
                'language_code' => 'pl',
            ],
            (string) Str::uuid7(),
        );

        return compact('actor', 'student', 'account');
    }
}
