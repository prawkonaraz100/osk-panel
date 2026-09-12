<?php

namespace Tests\Feature;

use App\Modules\InternalExams\ExamStationCredentialService;
use App\Modules\InternalExams\InternalExamService;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class InternalExamCandidateSnapshotPatchTest extends TestCase
{
    private string $stationCredential = '';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('internal_exams.station_heartbeat_fresh_seconds', 120);
        config()->set('internal_exams.token_verifier_key_v1', str_repeat('t', 32));
        config()->set('internal_exams.station_verifier_key_v1', str_repeat('s', 32));
        FoundationSchema::reset();
    }

    public function test_candidate_snapshot_patch_is_prestart_versioned_and_optimistically_concurrent(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $this->examFixtures($actor, $course);
        $service = app(InternalExamService::class);

        $attempt = $service->createAttempt(
            $actor['session_id'],
            $course['id'],
            'theory',
            'pl',
            (string) Str::uuid7(),
        );
        $attemptId = (string) $attempt['id'];

        $get = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson("/api/v1/internal-exam-attempts/{$attemptId}");
        $get->assertOk()->assertHeader('ETag', '"v1"');
        $get->assertJsonPath('version', 1)
            ->assertJsonPath('candidate_snapshot.first_name', 'Anna')
            ->assertJsonPath('candidate_snapshot.last_name', 'Egzamin')
            ->assertJsonPath('candidate_snapshot.student_id', $course['student_id']);

        $immutable = [
            'student_id' => $get->json('student_id'),
            'course_enrollment_id' => $get->json('course_enrollment_id'),
            'exam_part' => $get->json('exam_part'),
            'driving_category_code' => $get->json('driving_category_code'),
            'language_code' => $get->json('language_code'),
        ];

        $missingPrecondition = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->patchJson("/api/v1/internal-exam-attempts/{$attemptId}", [
                'candidate_snapshot' => ['first_name' => 'Anna Maria'],
            ]);
        $missingPrecondition->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        $patched = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('If-Match', '"v1"')
            ->patchJson("/api/v1/internal-exam-attempts/{$attemptId}", [
                'candidate_snapshot' => [
                    'first_name' => ' Anna Maria ',
                    'contact_email' => ' ANNA.EXAM@EXAMPLE.TEST ',
                ],
            ]);
        $patched->assertOk()->assertHeader('ETag', '"v2"');
        $patched->assertJsonPath('version', 2)
            ->assertJsonPath('candidate_snapshot.first_name', 'Anna Maria')
            ->assertJsonPath('candidate_snapshot.contact_email', 'anna.exam@example.test');
        foreach ($immutable as $field => $value) {
            $this->assertSame($value, $patched->json($field));
        }

        $event = DB::table('internal_exam_attempt_lifecycle_events')
            ->where('organization_id', $actor['organization_id'])
            ->where('internal_exam_attempt_id', $attemptId)
            ->where('event_type', 'candidate_snapshot_updated')
            ->firstOrFail();
        $this->assertSame('created', (string) $event->from_status);
        $this->assertSame('created', (string) $event->to_status);
        $this->assertSame(1, (int) $event->version_before);
        $this->assertSame(2, (int) $event->version_after);
        $this->assertSame($actor['user_id'], (string) $event->actor_user_id);
        $this->assertNull($event->reason);
        $eventColumns = array_keys((array) $event);
        $this->assertNotContains('candidate_snapshot', $eventColumns);
        $this->assertNotContains('pesel', $eventColumns);
        $this->assertNotContains('pkk_number', $eventColumns);

        $stale = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('If-Match', '"v1"')
            ->patchJson("/api/v1/internal-exam-attempts/{$attemptId}", [
                'candidate_snapshot' => ['last_name' => 'Nowak'],
            ]);
        $stale->assertStatus(409)
            ->assertJsonPath('error.code', 'RESOURCE_VERSION_CONFLICT');
        $this->assertSame(2, (int) DB::table('internal_exam_attempts')->where('id', $attemptId)->value('version'));

        $immutableField = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('If-Match', '"v2"')
            ->patchJson("/api/v1/internal-exam-attempts/{$attemptId}", [
                'candidate_snapshot' => ['language_code' => 'en'],
            ]);
        $immutableField->assertStatus(422);
        $this->assertSame(2, (int) DB::table('internal_exam_attempts')->where('id', $attemptId)->value('version'));

        $noOp = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('If-Match', '"v2"')
            ->patchJson("/api/v1/internal-exam-attempts/{$attemptId}", [
                'candidate_snapshot' => ['first_name' => 'Anna Maria'],
            ]);
        $noOp->assertOk()->assertHeader('ETag', '"v2"')
            ->assertJsonPath('version', 2);
        $this->assertSame(
            1,
            DB::table('internal_exam_attempt_lifecycle_events')
                ->where('internal_exam_attempt_id', $attemptId)
                ->where('event_type', 'candidate_snapshot_updated')
                ->count(),
        );

        $stationId = $this->station($actor);
        $access = $service->createAccess(
            $actor['session_id'],
            $attemptId,
            'assigned_exam_station',
            $stationId,
            null,
            null,
            (string) Str::uuid7(),
        );
        $started = $service->startLocal(
            $actor['session_id'],
            (string) $access['id'],
            $this->stationCredential,
            (string) Str::uuid7(),
        );
        $this->assertSame('in_progress', $started['status']);

        $afterStart = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->getJson("/api/v1/internal-exam-attempts/{$attemptId}");
        $afterStart->assertOk();
        $currentEtag = (string) $afterStart->headers->get('ETag');
        $this->assertNotSame('"v2"', $currentEtag);

        $postStartPatch = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('If-Match', $currentEtag)
            ->patchJson("/api/v1/internal-exam-attempts/{$attemptId}", [
                'candidate_snapshot' => ['last_name' => 'Po Starcie'],
            ]);
        $postStartPatch->assertStatus(409)
            ->assertJsonPath('error.code', 'RESOURCE_VERSION_CONFLICT');
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function examActor(): array
    {
        $actor = FoundationSchema::actor();
        foreach ([
            'students.view', 'students.create', 'students.edit',
            'courses.view', 'courses.create', 'courses.edit', 'course_requirements.correct',
            'exams.view', 'exams.generate', 'exams.start.local',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        return $actor;
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @return array<string,mixed>
     */
    private function course(array $actor): array
    {
        $student = app(StudentService::class)->create($actor['session_id'], [
            'first_name' => 'Anna',
            'last_name' => 'Egzamin',
            'pesel' => '02070803628',
            'no_pesel' => false,
        ], (string) Str::uuid7());

        $instructor = app(StaffService::class)->create($actor['session_id'], [
            'email' => 'candidate-patch.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
            'first_name' => 'Jan',
            'last_name' => 'Instruktor',
            'staff_type_codes' => ['Instructor'],
            'category_ids' => [],
            'location_ids' => [],
        ], (string) Str::uuid7());

        return app(CourseEnrollmentService::class)->create($actor['session_id'], $student['id'], [
            'training_type' => 'basic',
            'driving_category_code' => 'B',
            'pkk_number' => 'PKK-'.str_replace('-', '', (string) Str::uuid7()),
            'started_at' => '2026-09-11T08:00:00+02:00',
            'declared_theory_minutes' => 0,
            'declared_practical_minutes' => 0,
            'recognized_external_theory_minutes' => 0,
            'recognized_external_practical_minutes' => 0,
            'lead_instructor_id' => $instructor['id'],
            'location_id' => null,
        ], (string) Str::uuid7());
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @param  array<string,mixed>  $course
     */
    private function examFixtures(array $actor, array $course): void
    {
        $categoryId = (string) DB::table('driving_categories')
            ->where('code', $course['driving_category_code'])
            ->value('id');
        $capabilityId = (string) Str::uuid7();
        $definitionId = (string) Str::uuid7();
        $inventoryId = (string) Str::uuid7();
        $now = now()->subMinute();

        DB::table('internal_exam_capabilities')->insert([
            'id' => $capabilityId,
            'driving_category_id' => $categoryId,
            'exam_part' => 'theory',
            'language_code' => 'pl',
            'enabled_at' => $now,
            'disabled_at' => null,
            'source_reference' => 'candidate-patch-test',
            'created_at' => $now,
        ]);

        $composition = [
            'questions' => [[
                'group' => 'basic',
                'source_question_identifier' => 'candidate-patch-q1',
                'source_question_revision_identifier' => 'v1',
                'question_snapshot_schema_version' => 1,
                'question_snapshot' => [
                    'text' => 'Candidate patch fixture question',
                    'answer_type' => 'boolean',
                    'ordered_answer_options' => [true, false],
                    'correct_answer' => true,
                    'category_context' => 'B',
                ],
                'max_points' => 1,
            ]],
        ];
        $scoring = [
            'question_scoring' => 'all_or_nothing',
            'pass_rule' => 'minimum_score',
            'pass_threshold' => 1,
        ];
        DB::table('internal_exam_definitions')->insert([
            'id' => $definitionId,
            'driving_category_id' => $categoryId,
            'exam_part' => 'theory',
            'language_code' => 'pl',
            'engine_kind' => 'question_test',
            'definition_version' => 'candidate-patch-v1',
            'definition_schema_version' => 1,
            'composition_snapshot' => json_encode($composition, JSON_THROW_ON_ERROR),
            'scoring_policy_snapshot' => json_encode($scoring, JSON_THROW_ON_ERROR),
            'definition_content_hash' => hash('sha256', json_encode([$composition, $scoring], JSON_THROW_ON_ERROR)),
            'published_at' => $now,
            'retired_at' => null,
            'created_at' => $now,
        ]);

        DB::table('internal_exam_inventory_entries')->insert([
            'id' => $inventoryId,
            'organization_id' => $actor['organization_id'],
            'source_type' => 'free',
            'source_order_item_id' => null,
            'source_adjustment_id' => null,
            'current_state' => 'available',
            'created_at' => $now,
        ]);
        DB::table('internal_exam_inventory_ledger_entries')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $actor['organization_id'],
            'internal_exam_inventory_entry_id' => $inventoryId,
            'internal_exam_reservation_id' => null,
            'internal_exam_attempt_id' => null,
            'internal_exam_inventory_adjustment_id' => null,
            'event_sequence' => 1,
            'event_type' => 'unit_granted',
            'available_delta' => 1,
            'actor_user_id' => null,
            'reason' => null,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    /** @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor */
    private function station(array $actor): string
    {
        $stationId = (string) Str::uuid7();
        $now = now();
        DB::table('exam_stations')->insert([
            'id' => $stationId,
            'organization_id' => $actor['organization_id'],
            'administrative_status' => 'enabled',
            'last_authenticated_heartbeat_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $issued = app(ExamStationCredentialService::class)->issue(
            $actor['organization_id'],
            $stationId,
            $actor['user_id'],
            CarbonImmutable::instance($now),
        );
        $this->stationCredential = (string) $issued['raw_credential'];

        return $stationId;
    }
}
