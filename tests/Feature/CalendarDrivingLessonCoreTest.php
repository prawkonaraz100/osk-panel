<?php

namespace Tests\Feature;

use App\Modules\CalendarTraining\CalendarDrivingLessonService;
use App\Modules\CalendarTraining\TrainingSessionService;
use App\Modules\ResourcesCore\LocationService;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class CalendarDrivingLessonCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_calendar_create_and_list_project_one_practical_training_session_without_calendar_event_copy(): void
    {
        $fixture = $this->fixture();
        $response = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/calendar/driving-lessons', [
                'course_enrollment_id' => $fixture['course']['id'],
                'name' => 'Jazda miejska',
                'starts_at' => '2026-09-11T10:00:00+02:00',
                'ends_at' => '2026-09-11T11:00:00+02:00',
                'instructor_id' => $fixture['instructor']['id'],
                'custom_meeting_place' => 'Plac przy WORD',
            ]);

        $response->assertCreated()
            ->assertHeader('ETag', '"v1"')
            ->assertJsonPath('event_type', 'driving_lesson')
            ->assertJsonPath('source_kind', 'training_session')
            ->assertJsonPath('student_id', $fixture['student']['id'])
            ->assertJsonPath('name', 'Jazda miejska')
            ->assertJsonPath('custom_meeting_place', 'Plac przy WORD');

        $sessionId = (string) $response->json('source_id');
        $this->assertSame(0, DB::table('calendar_events')->count());
        $this->assertSame(1, DB::table('training_sessions')->where('id', $sessionId)->where('session_type', 'practical')->count());
        $this->assertSame(1, DB::table('training_session_calendar_details')->where('training_session_id', $sessionId)->count());

        $calendar = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->getJson('/api/v1/calendar/events?event_type[]=driving_lesson');
        $calendar->assertOk()->assertJsonCount(1);
        $this->assertSame($sessionId, $calendar->json('0.source_id'));

        $manualEndpoint = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->getJson("/api/v1/calendar/events/{$sessionId}");
        $manualEndpoint->assertNotFound();

        $sourceEndpoint = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->getJson("/api/v1/calendar/driving-lessons/{$sessionId}");
        $sourceEndpoint->assertOk()->assertJsonPath('source_id', $sessionId);
    }

    public function test_student_only_create_requires_exactly_one_active_course(): void
    {
        $fixture = $this->fixture();
        app(CourseEnrollmentService::class)->create(
            $fixture['actor']['session_id'],
            $fixture['student']['id'],
            $this->coursePayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );

        $response = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/calendar/driving-lessons', [
                'student_id' => $fixture['student']['id'],
                'starts_at' => '2026-09-12T10:00:00+02:00',
                'ends_at' => '2026-09-12T11:00:00+02:00',
                'instructor_id' => $fixture['instructor']['id'],
            ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertSame(0, DB::table('training_sessions')->count());
    }

    public function test_calendar_manage_permission_alone_cannot_create_formal_training(): void
    {
        $fixture = $this->fixture();
        DB::table('membership_permission_scopes')
            ->where('membership_id', $fixture['actor']['membership_id'])
            ->where('permission_code', 'training_sessions.create')
            ->delete();
        DB::table('membership_permissions')
            ->where('membership_id', $fixture['actor']['membership_id'])
            ->where('permission_code', 'training_sessions.create')
            ->delete();

        $response = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/calendar/driving-lessons', [
                'course_enrollment_id' => $fixture['course']['id'],
                'starts_at' => '2026-09-12T10:00:00+02:00',
                'ends_at' => '2026-09-12T11:00:00+02:00',
                'instructor_id' => $fixture['instructor']['id'],
            ]);

        $response->assertForbidden();
        $this->assertSame(0, DB::table('training_sessions')->count());
        $this->assertSame(0, DB::table('calendar_events')->count());
    }

    public function test_calendar_metadata_patch_uses_training_session_version_and_preserves_meeting_place_xor(): void
    {
        $fixture = $this->fixture();
        $projection = app(CalendarDrivingLessonService::class)->create(
            $fixture['actor']['session_id'],
            [
                'course_enrollment_id' => $fixture['course']['id'],
                'name' => 'Pierwsza nazwa',
                'starts_at' => '2026-09-13T10:00:00+02:00',
                'ends_at' => '2026-09-13T11:00:00+02:00',
                'instructor_id' => $fixture['instructor']['id'],
                'custom_meeting_place' => 'Brama WORD',
            ],
            (string) Str::uuid7(),
        );
        $sessionId = (string) $projection['source_id'];

        $patched = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('If-Match', '"v1"')
            ->patchJson("/api/v1/training-sessions/{$sessionId}", [
                'display_name' => 'Druga nazwa',
            ]);
        $patched->assertOk()
            ->assertHeader('ETag', '"v2"')
            ->assertJsonPath('display_name', 'Druga nazwa')
            ->assertJsonPath('custom_meeting_place', 'Brama WORD');
        $this->assertSame(1, DB::table('training_session_calendar_details')->where('training_session_id', $sessionId)->count());

        $location = app(LocationService::class)->create(
            $fixture['actor']['session_id'],
            [
                'type_code' => 'branch',
                'name' => 'Filia',
                'street_and_number' => 'Testowa 1',
                'postal_code' => '00-001',
                'city_reference' => 'Warszawa',
            ],
            (string) Str::uuid7(),
        );

        $invalid = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('If-Match', '"v2"')
            ->patchJson("/api/v1/training-sessions/{$sessionId}", [
                'location_id' => $location['id'],
            ]);
        $invalid->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $valid = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('If-Match', '"v2"')
            ->patchJson("/api/v1/training-sessions/{$sessionId}", [
                'location_id' => $location['id'],
                'custom_meeting_place' => null,
            ]);
        $valid->assertOk()
            ->assertHeader('ETag', '"v3"')
            ->assertJsonPath('custom_meeting_place', null)
            ->assertJsonPath('location_id', $location['id']);
    }

    public function test_calendar_view_can_read_formal_projection_without_training_view_permission_and_theory_is_not_projected(): void
    {
        $fixture = $this->fixture();
        $practical = app(CalendarDrivingLessonService::class)->create(
            $fixture['actor']['session_id'],
            [
                'course_enrollment_id' => $fixture['course']['id'],
                'starts_at' => '2026-09-14T10:00:00+02:00',
                'ends_at' => '2026-09-14T11:00:00+02:00',
                'instructor_id' => $fixture['instructor']['id'],
            ],
            (string) Str::uuid7(),
        );
        app(TrainingSessionService::class)->create(
            $fixture['actor']['session_id'],
            $fixture['course']['id'],
            [
                'session_type' => 'theory',
                'starts_at' => '2026-09-14T12:00:00+02:00',
                'ends_at' => '2026-09-14T13:00:00+02:00',
                'instructor_id' => $fixture['instructor']['id'],
                'vehicle_id' => null,
                'location_id' => null,
            ],
            (string) Str::uuid7(),
        );

        $membershipId = FoundationSchema::member($fixture['actor']['organization_id']);
        FoundationSchema::grant($membershipId, 'calendar.view', ['organization']);
        $userId = (string) DB::table('organization_memberships')->where('id', $membershipId)->value('user_id');
        $readSessionId = (string) Str::uuid7();
        DB::table('auth_sessions')->insert([
            'id' => $readSessionId,
            'user_id' => $userId,
            'organization_membership_id' => $membershipId,
            'token_or_framework_session_hash' => hash('sha256', $readSessionId),
            'created_at' => now(),
        ]);

        $detail = $this->withSession(['auth_session_id' => $readSessionId])
            ->getJson('/api/v1/calendar/driving-lessons/'.(string) $practical['source_id']);
        $detail->assertOk()->assertJsonPath('event_type', 'driving_lesson');

        $list = $this->withSession(['auth_session_id' => $readSessionId])
            ->getJson('/api/v1/calendar/events?event_type[]=driving_lesson');
        $list->assertOk()->assertJsonCount(1);
        $this->assertSame($practical['source_id'], $list->json('0.source_id'));
    }

    /**
     * @return array{
     *   actor:array{organization_id:string,user_id:string,membership_id:string,session_id:string},
     *   instructor:array<string,mixed>,
     *   student:array<string,mixed>,
     *   course:array<string,mixed>
     * }
     */
    private function fixture(): array
    {
        $actor = FoundationSchema::actor();
        foreach ([
            'calendar.view', 'calendar.manage.organization',
            'students.view', 'students.create',
            'courses.view', 'courses.create',
            'training_sessions.view', 'training_sessions.create', 'training_sessions.edit', 'training_sessions.cancel',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        $instructor = app(StaffService::class)->create(
            $actor['session_id'],
            [
                'email' => 'driving.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
                'first_name' => 'Jan',
                'last_name' => 'Instruktor',
                'staff_type_codes' => ['Instructor'],
                'category_ids' => [],
                'location_ids' => [],
            ],
            (string) Str::uuid7(),
        );
        $student = app(StudentService::class)->create(
            $actor['session_id'],
            ['first_name' => 'Anna', 'last_name' => 'Nowak', 'pesel' => '02070803628', 'no_pesel' => false],
            (string) Str::uuid7(),
        );
        $course = app(CourseEnrollmentService::class)->create(
            $actor['session_id'],
            $student['id'],
            $this->coursePayload($instructor['id']),
            (string) Str::uuid7(),
        );

        return compact('actor', 'instructor', 'student', 'course');
    }

    /** @return array<string,mixed> */
    private function coursePayload(string $instructorId): array
    {
        return [
            'training_type' => 'basic',
            'driving_category_code' => 'B',
            'pkk_number' => 'PKK-'.str_replace('-', '', (string) Str::uuid7()),
            'started_at' => '2026-09-11T08:00:00+02:00',
            'declared_theory_minutes' => 0,
            'declared_practical_minutes' => 0,
            'recognized_external_theory_minutes' => 0,
            'recognized_external_practical_minutes' => 0,
            'lead_instructor_id' => $instructorId,
            'location_id' => null,
        ];
    }
}
