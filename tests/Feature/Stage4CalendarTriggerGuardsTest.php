<?php

namespace Tests\Feature;

use App\Modules\CalendarTraining\AvailabilitySlotService;
use App\Modules\CalendarTraining\CalendarEventService;
use App\Modules\CalendarTraining\TrainingSessionService;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentService;
use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\TriggerGuards\CalendarTriggerGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4CalendarTriggerGuardsTest extends TestCase
{
    public function test_calendar_guards_protect_history_exact_claim_sets_and_companion_metadata(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        DB::beginTransaction();

        try {
            $exit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'preflight',
                '--force' => true,
            ]);
            $this->assertSame(0, $exit, Artisan::output());

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

            CalendarTriggerGuards::install();

            $fixture = $this->fixture();

            $event = app(CalendarEventService::class)->create(
                $fixture['actor']['session_id'],
                [
                    'event_type' => 'general_event',
                    'name' => 'Guarded event',
                    'starts_at' => '2026-09-13T08:00:00+02:00',
                    'ends_at' => '2026-09-13T09:00:00+02:00',
                    'student_id' => $fixture['student']['id'],
                    'instructor_id' => $fixture['instructor']['id'],
                    'vehicle_id' => null,
                    'location_id' => null,
                    'custom_meeting_place' => null,
                ],
                (string) Str::uuid7(),
            );
            $this->forceDeferredChecks();

            $eventHistoryId = (string) DB::table('calendar_event_lifecycle_events')
                ->where('calendar_event_id', $event['id'])
                ->value('id');
            $this->expectImmediateGuardViolation(
                fn () => DB::table('calendar_event_lifecycle_events')
                    ->where('id', $eventHistoryId)
                    ->update(['reason' => 'rewrite']),
            );

            $this->expectDeferredGuardViolation(
                fn () => DB::table('calendar_events')
                    ->where('id', $event['id'])
                    ->update(['starts_at' => '2026-09-13T08:15:00+02:00']),
            );

            $eventClaimId = (string) DB::table('calendar_resource_claims')
                ->where('claim_owner_kind', 'calendar_event')
                ->where('claim_owner_id', $event['id'])
                ->whereNotNull('student_id')
                ->value('id');
            $this->expectDeferredGuardViolation(
                fn () => DB::table('calendar_resource_claims')->where('id', $eventClaimId)->delete(),
            );

            $slot = app(AvailabilitySlotService::class)->create(
                $fixture['actor']['session_id'],
                [
                    'instructor_id' => $fixture['instructor']['id'],
                    'vehicle_id' => null,
                    'location_id' => null,
                    'starts_at' => '2026-09-13T10:00:00+02:00',
                    'ends_at' => '2026-09-13T11:00:00+02:00',
                ],
                (string) Str::uuid7(),
            );
            $this->forceDeferredChecks();

            $booked = app(AvailabilitySlotService::class)->book(
                $fixture['actor']['session_id'],
                $slot['id'],
                $fixture['student']['id'],
                (string) Str::uuid7(),
                '"v1"',
            );
            $this->assertSame('booked', $booked['status']);
            $this->forceDeferredChecks();

            $slotClaimId = (string) DB::table('calendar_resource_claims')
                ->where('claim_owner_kind', 'availability_slot_booking')
                ->where('claim_owner_id', $slot['id'])
                ->whereNotNull('student_id')
                ->value('id');
            $this->expectDeferredGuardViolation(
                fn () => DB::table('calendar_resource_claims')->where('id', $slotClaimId)->delete(),
            );

            $slotHistoryId = (string) DB::table('availability_slot_lifecycle_events')
                ->where('availability_slot_id', $slot['id'])
                ->orderByDesc('slot_version_after')
                ->value('id');
            $this->expectImmediateGuardViolation(
                fn () => DB::table('availability_slot_lifecycle_events')
                    ->where('id', $slotHistoryId)
                    ->update(['reason' => 'rewrite']),
            );

            $session = app(TrainingSessionService::class)->create(
                $fixture['actor']['session_id'],
                $fixture['course']['id'],
                [
                    'session_type' => 'theory',
                    'starts_at' => '2026-09-13T12:00:00+02:00',
                    'ends_at' => '2026-09-13T13:00:00+02:00',
                    'instructor_id' => $fixture['instructor']['id'],
                    'vehicle_id' => null,
                    'location_id' => null,
                ],
                (string) Str::uuid7(),
            );
            $this->forceDeferredChecks();

            $sessionClaimId = (string) DB::table('calendar_resource_claims')
                ->where('claim_owner_kind', 'training_session')
                ->where('claim_owner_id', $session['id'])
                ->whereNotNull('student_id')
                ->value('id');
            $this->expectDeferredGuardViolation(
                fn () => DB::table('calendar_resource_claims')->where('id', $sessionClaimId)->delete(),
            );

            $this->expectDeferredGuardViolation(function () use ($fixture, $session): void {
                DB::table('training_session_calendar_details')->insert([
                    'organization_id' => $fixture['actor']['organization_id'],
                    'training_session_id' => $session['id'],
                    'display_name' => 'Theory cannot use driving-lesson companion',
                    'custom_meeting_place' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            app(CalendarEventService::class)->cancel(
                $fixture['actor']['session_id'],
                $event['id'],
                'cancelled',
                (string) Str::uuid7(),
                '"v1"',
            );
            $this->forceDeferredChecks();
            $this->assertSame(
                0,
                DB::table('calendar_resource_claims')
                    ->where('claim_owner_kind', 'calendar_event')
                    ->where('claim_owner_id', $event['id'])
                    ->count(),
            );
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
            'calendar.view',
            'calendar.manage.organization',
            'calendar.publish_student_slots',
            'calendar.book_for_student',
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
                'email' => 'calendar.trigger.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
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
                'recognized_external_practical_minutes' => 0,
                'lead_instructor_id' => $instructor['id'],
                'location_id' => null,
            ],
            (string) Str::uuid7(),
        );

        return compact('actor', 'instructor', 'student', 'course');
    }

    private function forceDeferredChecks(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function expectImmediateGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_calendar_immediate');

        try {
            $operation();
            $this->fail('Expected immediate calendar trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_calendar_immediate');
        }
    }

    private function expectDeferredGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_calendar_deferred');

        try {
            $operation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Expected deferred calendar trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_calendar_deferred');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }
}
