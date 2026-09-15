<?php

namespace Tests\Feature;

use App\Modules\CalendarTraining\TrainingSessionService;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentService;
use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\TriggerGuards\TrainingTriggerGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4TrainingTriggerGuardsTest extends TestCase
{
    public function test_training_trigger_guards_preserve_formal_history_requirements_credit_external_and_pkk_state(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        DB::beginTransaction();

        try {
            $preflightExit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'preflight',
                '--force' => true,
            ]);
            $this->assertSame(0, $preflightExit, Artisan::output());

            foreach ($plan->phaseSteps('write_fence') as $step) {
                ControlledMigrationContext::enter('write_fence', $step['node_id'], $plan->executionIdentity());

                try {
                    /** @var Migration $migration */
                    $migration = require base_path($step['migration_file']);
                    $migration->up();
                } finally {
                    ControlledMigrationContext::leave();
                }
            }

            TrainingTriggerGuards::install();

            $fixture = $this->fixture();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            $this->expectDeferredGuardViolation(function () use ($fixture): void {
                DB::table('students')->where('id', $fixture['student']['id'])->update([
                    'no_pesel_declared' => false,
                    'pesel_ciphertext' => null,
                    'pesel_lookup_hash' => null,
                    'birth_date' => null,
                ]);
            });

            $this->expectDeferredGuardViolation(function () use ($fixture): void {
                DB::table('students')->where('id', $fixture['student']['id'])->update([
                    'archived_at' => now(),
                    'archived_by_user_id' => $fixture['actor']['user_id'],
                ]);
            });

            $this->expectDeferredGuardViolation(function () use ($fixture): void {
                DB::table('course_enrollments')->where('id', $fixture['course']['id'])->increment('version');
            });

            $historyId = (string) DB::table('course_enrollment_lifecycle_events')
                ->where('course_enrollment_id', $fixture['course']['id'])
                ->value('id');
            $this->expectImmediateGuardViolation(
                fn () => DB::table('course_enrollment_lifecycle_events')
                    ->where('id', $historyId)
                    ->update(['reason' => 'rewritten']),
            );

            $this->expectDeferredGuardViolation(function () use ($fixture): void {
                DB::table('course_enrollments')
                    ->where('id', $fixture['course']['id'])
                    ->increment('requirements_revision');
            });

            $currentPkkId = (string) DB::table('pkk_profiles')
                ->where('course_enrollment_id', $fixture['course']['id'])
                ->whereNull('superseded_at')
                ->value('id');
            $this->expectDeferredGuardViolation(
                fn () => DB::table('pkk_profiles')
                    ->where('id', $currentPkkId)
                    ->update(['bound_training_type' => 'supplementary']),
            );

            $external = DB::table('recognized_external_training')
                ->where('course_enrollment_id', $fixture['course']['id'])
                ->firstOrFail();
            $this->expectImmediateGuardViolation(
                fn () => DB::table('recognized_external_training')
                    ->where('id', $external->id)
                    ->update(['recognized_minutes' => (int) $external->recognized_minutes + 1]),
            );

            $this->expectDeferredGuardViolation(function () use ($fixture, $external): void {
                DB::table('recognized_external_training')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $fixture['actor']['organization_id'],
                    'course_enrollment_id' => $fixture['course']['id'],
                    'training_part' => 'theory',
                    'recognized_minutes' => 10,
                    'record_role' => 'documented_transfer',
                    'source_kind' => (string) $external->source_kind,
                    'source_school_reference' => null,
                    'evidence_reference' => 'mismatch-test',
                    'reason' => 'mismatch test',
                    'approved_by_user_id' => $fixture['actor']['user_id'],
                    'recognized_for_driving_category_id' => $external->recognized_for_driving_category_id,
                    'recognized_for_training_type' => 'supplementary',
                    'supersedes_record_id' => null,
                    'created_at' => now(),
                    'superseded_at' => null,
                    'revoked_at' => null,
                    'revoked_by_user_id' => null,
                    'revocation_reason' => null,
                ]);
            });

            $session = app(TrainingSessionService::class)->create(
                $fixture['actor']['session_id'],
                $fixture['course']['id'],
                $this->sessionPayload($fixture['instructor']['id']),
                (string) Str::uuid7(),
            );
            app(TrainingSessionService::class)->recordAttendance(
                $fixture['actor']['session_id'],
                $session['id'],
                'present',
                (string) Str::uuid7(),
                '"v1"',
            );
            app(TrainingSessionService::class)->complete(
                $fixture['actor']['session_id'],
                $session['id'],
                (string) Str::uuid7(),
            );
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            $creditId = (string) DB::table('training_hour_ledger_entries')
                ->where('training_session_id', $session['id'])
                ->where('entry_type', 'credit')
                ->value('id');
            $this->expectImmediateGuardViolation(
                fn () => DB::table('training_hour_ledger_entries')
                    ->where('id', $creditId)
                    ->update(['minutes' => 1]),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('training_session_attendance')
                    ->where('training_session_id', $session['id'])
                    ->update(['status' => 'absent']),
            );

            $planned = app(TrainingSessionService::class)->create(
                $fixture['actor']['session_id'],
                $fixture['course']['id'],
                $this->sessionPayload(
                    $fixture['instructor']['id'],
                    '2026-09-12T10:00:00+02:00',
                    '2026-09-12T11:00:00+02:00',
                ),
                (string) Str::uuid7(),
            );

            $this->expectDeferredGuardViolation(function () use ($fixture, $planned): void {
                DB::table('training_hour_ledger_entries')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $fixture['actor']['organization_id'],
                    'course_enrollment_id' => $fixture['course']['id'],
                    'training_session_id' => $planned['id'],
                    'entry_type' => 'credit',
                    'training_part' => 'theory',
                    'minutes' => 60,
                    'source_entry_id' => null,
                    'reason' => 'invalid direct credit',
                    'actor_user_id' => $fixture['actor']['user_id'],
                    'created_at' => now(),
                ]);
            });
        } finally {
            DB::rollBack();
        }
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
            'students.view',
            'students.create',
            'students.edit',
            'courses.view',
            'courses.create',
            'courses.edit',
            'courses.cancel',
            'courses.restore',
            'courses.stage.change',
            'training_sessions.view',
            'training_sessions.create',
            'training_sessions.edit',
            'training_sessions.cancel',
            'training_hours.correct',
            'course_requirements.correct',
            'external_training.recognize',
        ] as $permission) {
            FoundationSchema::grant($actor['membership_id'], $permission, ['organization']);
        }

        $instructor = app(StaffService::class)->create(
            $actor['session_id'],
            [
                'email' => 'trigger.training.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
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
            [
                'first_name' => 'Anna',
                'last_name' => 'Nowak',
                'pesel' => '02070803628',
                'no_pesel' => false,
            ],
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
                'recognized_external_practical_minutes' => 60,
                'lead_instructor_id' => $instructor['id'],
                'location_id' => null,
            ],
            (string) Str::uuid7(),
        );

        return compact('actor', 'instructor', 'student', 'course');
    }

    /**
     * @return array<string,mixed>
     */
    private function sessionPayload(
        string $instructorId,
        string $startsAt = '2026-09-11T10:00:00+02:00',
        string $endsAt = '2026-09-11T11:00:00+02:00',
    ): array {
        return [
            'session_type' => 'theory',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'instructor_id' => $instructorId,
            'vehicle_id' => null,
            'location_id' => null,
        ];
    }

    private function expectImmediateGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_training_immediate');

        try {
            $operation();
            $this->fail('Expected immediate training trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_training_immediate');
        }
    }

    private function expectDeferredGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_training_deferred');

        try {
            $operation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Expected deferred training trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_training_deferred');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }
}
