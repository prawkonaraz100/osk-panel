<?php

namespace Tests\Feature;

use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentCourseScopeAuthorizer;
use App\Modules\StudentsCourses\StudentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class StudentsCoursesCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FoundationSchema::reset();
    }

    public function test_pre_course_incomplete_student_is_allowed_but_formal_course_is_rejected(): void
    {
        $actor = $this->studentCourseActor();
        $student = $this->student($actor, ['pesel' => null, 'no_pesel' => false]);

        $this->assertNull($student['pesel_masked']);

        $exception = $this->captureDomainException(fn () => $this->course($actor, $student['id']));
        $this->assertSame('VALIDATION_FAILED', $exception->machineCode);
        $this->assertDatabaseCount('course_enrollments', 0);
    }

    public function test_no_pesel_with_birth_date_is_valid_formal_identity(): void
    {
        $actor = $this->studentCourseActor();
        $student = $this->student($actor, [
            'pesel' => null,
            'no_pesel' => true,
            'birth_date' => '1990-05-17',
        ]);

        $course = $this->course($actor, $student['id']);
        $this->assertSame($student['id'], $course['student_id']);
        $this->assertDatabaseCount('pkk_profiles', 1);
        $this->assertDatabaseCount('training_requirement_profiles', 1);
    }

    public function test_course_create_is_atomic_across_pkk_requirements_external_history_and_audit(): void
    {
        $actor = $this->studentCourseActor();
        $student = $this->student($actor);
        $pkk = 'PKK-SECRET-123456';
        $course = $this->course($actor, $student['id'], [
            'pkk_number' => $pkk,
            'driving_category_code' => 'C+E',
            'recognized_external_practical_minutes' => 120,
        ]);

        $this->assertSame('C+E', $course['driving_category_code']);
        $this->assertStringNotContainsString($pkk, (string) $course['pkk_reference_masked']);
        $this->assertSame(1, DB::table('course_enrollment_lifecycle_events')->where('course_enrollment_id', $course['id'])->count());
        $this->assertSame(1, DB::table('pkk_profiles')->where('course_enrollment_id', $course['id'])->whereNull('superseded_at')->count());
        $this->assertSame(1, DB::table('training_requirement_profiles')->where('course_enrollment_id', $course['id'])->whereNull('superseded_at')->count());
        $this->assertSame(1, DB::table('recognized_external_training')->where('course_enrollment_id', $course['id'])->count());

        $profile = app(CourseEnrollmentService::class)->currentRequirements($actor['session_id'], $course['id']);
        $this->assertFalse($profile['theory_training_required']);
        $this->assertSame(0, $profile['minimum_theory_minutes']);
        $this->assertSame(1500, $profile['minimum_practical_minutes']);
        $this->assertTrue($profile['internal_practical_exam_required']);

        $auditText = DB::table('audit_logs')->where('entity_id', $course['id'])->pluck('after_redacted_json')->implode(' ');
        $this->assertStringNotContainsString($pkk, $auditText);
        $this->assertStringNotContainsString('pkk_number', $auditText);
    }

    public function test_verified_basic_b_requirement_profile_uses_1350_theory_and_1800_practical_minutes(): void
    {
        $actor = $this->studentCourseActor();
        $student = $this->student($actor);
        $course = $this->course($actor, $student['id'], ['driving_category_code' => 'B']);

        $profile = app(CourseEnrollmentService::class)->currentRequirements($actor['session_id'], $course['id']);

        $this->assertTrue($profile['theory_training_required']);
        $this->assertSame(1350, $profile['minimum_theory_minutes']);
        $this->assertTrue($profile['internal_theory_exam_required']);
        $this->assertSame(1800, $profile['minimum_practical_minutes']);
    }

    public function test_held_b1_recalculates_basic_b_theory_to_zero_and_practice_down_by_600(): void
    {
        $actor = $this->studentCourseActor();
        $student = $this->student($actor);
        $course = $this->course($actor, $student['id'], ['driving_category_code' => 'B']);

        $profile = app(CourseEnrollmentService::class)->updateRequirementContext(
            $actor['session_id'],
            $course['id'],
            ['held_categories' => ['B1']],
            (string) Str::uuid7(),
            '"v1"',
        );

        $this->assertFalse($profile['theory_training_required']);
        $this->assertSame(0, $profile['minimum_theory_minutes']);
        $this->assertSame(1200, $profile['minimum_practical_minutes']);
        $this->assertSame('recognized_prior_theory', $profile['exemption_basis_code']);
        $this->assertSame(2, DB::table('training_requirement_profiles')->where('course_enrollment_id', $course['id'])->count());
        $this->assertSame(1, DB::table('training_requirement_profiles')->where('course_enrollment_id', $course['id'])->whereNull('superseded_at')->count());
    }

    public function test_art_23a_exemption_preserves_profile_history_and_disables_theory(): void
    {
        $actor = $this->studentCourseActor();
        $student = $this->student($actor);
        $course = $this->course($actor, $student['id'], ['driving_category_code' => 'C']);

        $profile = app(CourseEnrollmentService::class)->addExemptionDecision(
            $actor['session_id'],
            $course['id'],
            'art_23a',
            'state-exam-result',
            'Państwowa teoria zdana przed kursem',
            (string) Str::uuid7(),
            '"v1"',
        );

        $this->assertFalse($profile['theory_training_required']);
        $this->assertFalse($profile['internal_theory_exam_required']);
        $this->assertSame('art_23a', $profile['exemption_basis_code']);
        $this->assertSame(1, DB::table('course_exemption_decisions')->whereNull('revoked_at')->count());
        $this->assertSame(2, DB::table('training_requirement_profiles')->where('course_enrollment_id', $course['id'])->count());
    }

    public function test_duplicate_student_pesel_is_blocked_even_after_archive(): void
    {
        $actor = $this->studentCourseActor();
        $first = $this->student($actor, ['pesel' => '44051401458']);
        app(StudentService::class)->archive($actor['session_id'], $first['id'], (string) Str::uuid7(), null);

        $exception = $this->captureDomainException(fn () => $this->student($actor, ['pesel' => '44051401458']));

        $this->assertSame('RESOURCE_VERSION_CONFLICT', $exception->machineCode);
        $this->assertSame(1, DB::table('students')->where('organization_id', $actor['organization_id'])->count());
    }

    public function test_active_course_blocks_student_archive_and_cancel_then_archive_preserves_history(): void
    {
        $actor = $this->studentCourseActor();
        $student = $this->student($actor);
        $course = $this->course($actor, $student['id']);

        $blocked = $this->captureDomainException(fn () => app(StudentService::class)->archive(
            $actor['session_id'], $student['id'], (string) Str::uuid7(), 'archive',
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $blocked->machineCode);

        $cancelled = app(CourseEnrollmentService::class)->cancel(
            $actor['session_id'], $course['id'], 'course cancelled', (string) Str::uuid7(), '"v1"',
        );
        $archived = app(StudentService::class)->archive(
            $actor['session_id'], $student['id'], (string) Str::uuid7(), 'after cancellation',
        );

        $this->assertNotNull($cancelled['cancelled_at']);
        $this->assertNotNull($archived['archived_at']);
        $this->assertSame(2, DB::table('course_enrollment_lifecycle_events')->where('course_enrollment_id', $course['id'])->count());
        $this->assertSame(1, DB::table('course_enrollments')->where('id', $course['id'])->count());
    }

    public function test_course_restore_rejects_archived_student_and_succeeds_after_student_restore(): void
    {
        $actor = $this->studentCourseActor();
        $student = $this->student($actor);
        $course = $this->course($actor, $student['id']);
        app(CourseEnrollmentService::class)->cancel(
            $actor['session_id'], $course['id'], 'cancel', (string) Str::uuid7(), '"v1"',
        );
        app(StudentService::class)->archive($actor['session_id'], $student['id'], (string) Str::uuid7(), null);

        $blocked = $this->captureDomainException(fn () => app(CourseEnrollmentService::class)->restore(
            $actor['session_id'], $course['id'], (string) Str::uuid7(), '"v2"',
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $blocked->machineCode);

        app(StudentService::class)->restore($actor['session_id'], $student['id'], (string) Str::uuid7());
        $restored = app(CourseEnrollmentService::class)->restore(
            $actor['session_id'], $course['id'], (string) Str::uuid7(), '"v2"',
        );
        $this->assertNull($restored['cancelled_at']);
        $this->assertSame(3, $restored['version']);
    }

    public function test_nonterminal_stage_change_is_audited_but_training_completed_is_fail_closed(): void
    {
        $actor = $this->studentCourseActor();
        $student = $this->student($actor);
        $course = $this->course($actor, $student['id']);

        $theory = app(CourseEnrollmentService::class)->changeStage(
            $actor['session_id'], $course['id'], 'theory', null, (string) Str::uuid7(), '"v1"',
        );
        $this->assertSame('theory', $theory['training_stage']);
        $this->assertSame(2, $theory['version']);

        $blocked = $this->captureDomainException(fn () => app(CourseEnrollmentService::class)->changeStage(
            $actor['session_id'], $course['id'], 'training_completed', null, (string) Str::uuid7(), '"v2"',
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $blocked->machineCode);
        $this->assertNull(DB::table('course_enrollments')->where('id', $course['id'])->value('completed_at'));
    }

    public function test_course_update_requires_current_version_and_category_change_requires_pkk_revalidation(): void
    {
        $actor = $this->studentCourseActor();
        $student = $this->student($actor);
        $course = $this->course($actor, $student['id']);

        $missingPkk = $this->captureDomainException(fn () => app(CourseEnrollmentService::class)->update(
            $actor['session_id'],
            $course['id'],
            ['driving_category_code' => 'C'],
            (string) Str::uuid7(),
            '"v1"',
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $missingPkk->machineCode);

        $updated = app(CourseEnrollmentService::class)->update(
            $actor['session_id'],
            $course['id'],
            ['driving_category_code' => 'C', 'pkk_number' => 'PKK-NEW-CTX'],
            (string) Str::uuid7(),
            '"v1"',
        );
        $this->assertSame('C', $updated['driving_category_code']);
        $this->assertSame(2, $updated['version']);
        $this->assertSame(2, DB::table('pkk_profiles')->where('course_enrollment_id', $course['id'])->count());
        $this->assertSame(1, DB::table('pkk_profiles')->where('course_enrollment_id', $course['id'])->whereNull('superseded_at')->count());

        $stale = $this->captureDomainException(fn () => app(CourseEnrollmentService::class)->update(
            $actor['session_id'], $course['id'], ['lead_instructor_id' => $updated['lead_instructor_id']], (string) Str::uuid7(), '"v1"',
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $stale->machineCode);
    }

    public function test_external_training_is_append_history_and_revoke_does_not_delete_record(): void
    {
        $actor = $this->studentCourseActor();
        $student = $this->student($actor);
        $course = $this->course($actor, $student['id']);

        $external = app(CourseEnrollmentService::class)->recognizeExternalTraining(
            $actor['session_id'],
            $course['id'],
            'practical',
            180,
            'OSK-OLD',
            'doc-1',
            'transfer evidence',
            (string) Str::uuid7(),
            '"v1"',
        );
        $this->assertSame(180, $external['recognized_minutes']);

        $revoked = app(CourseEnrollmentService::class)->revokeExternalTraining(
            $actor['session_id'],
            $course['id'],
            $external['id'],
            'correction',
            (string) Str::uuid7(),
            '"v2"',
        );
        $this->assertNotNull($revoked['revoked_at']);
        $this->assertSame(1, DB::table('recognized_external_training')->where('id', $external['id'])->count());
        $this->assertSame(3, (int) DB::table('course_enrollments')->where('id', $course['id'])->value('version'));
    }

    public function test_cross_tenant_instructor_is_rejected(): void
    {
        $actor = $this->studentCourseActor();
        $other = $this->studentCourseActor();
        $student = $this->student($actor);
        $foreignInstructor = $this->instructor($other);

        $exception = $this->captureDomainException(fn () => $this->course(
            $actor,
            $student['id'],
            ['lead_instructor_id' => $foreignInstructor['id']],
            false,
        ));

        $this->assertSame('VALIDATION_FAILED', $exception->machineCode);
        $this->assertDatabaseCount('course_enrollments', 0);
    }

    public function test_assigned_students_scope_resolves_only_non_cancelled_lead_instructor_courses(): void
    {
        $owner = $this->studentCourseActor();
        $instructor = $this->instructor($owner);
        $student = $this->student($owner);
        $course = $this->course($owner, $student['id'], ['lead_instructor_id' => $instructor['id']], false);

        app(StaffService::class)->createPanelAccount($owner['session_id'], $instructor['id'], (string) Str::uuid7());
        $link = DB::table('staff_membership_links')
            ->where('staff_profile_id', $instructor['id'])
            ->whereNull('unlinked_at')
            ->firstOrFail();
        DB::table('organization_memberships')->where('id', $link->organization_membership_id)->update(['status' => 'active']);
        FoundationSchema::grant((string) $link->organization_membership_id, 'students.view', ['assigned_students']);

        $sessionId = (string) Str::uuid7();
        DB::table('auth_sessions')->insert([
            'id' => $sessionId,
            'user_id' => DB::table('organization_memberships')->where('id', $link->organization_membership_id)->value('user_id'),
            'organization_membership_id' => $link->organization_membership_id,
            'token_or_framework_session_hash' => hash('sha256', $sessionId),
            'created_at' => now(),
        ]);

        $visibility = app(StudentCourseScopeAuthorizer::class)->visibility($sessionId, 'students.view');
        $this->assertEquals([$student['id']], $visibility['student_ids']);

        app(CourseEnrollmentService::class)->cancel(
            $owner['session_id'], $course['id'], 'ended', (string) Str::uuid7(), '"v1"',
        );
        $visibility = app(StudentCourseScopeAuthorizer::class)->visibility($sessionId, 'students.view');
        $this->assertSame([], $visibility['student_ids']);
    }

    public function test_http_student_create_and_course_create_require_idempotency_and_emit_version_etag(): void
    {
        $actor = $this->studentCourseActor();
        $instructor = $this->instructor($actor);

        $studentResponse = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/students', [
                'first_name' => 'Anna',
                'last_name' => 'Nowak',
                'pesel' => '02070803628',
            ]);

        $studentResponse->assertCreated()->assertHeader('ETag', '"v1"');
        $studentId = (string) $studentResponse->json('id');

        $courseResponse = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/students/{$studentId}/course-enrollments", [
                'training_type' => 'basic',
                'driving_category_code' => 'B',
                'pkk_number' => 'HTTP-PKK-123',
                'started_at' => '2026-09-11T10:00:00+02:00',
                'lead_instructor_id' => $instructor['id'],
            ]);

        $courseResponse->assertCreated()->assertHeader('ETag', '"v1"');
        $this->assertSame('B', $courseResponse->json('driving_category_code'));
        $this->assertNotSame('HTTP-PKK-123', $courseResponse->json('pkk_reference_masked'));
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function studentCourseActor(): array
    {
        $actor = FoundationSchema::actor();
        foreach ([
            'students.view', 'students.create', 'students.edit', 'students.archive', 'students.restore',
            'courses.view', 'courses.create', 'courses.edit', 'courses.cancel', 'courses.restore', 'courses.stage.change',
            'course_requirements.correct', 'external_training.recognize',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        return $actor;
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function student(array $actor, array $overrides = []): array
    {
        return app(StudentService::class)->create(
            $actor['session_id'],
            [
                'first_name' => 'Anna',
                'last_name' => 'Nowak',
                'pesel' => '02070803628',
                'no_pesel' => false,
                ...$overrides,
            ],
            (string) Str::uuid7(),
        );
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function instructor(array $actor, array $overrides = []): array
    {
        return app(StaffService::class)->create(
            $actor['session_id'],
            [
                'email' => 'instructor.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
                'first_name' => 'Jan',
                'last_name' => 'Instruktor',
                'staff_type_codes' => ['Instructor'],
                'category_ids' => [],
                'location_ids' => [],
                ...$overrides,
            ],
            (string) Str::uuid7(),
        );
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function course(array $actor, string $studentId, array $overrides = [], bool $createInstructor = true): array
    {
        $instructorId = $overrides['lead_instructor_id'] ?? null;
        if ($instructorId === null && $createInstructor) {
            $instructorId = $this->instructor($actor)['id'];
        }

        return app(CourseEnrollmentService::class)->create(
            $actor['session_id'],
            $studentId,
            [
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
                ...$overrides,
            ],
            (string) Str::uuid7(),
        );
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
