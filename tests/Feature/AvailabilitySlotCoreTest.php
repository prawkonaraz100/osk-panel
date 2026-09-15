<?php

namespace Tests\Feature;

use App\Modules\CalendarTraining\AvailabilitySlotService;
use App\Modules\CalendarTraining\CalendarEventService;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class AvailabilitySlotCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_publication_is_non_reserving_and_material_patch_is_versioned_while_noop_is_not(): void
    {
        $fixture = $this->fixture();
        $created = $this->slots()->create(
            $fixture['actor']['session_id'],
            $this->slotPayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );

        $this->assertSame('available', $created['status']);
        $this->assertSame(1, $created['version']);
        $this->assertSame(0, DB::table('calendar_resource_claims')->count());
        $this->assertSame(1, DB::table('availability_slot_lifecycle_events')
            ->where('availability_slot_id', $created['id'])
            ->where('event_type', 'created')
            ->count());

        $noop = $this->slots()->update(
            $fixture['actor']['session_id'],
            $created['id'],
            ['ends_at' => '2026-09-11T11:00:00+02:00'],
            (string) Str::uuid7(),
            '"v1"',
        );
        $this->assertSame(1, $noop['version']);
        $this->assertSame(1, DB::table('availability_slot_lifecycle_events')->where('availability_slot_id', $created['id'])->count());

        $updated = $this->slots()->update(
            $fixture['actor']['session_id'],
            $created['id'],
            ['ends_at' => '2026-09-11T11:30:00+02:00'],
            (string) Str::uuid7(),
            '"v1"',
        );
        $this->assertSame(2, $updated['version']);
        $this->assertSame(1, DB::table('availability_slot_lifecycle_events')
            ->where('availability_slot_id', $created['id'])
            ->where('event_type', 'updated')
            ->count());
        $this->assertSame(0, DB::table('calendar_resource_claims')->count());
    }

    public function test_booking_is_idempotent_versioned_and_creates_exact_claims_without_training_effect(): void
    {
        $fixture = $this->fixture();
        $slot = $this->slots()->create(
            $fixture['actor']['session_id'],
            $this->slotPayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );

        $missing = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/availability-slots/{$slot['id']}/book", ['student_id' => $fixture['student']['id']]);
        $missing->assertStatus(428)->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        $key = (string) Str::uuid7();
        $booked = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders(['Idempotency-Key' => $key, 'If-Match' => '"v1"'])
            ->postJson("/api/v1/availability-slots/{$slot['id']}/book", ['student_id' => $fixture['student']['id']]);
        $booked->assertOk()
            ->assertHeader('ETag', '"v2"')
            ->assertJsonPath('status', 'booked')
            ->assertJsonPath('booked_student_id', $fixture['student']['id']);

        $replay = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders(['Idempotency-Key' => $key, 'If-Match' => '"v1"'])
            ->postJson("/api/v1/availability-slots/{$slot['id']}/book", ['student_id' => $fixture['student']['id']]);
        $replay->assertOk()->assertHeader('ETag', '"v2"');

        $this->assertSame(2, DB::table('calendar_resource_claims')
            ->where('claim_owner_kind', 'availability_slot_booking')
            ->where('claim_owner_id', $slot['id'])
            ->count());
        $this->assertSame(1, DB::table('availability_slot_lifecycle_events')
            ->where('availability_slot_id', $slot['id'])
            ->where('event_type', 'booked')
            ->count());
        $this->assertSame(0, DB::table('calendar_events')->count());
        $this->assertSame(0, DB::table('training_sessions')->count());
        $this->assertSame(0, DB::table('training_hour_ledger_entries')->count());
    }

    public function test_booking_conflict_rolls_back_slot_to_available_without_partial_claims(): void
    {
        $fixture = $this->fixture();
        app(CalendarEventService::class)->create(
            $fixture['actor']['session_id'],
            [
                'event_type' => 'general_event',
                'name' => 'Zajęty instruktor',
                'starts_at' => '2026-09-11T10:00:00+02:00',
                'ends_at' => '2026-09-11T11:00:00+02:00',
                'student_id' => null,
                'instructor_id' => $fixture['instructor']['id'],
                'vehicle_id' => null,
                'location_id' => null,
                'custom_meeting_place' => null,
            ],
            (string) Str::uuid7(),
        );
        $slot = $this->slots()->create(
            $fixture['actor']['session_id'],
            $this->slotPayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );

        $exception = $this->captureDomainException(fn () => $this->slots()->book(
            $fixture['actor']['session_id'],
            $slot['id'],
            $fixture['student']['id'],
            (string) Str::uuid7(),
            '"v1"',
        ));
        $this->assertSame('CALENDAR_RESOURCE_CONFLICT', $exception->machineCode);

        $current = DB::table('availability_slots')->where('id', $slot['id'])->firstOrFail();
        $this->assertSame('available', $current->status);
        $this->assertSame(1, (int) $current->version);
        $this->assertNull($current->booked_student_id);
        $this->assertSame(0, DB::table('calendar_resource_claims')
            ->where('claim_owner_kind', 'availability_slot_booking')
            ->where('claim_owner_id', $slot['id'])
            ->count());
        $this->assertSame(1, DB::table('availability_slot_lifecycle_events')->where('availability_slot_id', $slot['id'])->count());
    }

    public function test_cancel_booked_slot_releases_claims_preserves_student_snapshot_and_cannot_rebook(): void
    {
        $fixture = $this->fixture();
        $slot = $this->slots()->create(
            $fixture['actor']['session_id'],
            $this->slotPayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );
        $this->slots()->book(
            $fixture['actor']['session_id'],
            $slot['id'],
            $fixture['student']['id'],
            (string) Str::uuid7(),
            '"v1"',
        );

        $key = (string) Str::uuid7();
        $cancelled = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders(['Idempotency-Key' => $key, 'If-Match' => '"v2"'])
            ->postJson("/api/v1/availability-slots/{$slot['id']}/cancel", ['reason' => 'Zmiana planu']);
        $cancelled->assertOk()
            ->assertHeader('ETag', '"v3"')
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('booked_student_id', null);

        $replay = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders(['Idempotency-Key' => $key, 'If-Match' => '"v2"'])
            ->postJson("/api/v1/availability-slots/{$slot['id']}/cancel", ['reason' => 'Zmiana planu']);
        $replay->assertOk()->assertHeader('ETag', '"v3"');

        $history = DB::table('availability_slot_lifecycle_events')
            ->where('availability_slot_id', $slot['id'])
            ->where('event_type', 'cancelled')
            ->firstOrFail();
        $this->assertSame($fixture['student']['id'], $history->booking_student_id_snapshot);
        $this->assertSame('Zmiana planu', $history->reason);
        $this->assertSame(0, DB::table('calendar_resource_claims')
            ->where('claim_owner_kind', 'availability_slot_booking')
            ->where('claim_owner_id', $slot['id'])
            ->count());

        $freshBook = $this->captureDomainException(fn () => $this->slots()->book(
            $fixture['actor']['session_id'],
            $slot['id'],
            $fixture['student']['id'],
            (string) Str::uuid7(),
            '"v3"',
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $freshBook->machineCode);
        $this->assertSame(3, DB::table('availability_slot_lifecycle_events')->where('availability_slot_id', $slot['id'])->count());
    }

    public function test_publish_own_scope_cannot_transfer_slot_to_another_instructor(): void
    {
        $fixture = $this->fixture();
        app(StaffService::class)->createPanelAccount(
            $fixture['actor']['session_id'],
            $fixture['instructor']['id'],
            (string) Str::uuid7(),
        );
        $link = DB::table('staff_membership_links')
            ->where('staff_profile_id', $fixture['instructor']['id'])
            ->whereNull('unlinked_at')
            ->firstOrFail();
        DB::table('organization_memberships')->where('id', $link->organization_membership_id)->update(['status' => 'active']);
        FoundationSchema::grant((string) $link->organization_membership_id, 'calendar.view', ['own']);
        FoundationSchema::grant((string) $link->organization_membership_id, 'calendar.publish_student_slots', ['own']);

        $ownSessionId = (string) Str::uuid7();
        $userId = (string) DB::table('organization_memberships')
            ->where('id', $link->organization_membership_id)
            ->value('user_id');
        DB::table('auth_sessions')->insert([
            'id' => $ownSessionId,
            'user_id' => $userId,
            'organization_membership_id' => $link->organization_membership_id,
            'token_or_framework_session_hash' => hash('sha256', $ownSessionId),
            'created_at' => now(),
        ]);

        $slot = $this->slots()->create(
            $ownSessionId,
            $this->slotPayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );
        $other = app(StaffService::class)->create(
            $fixture['actor']['session_id'],
            [
                'email' => 'availability.other.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
                'first_name' => 'Ewa',
                'last_name' => 'Instruktor',
                'staff_type_codes' => ['Instructor'],
                'category_ids' => [],
                'location_ids' => [],
            ],
            (string) Str::uuid7(),
        );

        $denied = $this->captureDomainException(fn () => $this->slots()->update(
            $ownSessionId,
            $slot['id'],
            ['instructor_id' => $other['id']],
            (string) Str::uuid7(),
            '"v1"',
        ));
        $this->assertSame('RESOURCE_NOT_FOUND', $denied->machineCode);
        $this->assertSame($fixture['instructor']['id'], DB::table('availability_slots')->where('id', $slot['id'])->value('instructor_id'));
    }

    private function slots(): AvailabilitySlotService
    {
        return app(AvailabilitySlotService::class);
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
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        $instructor = app(StaffService::class)->create(
            $actor['session_id'],
            [
                'email' => 'availability.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
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

    /** @param callable():mixed $callback */
    private function captureDomainException(callable $callback): ResourceDomainException
    {
        try {
            $callback();
        } catch (ResourceDomainException $exception) {
            return $exception;
        }

        $this->fail('Expected ResourceDomainException.');
    }
}
