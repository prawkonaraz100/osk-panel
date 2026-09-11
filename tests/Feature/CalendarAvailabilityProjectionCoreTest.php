<?php

namespace Tests\Feature;

use App\Modules\CalendarTraining\AvailabilitySlotService;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class CalendarAvailabilityProjectionCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_only_booked_unformalized_slot_projects_as_driving_lesson_and_filters_by_source_resources(): void
    {
        $fixture = $this->fixture();
        $slots = app(AvailabilitySlotService::class);
        $slot = $slots->create(
            $fixture['actor']['session_id'],
            $this->slotPayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );

        $beforeBooking = $this->calendar($fixture['actor']['session_id']);
        $beforeBooking->assertOk()->assertJsonCount(0);

        $booked = $slots->book(
            $fixture['actor']['session_id'],
            $slot['id'],
            $fixture['student']['id'],
            (string) Str::uuid7(),
            '"v1"',
        );

        $calendar = $this->calendar($fixture['actor']['session_id']);
        $calendar->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $booked['id'])
            ->assertJsonPath('0.source_kind', 'availability_slot_booking')
            ->assertJsonPath('0.source_id', $booked['id'])
            ->assertJsonPath('0.event_type', 'driving_lesson')
            ->assertJsonPath('0.student_id', $fixture['student']['id'])
            ->assertJsonPath('0.instructor_id', $fixture['instructor']['id'])
            ->assertJsonPath('0.status', 'booked')
            ->assertJsonPath('0.version', 2);

        $correctStudent = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->getJson('/api/v1/calendar/events?event_type[]=driving_lesson&student_id='.$fixture['student']['id']);
        $correctStudent->assertOk()->assertJsonCount(1);

        $wrongStudent = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->getJson('/api/v1/calendar/events?event_type[]=driving_lesson&student_id='.(string) Str::uuid7());
        $wrongStudent->assertOk()->assertJsonCount(0);

        $correctStaff = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->getJson('/api/v1/calendar/events?event_type[]=driving_lesson&staff_id='.$fixture['instructor']['id']);
        $correctStaff->assertOk()->assertJsonCount(1);
    }

    public function test_formalization_switches_calendar_source_without_duplicate_item(): void
    {
        $fixture = $this->fixture();
        $slots = app(AvailabilitySlotService::class);
        $slot = $slots->create(
            $fixture['actor']['session_id'],
            $this->slotPayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );
        $booked = $slots->book(
            $fixture['actor']['session_id'],
            $slot['id'],
            $fixture['student']['id'],
            (string) Str::uuid7(),
            '"v1"',
        );

        $before = $this->calendar($fixture['actor']['session_id']);
        $before->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.source_kind', 'availability_slot_booking')
            ->assertJsonPath('0.source_id', $booked['id']);

        $formalized = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders([
                'Idempotency-Key' => (string) Str::uuid7(),
                'If-Match' => '"v2"',
            ])
            ->postJson("/api/v1/availability-slots/{$slot['id']}/formalize", [
                'course_enrollment_id' => $fixture['course']['id'],
                'display_name' => 'Jazda po rezerwacji',
            ]);
        $formalized->assertCreated();
        $trainingSessionId = (string) $formalized->json('training_session.id');

        $after = $this->calendar($fixture['actor']['session_id']);
        $after->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.source_kind', 'training_session')
            ->assertJsonPath('0.source_id', $trainingSessionId)
            ->assertJsonPath('0.name', 'Jazda po rezerwacji');

        $this->assertNotSame($slot['id'], $after->json('0.source_id'));
    }

    public function test_cancelled_booking_is_not_projected_as_calendar_driving_lesson(): void
    {
        $fixture = $this->fixture();
        $slots = app(AvailabilitySlotService::class);
        $slot = $slots->create(
            $fixture['actor']['session_id'],
            $this->slotPayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );
        $slots->book(
            $fixture['actor']['session_id'],
            $slot['id'],
            $fixture['student']['id'],
            (string) Str::uuid7(),
            '"v1"',
        );

        $visible = $this->calendar($fixture['actor']['session_id']);
        $visible->assertOk()->assertJsonCount(1);

        $slots->cancel(
            $fixture['actor']['session_id'],
            $slot['id'],
            'Rezygnacja',
            (string) Str::uuid7(),
            '"v2"',
        );

        $afterCancel = $this->calendar($fixture['actor']['session_id']);
        $afterCancel->assertOk()->assertJsonCount(0);
    }

    private function calendar(string $sessionId): TestResponse
    {
        return $this->withSession(['auth_session_id' => $sessionId])
            ->getJson('/api/v1/calendar/events?event_type[]=driving_lesson');
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
            'calendar.view', 'calendar.manage.organization', 'calendar.publish_student_slots', 'calendar.book_for_student',
            'students.view', 'students.create', 'courses.view', 'courses.create',
            'training_sessions.view', 'training_sessions.create', 'training_sessions.edit', 'training_sessions.cancel',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        $instructor = app(StaffService::class)->create(
            $actor['session_id'],
            [
                'email' => 'projection.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
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
            [
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
            ],
            (string) Str::uuid7(),
        );

        return compact('actor', 'instructor', 'student', 'course');
    }

    /** @return array<string,mixed> */
    private function slotPayload(string $instructorId): array
    {
        return [
            'instructor_id' => $instructorId,
            'vehicle_id' => null,
            'location_id' => null,
            'starts_at' => '2026-09-11T10:00:00+02:00',
            'ends_at' => '2026-09-11T11:00:00+02:00',
        ];
    }
}
