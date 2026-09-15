<?php

namespace Tests\Feature;

use App\Modules\CalendarTraining\TrainingSessionService;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class TrainingSessionCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_planned_session_creates_exact_shared_claims_and_rejects_overlap_but_allows_adjacent_interval(): void
    {
        $fixture = $this->fixture();
        $first = $this->training()->create(
            $fixture['actor']['session_id'],
            $fixture['course']['id'],
            $this->sessionPayload($fixture['instructor']['id'], '2026-09-11T10:00:00+02:00', '2026-09-11T11:00:00+02:00'),
            (string) Str::uuid7(),
        );

        $this->assertSame(2, DB::table('calendar_resource_claims')->where('claim_owner_id', $first['id'])->count());
        $this->assertSame(0, DB::table('training_session_attendance')->where('training_session_id', $first['id'])->count());
        $this->assertSame(0, DB::table('training_hour_ledger_entries')->where('training_session_id', $first['id'])->count());

        $exception = $this->captureDomainException(fn () => $this->training()->create(
            $fixture['actor']['session_id'],
            $fixture['course']['id'],
            $this->sessionPayload($fixture['instructor']['id'], '2026-09-11T10:30:00+02:00', '2026-09-11T11:30:00+02:00'),
            (string) Str::uuid7(),
        ));
        $this->assertSame('CALENDAR_RESOURCE_CONFLICT', $exception->machineCode);
        $this->assertSame(1, DB::table('training_sessions')->count());

        $adjacent = $this->training()->create(
            $fixture['actor']['session_id'],
            $fixture['course']['id'],
            $this->sessionPayload($fixture['instructor']['id'], '2026-09-11T11:00:00+02:00', '2026-09-11T12:00:00+02:00'),
            (string) Str::uuid7(),
        );
        $this->assertSame('planned', $adjacent['status']);
        $this->assertSame(2, DB::table('training_sessions')->count());
    }

    public function test_verified_present_attendance_then_complete_creates_exactly_one_credit_and_releases_claims(): void
    {
        $fixture = $this->fixture();
        $session = $this->training()->create(
            $fixture['actor']['session_id'],
            $fixture['course']['id'],
            $this->sessionPayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );

        $attendance = $this->training()->recordAttendance(
            $fixture['actor']['session_id'],
            $session['id'],
            'present',
            (string) Str::uuid7(),
            '"v1"',
        );
        $this->assertSame(2, $attendance['session_version']);

        $completed = $this->training()->complete($fixture['actor']['session_id'], $session['id'], (string) Str::uuid7());
        $this->assertSame('completed', $completed['status']);
        $this->assertSame(3, $completed['version']);
        $this->assertSame(1, DB::table('training_hour_ledger_entries')->where('training_session_id', $session['id'])->where('entry_type', 'credit')->count());
        $credit = DB::table('training_hour_ledger_entries')->where('training_session_id', $session['id'])->firstOrFail();
        $this->assertSame(60, (int) $credit->minutes);
        $this->assertSame('theory', $credit->training_part);
        $this->assertSame(0, DB::table('calendar_resource_claims')->where('claim_owner_id', $session['id'])->count());

        $freshSecondComplete = $this->captureDomainException(fn () => $this->training()->complete(
            $fixture['actor']['session_id'],
            $session['id'],
            (string) Str::uuid7(),
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $freshSecondComplete->machineCode);
        $this->assertSame(1, DB::table('training_hour_ledger_entries')->where('training_session_id', $session['id'])->where('entry_type', 'credit')->count());
    }

    public function test_absent_completion_and_cancellation_never_credit_formal_time(): void
    {
        $fixture = $this->fixture();
        $absent = $this->training()->create(
            $fixture['actor']['session_id'],
            $fixture['course']['id'],
            $this->sessionPayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );
        $this->training()->recordAttendance($fixture['actor']['session_id'], $absent['id'], 'absent', (string) Str::uuid7(), '"v1"');
        $this->training()->complete($fixture['actor']['session_id'], $absent['id'], (string) Str::uuid7());
        $this->assertSame(0, DB::table('training_hour_ledger_entries')->where('training_session_id', $absent['id'])->count());
        $this->assertSame(0, DB::table('calendar_resource_claims')->where('claim_owner_id', $absent['id'])->count());

        $cancelled = $this->training()->create(
            $fixture['actor']['session_id'],
            $fixture['course']['id'],
            $this->sessionPayload($fixture['instructor']['id'], '2026-09-12T10:00:00+02:00', '2026-09-12T11:00:00+02:00'),
            (string) Str::uuid7(),
        );
        $this->training()->recordAttendance($fixture['actor']['session_id'], $cancelled['id'], 'present', (string) Str::uuid7(), '"v1"');
        $result = $this->training()->cancel($fixture['actor']['session_id'], $cancelled['id'], 'Odwołane zajęcia', (string) Str::uuid7());
        $this->assertSame('cancelled', $result['status']);
        $this->assertSame(1, DB::table('training_session_attendance')->where('training_session_id', $cancelled['id'])->count());
        $this->assertSame(0, DB::table('training_hour_ledger_entries')->where('training_session_id', $cancelled['id'])->count());
        $this->assertSame(0, DB::table('calendar_resource_claims')->where('claim_owner_id', $cancelled['id'])->count());
    }

    public function test_attendance_and_patch_use_training_session_version_and_patch_cannot_change_session_type(): void
    {
        $fixture = $this->fixture();
        $session = $this->training()->create(
            $fixture['actor']['session_id'],
            $fixture['course']['id'],
            $this->sessionPayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );

        $missing = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->putJson("/api/v1/training-sessions/{$session['id']}/attendance", ['status' => 'present']);
        $missing->assertStatus(428)->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        $attendance = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('If-Match', '"v1"')
            ->putJson("/api/v1/training-sessions/{$session['id']}/attendance", ['status' => 'present']);
        $attendance->assertOk()->assertHeader('ETag', '"v2"');

        $stale = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('If-Match', '"v1"')
            ->patchJson("/api/v1/training-sessions/{$session['id']}", ['ends_at' => '2026-09-11T11:30:00+02:00']);
        $stale->assertConflict()->assertJsonPath('error.code', 'RESOURCE_VERSION_CONFLICT');

        $typeMutation = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('If-Match', '"v2"')
            ->patchJson("/api/v1/training-sessions/{$session['id']}", ['session_type' => 'practical']);
        $typeMutation->assertUnprocessable();
    }

    public function test_http_create_and_complete_are_idempotent_and_do_not_duplicate_credit(): void
    {
        $fixture = $this->fixture();
        $createKey = (string) Str::uuid7();
        $payload = $this->sessionPayload($fixture['instructor']['id']);

        $created = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('Idempotency-Key', $createKey)
            ->postJson("/api/v1/course-enrollments/{$fixture['course']['id']}/training-sessions", $payload);
        $created->assertCreated()->assertHeader('ETag', '"v1"');
        $sessionId = (string) $created->json('id');

        $replay = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('Idempotency-Key', $createKey)
            ->postJson("/api/v1/course-enrollments/{$fixture['course']['id']}/training-sessions", $payload);
        $replay->assertCreated();
        $this->assertSame($sessionId, $replay->json('id'));
        $this->assertSame(1, DB::table('training_sessions')->count());

        $this->training()->recordAttendance($fixture['actor']['session_id'], $sessionId, 'present', (string) Str::uuid7(), '"v1"');
        $completeKey = (string) Str::uuid7();
        $first = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('Idempotency-Key', $completeKey)
            ->postJson("/api/v1/training-sessions/{$sessionId}/complete");
        $first->assertOk()->assertHeader('ETag', '"v3"');

        $second = $this->withSession(['auth_session_id' => $fixture['actor']['session_id']])
            ->withHeader('Idempotency-Key', $completeKey)
            ->postJson("/api/v1/training-sessions/{$sessionId}/complete");
        $second->assertOk()->assertHeader('ETag', '"v3"');
        $this->assertSame(1, DB::table('training_hour_ledger_entries')->where('training_session_id', $sessionId)->where('entry_type', 'credit')->count());
    }

    public function test_manual_correction_is_append_only_requires_fresh_course_version_and_preserves_nonnegative_projection(): void
    {
        $fixture = $this->fixture();
        $session = $this->training()->create(
            $fixture['actor']['session_id'],
            $fixture['course']['id'],
            $this->sessionPayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );
        $this->training()->recordAttendance($fixture['actor']['session_id'], $session['id'], 'present', (string) Str::uuid7(), '"v1"');
        $this->training()->complete($fixture['actor']['session_id'], $session['id'], (string) Str::uuid7());
        $credit = DB::table('training_hour_ledger_entries')->where('training_session_id', $session['id'])->firstOrFail();

        $correction = $this->training()->correctHours(
            $fixture['actor']['session_id'],
            $fixture['course']['id'],
            'theory',
            -15,
            'Korekta protokołu',
            (string) $credit->id,
            (string) Str::uuid7(),
            '"v1"',
        );
        $this->assertSame('correction', $correction['entry_type']);
        $this->assertSame(2, $correction['course_version']);
        $this->assertSame(2, DB::table('training_hour_ledger_entries')->where('course_enrollment_id', $fixture['course']['id'])->count());
        $this->assertSame(45, (int) DB::table('training_hour_ledger_entries')->where('course_enrollment_id', $fixture['course']['id'])->where('training_part', 'theory')->sum('minutes'));

        $stale = $this->captureDomainException(fn () => $this->training()->correctHours(
            $fixture['actor']['session_id'],
            $fixture['course']['id'],
            'theory',
            5,
            'Stale correction',
            null,
            (string) Str::uuid7(),
            '"v1"',
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $stale->machineCode);

        $negative = $this->captureDomainException(fn () => $this->training()->correctHours(
            $fixture['actor']['session_id'],
            $fixture['course']['id'],
            'theory',
            -100,
            'Invalid negative projection',
            null,
            (string) Str::uuid7(),
            '"v2"',
        ));
        $this->assertSame('VALIDATION_FAILED', $negative->machineCode);
        $this->assertSame(2, DB::table('training_hour_ledger_entries')->where('course_enrollment_id', $fixture['course']['id'])->count());
    }

    public function test_own_scope_is_bound_to_active_staff_profile_and_session_instructor(): void
    {
        $fixture = $this->fixture();
        app(StaffService::class)->createPanelAccount($fixture['actor']['session_id'], $fixture['instructor']['id'], (string) Str::uuid7());
        $link = DB::table('staff_membership_links')->where('staff_profile_id', $fixture['instructor']['id'])->whereNull('unlinked_at')->firstOrFail();
        DB::table('organization_memberships')->where('id', $link->organization_membership_id)->update(['status' => 'active']);
        foreach (['training_sessions.view', 'training_sessions.create', 'training_sessions.edit'] as $permission) {
            FoundationSchema::grant((string) $link->organization_membership_id, $permission, ['own']);
        }

        $ownSessionId = (string) Str::uuid7();
        DB::table('auth_sessions')->insert([
            'id' => $ownSessionId,
            'user_id' => DB::table('organization_memberships')->where('id', $link->organization_membership_id)->value('user_id'),
            'organization_membership_id' => $link->organization_membership_id,
            'token_or_framework_session_hash' => hash('sha256', $ownSessionId),
            'created_at' => now(),
        ]);

        $created = $this->training()->create(
            $ownSessionId,
            $fixture['course']['id'],
            $this->sessionPayload($fixture['instructor']['id']),
            (string) Str::uuid7(),
        );
        $this->assertSame($created['id'], $this->training()->get($ownSessionId, $created['id'])['id']);

        $otherInstructor = $this->instructor($fixture['actor']);
        $other = $this->training()->create(
            $fixture['actor']['session_id'],
            $fixture['course']['id'],
            $this->sessionPayload($otherInstructor['id'], '2026-09-12T10:00:00+02:00', '2026-09-12T11:00:00+02:00'),
            (string) Str::uuid7(),
        );
        $denied = $this->captureDomainException(fn () => $this->training()->get($ownSessionId, $other['id']));
        $this->assertSame('RESOURCE_NOT_FOUND', $denied->machineCode);
    }

    private function training(): TrainingSessionService
    {
        return app(TrainingSessionService::class);
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
            'students.view', 'students.create', 'students.edit',
            'courses.view', 'courses.create', 'courses.edit', 'courses.cancel', 'courses.restore', 'courses.stage.change',
            'training_sessions.view', 'training_sessions.create', 'training_sessions.edit', 'training_sessions.cancel',
            'training_hours.correct', 'course_requirements.correct', 'external_training.recognize',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        $instructor = $this->instructor($actor);
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

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @return array<string,mixed>
     */
    private function instructor(array $actor): array
    {
        return app(StaffService::class)->create(
            $actor['session_id'],
            [
                'email' => 'training.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
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
    private function sessionPayload(string $instructorId, string $startsAt = '2026-09-11T10:00:00+02:00', string $endsAt = '2026-09-11T11:00:00+02:00'): array
    {
        return [
            'session_type' => 'theory',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'instructor_id' => $instructorId,
            'vehicle_id' => null,
            'location_id' => null,
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
