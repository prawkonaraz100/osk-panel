<?php

namespace Tests\Feature;

use App\Modules\CalendarTraining\CalendarEventService;
use App\Modules\CalendarTraining\TrainingSessionService;
use App\Modules\ResourcesCore\LocationService;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class CalendarEventCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_general_event_uses_shared_claim_boundary_and_formal_driving_lesson_row_is_rejected(): void
    {
        $fixture = $this->fixture(true);
        $training = app(TrainingSessionService::class)->create(
            $fixture['actor']['session_id'],
            $fixture['course']['id'],
            [
                'session_type' => 'practical',
                'starts_at' => '2026-09-11T10:00:00+02:00',
                'ends_at' => '2026-09-11T11:00:00+02:00',
                'instructor_id' => $fixture['instructor']['id'],
                'vehicle_id' => null,
                'location_id' => null,
            ],
            (string) Str::uuid7(),
        );

        $conflict = $this->captureDomainException(fn () => $this->calendar()->create(
            $fixture['actor']['session_id'],
            $this->payload($fixture['instructor']['id'], '2026-09-11T10:30:00+02:00', '2026-09-11T10:45:00+02:00'),
            (string) Str::uuid7(),
        ));
        $this->assertSame('CALENDAR_RESOURCE_CONFLICT', $conflict->machineCode);
        $this->assertSame(0, DB::table('calendar_events')->count());
        $this->assertSame(2, DB::table('calendar_resource_claims')->where('claim_owner_id', $training['id'])->count());

        $event = $this->calendar()->create(
            $fixture['actor']['session_id'],
            $this->payload($fixture['instructor']['id'], '2026-09-11T11:00:00+02:00', '2026-09-11T12:00:00+02:00'),
            (string) Str::uuid7(),
        );
        $this->assertSame('general_event', $event['event_type']);
        $this->assertSame(1, DB::table('calendar_event_lifecycle_events')->where('calendar_event_id', $event['id'])->where('event_type', 'created')->count());
        $this->assertSame(1, DB::table('calendar_resource_claims')->where('claim_owner_kind', 'calendar_event')->where('claim_owner_id', $event['id'])->count());

        $formalCopy = $this->payload($fixture['instructor']['id'], '2026-09-12T10:00:00+02:00', '2026-09-12T11:00:00+02:00');
        $formalCopy['event_type'] = 'driving_lesson';
        $rejected = $this->captureDomainException(fn () => $this->calendar()->create(
            $fixture['actor']['session_id'],
            $formalCopy,
            (string) Str::uuid7(),
        ));
        $this->assertSame('VALIDATION_FAILED', $rejected->machineCode);
    }

    public function test_material_patch_is_versioned_noop_is_not_and_meeting_place_sources_are_exclusive(): void
    {
        $fixture = $this->fixture();
        $event = $this->calendar()->create(
            $fixture['actor']['session_id'],
            $this->payload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );

        $noop = $this->calendar()->update(
            $fixture['actor']['session_id'],
            $event['id'],
            ['name' => 'Spotkanie'],
            (string) Str::uuid7(),
            '"v1"',
        );
        $this->assertSame(1, $noop['version']);
        $this->assertSame(1, DB::table('calendar_event_lifecycle_events')->where('calendar_event_id', $event['id'])->count());

        $updated = $this->calendar()->update(
            $fixture['actor']['session_id'],
            $event['id'],
            ['name' => 'Nowa nazwa', 'custom_meeting_place' => '  Plac testowy  '],
            (string) Str::uuid7(),
            '"v1"',
        );
        $this->assertSame(2, $updated['version']);
        $this->assertSame('Plac testowy', $updated['custom_meeting_place']);
        $this->assertSame(1, DB::table('calendar_event_lifecycle_events')->where('calendar_event_id', $event['id'])->where('event_type', 'updated')->count());

        $stale = $this->captureDomainException(fn () => $this->calendar()->update(
            $fixture['actor']['session_id'],
            $event['id'],
            ['name' => 'Stale'],
            (string) Str::uuid7(),
            '"v1"',
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $stale->machineCode);

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
        $locationId = (string) $location['id'];
        $exclusive = $this->captureDomainException(fn () => $this->calendar()->update(
            $fixture['actor']['session_id'],
            $event['id'],
            ['location_id' => $locationId],
            (string) Str::uuid7(),
            '"v2"',
        ));
        $this->assertSame('VALIDATION_FAILED', $exclusive->machineCode);
    }

    public function test_terminal_commands_require_if_match_are_idempotent_and_never_credit_training_hours(): void
    {
        $fixture = $this->fixture();
        $key = (string) Str::uuid7();
        $created = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/calendar/events', $this->payload($fixture['instructor']['id']));
        $created->assertCreated()->assertHeader('ETag', '"v1"');
        $eventId = (string) $created->json('id');

        $missing = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/calendar/events/{$eventId}/complete");
        $missing->assertStatus(428)->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        $completeKey = (string) Str::uuid7();
        $first = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders(['Idempotency-Key' => $completeKey, 'If-Match' => '"v1"'])
            ->postJson("/api/v1/calendar/events/{$eventId}/complete");
        $first->assertOk()->assertHeader('ETag', '"v2"')->assertJsonPath('status', 'completed');

        $replay = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeaders(['Idempotency-Key' => $completeKey, 'If-Match' => '"v1"'])
            ->postJson("/api/v1/calendar/events/{$eventId}/complete");
        $replay->assertOk()->assertHeader('ETag', '"v2"');
        $this->assertSame(1, DB::table('calendar_event_lifecycle_events')->where('calendar_event_id', $eventId)->where('event_type', 'completed')->count());
        $this->assertSame(0, DB::table('calendar_resource_claims')->where('claim_owner_id', $eventId)->count());
        $this->assertSame(0, DB::table('training_hour_ledger_entries')->count());
    }

    public function test_calendar_manage_own_is_canonical_instructor_ownership_and_cannot_transfer(): void
    {
        $fixture = $this->fixture();
        app(StaffService::class)->createPanelAccount($fixture['actor']['session_id'], $fixture['instructor']['id'], (string) Str::uuid7());
        $link = DB::table('staff_membership_links')->where('staff_profile_id', $fixture['instructor']['id'])->whereNull('unlinked_at')->firstOrFail();
        DB::table('organization_memberships')->where('id', $link->organization_membership_id)->update(['status' => 'active']);
        FoundationSchema::grant((string) $link->organization_membership_id, 'calendar.view', ['own']);
        FoundationSchema::grant((string) $link->organization_membership_id, 'calendar.manage.own', ['own']);

        $ownSessionId = (string) Str::uuid7();
        DB::table('auth_sessions')->insert([
            'id' => $ownSessionId,
            'user_id' => DB::table('organization_memberships')->where('id', $link->organization_membership_id)->value('user_id'),
            'organization_membership_id' => $link->organization_membership_id,
            'token_or_framework_session_hash' => hash('sha256', $ownSessionId),
            'created_at' => now(),
        ]);

        $event = $this->calendar()->create(
            $ownSessionId,
            $this->payload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );
        $this->assertSame($event['id'], $this->calendar()->get($ownSessionId, $event['id'])['id']);

        $other = $this->instructor($fixture['actor']);
        $denied = $this->captureDomainException(fn () => $this->calendar()->update(
            $ownSessionId,
            $event['id'],
            ['instructor_id' => $other['id']],
            (string) Str::uuid7(),
            '"v1"',
        ));
        $this->assertSame('RESOURCE_NOT_FOUND', $denied->machineCode);
        $this->assertSame($fixture['instructor']['id'], DB::table('calendar_events')->where('id', $event['id'])->value('instructor_id'));
    }

    private function calendar(): CalendarEventService
    {
        return app(CalendarEventService::class);
    }

    /**
     * @return array{
     *   actor:array{organization_id:string,user_id:string,membership_id:string,session_id:string},
     *   instructor:array<string,mixed>,
     *   student:array<string,mixed>,
     *   course?:array<string,mixed>
     * }
     */
    private function fixture(bool $withCourse = false): array
    {
        $actor = FoundationSchema::actor();
        foreach ([
            'calendar.view', 'calendar.manage.organization',
            'locations.create',
            'students.view', 'students.create', 'courses.view', 'courses.create',
            'training_sessions.view', 'training_sessions.create', 'training_sessions.edit', 'training_sessions.cancel',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        $instructor = $this->instructor($actor);
        $student = app(StudentService::class)->create(
            $actor['session_id'],
            ['first_name' => 'Anna', 'last_name' => 'Nowak', 'pesel' => '02070803628', 'no_pesel' => false],
            (string) Str::uuid7(),
        );
        $result = compact('actor', 'instructor', 'student');

        if ($withCourse) {
            $result['course'] = app(CourseEnrollmentService::class)->create(
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
        }

        return $result;
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @return array<string,mixed>
     */
    private function instructor(array $actor): array
    {
        return app(StaffService::class)->create(
            $actor['session_id'],
            [
                'email' => 'calendar.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
                'first_name' => 'Jan',
                'last_name' => 'Instruktor',
                'staff_type_codes' => ['Instructor'],
                'category_ids' => [],
                'location_ids' => [],
            ],
            (string) Str::uuid7(),
        );
    }

    /** @return array<string,mixed> */
    private function payload(
        string $instructorId,
        string $startsAt = '2026-09-11T10:00:00+02:00',
        string $endsAt = '2026-09-11T11:00:00+02:00',
    ): array {
        return [
            'event_type' => 'general_event',
            'name' => 'Spotkanie',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'student_id' => null,
            'instructor_id' => $instructorId,
            'vehicle_id' => null,
            'location_id' => null,
            'custom_meeting_place' => null,
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
