<?php

namespace Tests\Feature;

use App\Modules\CalendarTraining\AvailabilitySlotService;
use App\Modules\ResourcesCore\LocationService;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class AvailabilityFormalizationCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_formalization_transfers_booking_to_one_practical_training_session_atomically(): void
    {
        $fixture = $this->fixture();
        $slot = $this->bookedSlot($fixture);

        $response = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders([
                'Idempotency-Key' => (string) Str::uuid7(),
                'If-Match' => '"v2"',
            ])
            ->postJson("/api/v1/availability-slots/{$slot['id']}/formalize", [
                'course_enrollment_id' => $fixture['course']['id'],
                'display_name' => 'Jazda z rezerwacji',
            ]);

        $response->assertCreated()
            ->assertHeader('ETag', '"v3"')
            ->assertJsonPath('availability_slot.status', 'booked')
            ->assertJsonPath('availability_slot.version', 3)
            ->assertJsonPath('training_session.session_type', 'practical')
            ->assertJsonPath('training_session.status', 'planned')
            ->assertJsonPath('training_session.display_name', 'Jazda z rezerwacji');

        $trainingSessionId = (string) $response->json('training_session.id');
        $this->assertSame($trainingSessionId, $response->json('availability_slot.training_session_id'));
        $this->assertSame(0, DB::table('calendar_resource_claims')
            ->where('claim_owner_kind', 'availability_slot_booking')
            ->where('claim_owner_id', $slot['id'])
            ->count());
        $this->assertSame(2, DB::table('calendar_resource_claims')
            ->where('claim_owner_kind', 'training_session')
            ->where('claim_owner_id', $trainingSessionId)
            ->count());
        $this->assertSame(1, DB::table('availability_slot_lifecycle_events')
            ->where('availability_slot_id', $slot['id'])
            ->where('event_type', 'formalized')
            ->count());
        $this->assertSame(0, DB::table('calendar_events')->count());
        $this->assertSame(0, DB::table('training_session_attendance')->count());
        $this->assertSame(0, DB::table('training_hour_ledger_entries')->count());

        $calendar = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->getJson('/api/v1/calendar/events?event_type[]=driving_lesson');
        $calendar->assertOk()->assertJsonCount(1);
        $this->assertSame($trainingSessionId, $calendar->json('0.source_id'));

        $slotBeforeReschedule = DB::table('availability_slots')->where('id', $slot['id'])->firstOrFail();
        $rescheduled = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('If-Match', '"v1"')
            ->patchJson("/api/v1/training-sessions/{$trainingSessionId}", [
                'starts_at' => '2026-09-11T12:00:00+02:00',
                'ends_at' => '2026-09-11T13:00:00+02:00',
            ]);
        $rescheduled->assertOk()->assertHeader('ETag', '"v2"');

        $slotAfterReschedule = DB::table('availability_slots')->where('id', $slot['id'])->firstOrFail();
        $this->assertSame((string) $slotBeforeReschedule->starts_at, (string) $slotAfterReschedule->starts_at);
        $this->assertSame((string) $slotBeforeReschedule->ends_at, (string) $slotAfterReschedule->ends_at);

        $cancelSlot = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders([
                'Idempotency-Key' => (string) Str::uuid7(),
                'If-Match' => '"v3"',
            ])
            ->postJson("/api/v1/availability-slots/{$slot['id']}/cancel");
        $cancelSlot->assertStatus(409);
    }

    public function test_ambiguous_course_context_rolls_back_and_preserves_booking(): void
    {
        $fixture = $this->fixture();
        app(CourseEnrollmentService::class)->create(
            $fixture['actor']['session_id'],
            $fixture['student']['id'],
            $this->coursePayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );
        $slot = $this->bookedSlot($fixture);

        $response = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders([
                'Idempotency-Key' => (string) Str::uuid7(),
                'If-Match' => '"v2"',
            ])
            ->postJson("/api/v1/availability-slots/{$slot['id']}/formalize", []);

        $response->assertUnprocessable();
        $this->assertSame(0, DB::table('training_sessions')->count());

        $current = DB::table('availability_slots')->where('id', $slot['id'])->firstOrFail();
        $this->assertSame('booked', $current->status);
        $this->assertSame(2, (int) $current->version);
        $this->assertNull($current->training_session_id);
        $this->assertSame(2, DB::table('calendar_resource_claims')
            ->where('claim_owner_kind', 'availability_slot_booking')
            ->where('claim_owner_id', $slot['id'])
            ->count());
        $this->assertSame(2, DB::table('availability_slot_lifecycle_events')
            ->where('availability_slot_id', $slot['id'])
            ->count());
    }

    public function test_invalid_calendar_metadata_rolls_back_session_link_and_preserves_booking_claims(): void
    {
        $fixture = $this->fixture();
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
        $slot = $this->bookedSlot($fixture, (string) $location['id']);

        $response = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders([
                'Idempotency-Key' => (string) Str::uuid7(),
                'If-Match' => '"v2"',
            ])
            ->postJson("/api/v1/availability-slots/{$slot['id']}/formalize", [
                'course_enrollment_id' => $fixture['course']['id'],
                'custom_meeting_place' => 'Inny punkt',
            ]);

        $response->assertUnprocessable();
        $this->assertSame(0, DB::table('training_sessions')->count());
        $this->assertNull(DB::table('availability_slots')->where('id', $slot['id'])->value('training_session_id'));
        $this->assertSame(3, DB::table('calendar_resource_claims')
            ->where('claim_owner_kind', 'availability_slot_booking')
            ->where('claim_owner_id', $slot['id'])
            ->count());
        $this->assertSame(0, DB::table('calendar_resource_claims')
            ->where('claim_owner_kind', 'training_session')
            ->count());
    }

    public function test_same_formalization_idempotency_key_does_not_create_second_session(): void
    {
        $fixture = $this->fixture();
        $slot = $this->bookedSlot($fixture);
        $key = (string) Str::uuid7();
        $payload = ['course_enrollment_id' => $fixture['course']['id']];

        $first = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders(['Idempotency-Key' => $key, 'If-Match' => '"v2"'])
            ->postJson("/api/v1/availability-slots/{$slot['id']}/formalize", $payload);
        $first->assertCreated()->assertHeader('ETag', '"v3"');
        $trainingSessionId = (string) $first->json('training_session.id');

        $replay = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders(['Idempotency-Key' => $key, 'If-Match' => '"v2"'])
            ->postJson("/api/v1/availability-slots/{$slot['id']}/formalize", $payload);
        $replay->assertCreated()
            ->assertHeader('ETag', '"v3"')
            ->assertJsonPath('training_session.id', $trainingSessionId);

        $this->assertSame(1, DB::table('training_sessions')->count());
        $this->assertSame(1, DB::table('availability_slot_lifecycle_events')
            ->where('availability_slot_id', $slot['id'])
            ->where('event_type', 'formalized')
            ->count());

        $fresh = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders([
                'Idempotency-Key' => (string) Str::uuid7(),
                'If-Match' => '"v3"',
            ])
            ->postJson("/api/v1/availability-slots/{$slot['id']}/formalize", $payload);
        $fresh->assertStatus(409);
        $this->assertSame(1, DB::table('training_sessions')->count());
    }

    public function test_formalization_requires_training_create_permission_even_after_successful_booking(): void
    {
        $fixture = $this->fixture();
        $slot = $this->bookedSlot($fixture);

        DB::table('membership_permission_scopes')
            ->where('membership_id', $fixture['actor']['membership_id'])
            ->where('permission_code', 'training_sessions.create')
            ->delete();
        DB::table('membership_permissions')
            ->where('membership_id', $fixture['actor']['membership_id'])
            ->where('permission_code', 'training_sessions.create')
            ->delete();

        $response = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders([
                'Idempotency-Key' => (string) Str::uuid7(),
                'If-Match' => '"v2"',
            ])
            ->postJson("/api/v1/availability-slots/{$slot['id']}/formalize", [
                'course_enrollment_id' => $fixture['course']['id'],
            ]);

        $response->assertForbidden();
        $this->assertSame(0, DB::table('training_sessions')->count());
        $this->assertNull(DB::table('availability_slots')->where('id', $slot['id'])->value('training_session_id'));
        $this->assertSame(2, DB::table('calendar_resource_claims')
            ->where('claim_owner_kind', 'availability_slot_booking')
            ->where('claim_owner_id', $slot['id'])
            ->count());
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
                'email' => 'formalize.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
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

    /**
     * @param array{
     *   actor:array{organization_id:string,user_id:string,membership_id:string,session_id:string},
     *   instructor:array<string,mixed>,
     *   student:array<string,mixed>,
     *   course:array<string,mixed>
     * } $fixture
     * @return array<string,mixed>
     */
    private function bookedSlot(array $fixture, ?string $locationId = null): array
    {
        $slots = app(AvailabilitySlotService::class);
        $slot = $slots->create(
            $fixture['actor']['session_id'],
            [
                'instructor_id' => $fixture['instructor']['id'],
                'vehicle_id' => null,
                'location_id' => $locationId,
                'starts_at' => '2026-09-11T10:00:00+02:00',
                'ends_at' => '2026-09-11T11:00:00+02:00',
            ],
            (string) Str::uuid7(),
        );

        return $slots->book(
            $fixture['actor']['session_id'],
            $slot['id'],
            $fixture['student']['id'],
            (string) Str::uuid7(),
            '"v1"',
        );
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
