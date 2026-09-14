<?php

namespace Tests\Feature;

use App\Modules\InternalExams\ExamStationCredentialService;
use App\Modules\InternalExams\InternalExamAnswerSheetService;
use App\Modules\InternalExams\InternalExamService;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentService;
use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\TriggerGuards\ExamsTriggerGuards;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4ExamsTriggerGuardsTest extends TestCase
{
    public function test_exam_guards_preserve_inventory_lifecycle_station_chain_and_finished_evidence(): void
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

            ExamsTriggerGuards::install();

            $actor = $this->examActor();
            $course = $this->course($actor);
            $fixtures = $this->examFixtures($actor, $course);

            $service = app(InternalExamService::class);
            $attempt = $service->createAttempt(
                $actor['session_id'],
                $course['id'],
                'theory',
                'pl',
                (string) Str::uuid7(),
            );
            $this->forceDeferredChecks();

            $ledgerId = (string) DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_inventory_entry_id', $fixtures['inventory_id'])
                ->where('event_type', 'unit_reserved')
                ->value('id');

            $this->expectImmediateGuardViolation(
                fn () => DB::table('internal_exam_inventory_ledger_entries')
                    ->where('id', $ledgerId)
                    ->update(['available_delta' => 0]),
            );

            $this->expectDeferredGuardViolation(
                fn () => DB::table('internal_exam_inventory_entries')
                    ->where('id', $fixtures['inventory_id'])
                    ->update(['current_state' => 'available']),
            );

            $this->expectImmediateGuardViolation(
                fn () => DB::table('internal_exam_attempts')
                    ->where('id', $attempt['id'])
                    ->update(['course_attempt_sequence' => 2]),
            );

            $stationA = $this->station($actor);
            $stationB = $this->station($actor);

            $access = $service->createAccess(
                $actor['session_id'],
                $attempt['id'],
                'assigned_exam_station',
                $stationA['id'],
                null,
                null,
                (string) Str::uuid7(),
            );
            $this->forceDeferredChecks();

            $service->startLocal(
                $actor['session_id'],
                $access['id'],
                $stationA['credential'],
                (string) Str::uuid7(),
            );
            $this->forceDeferredChecks();

            $service->transferStation(
                $actor['session_id'],
                $attempt['id'],
                $stationB['credential'],
                'awaria stanowiska testowego',
                (string) Str::uuid7(),
            );
            $this->forceDeferredChecks();

            $secondSessionId = (string) DB::table('internal_exam_station_sessions')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->where('session_sequence', 2)
                ->value('id');
            $this->expectImmediateGuardViolation(
                fn () => DB::table('internal_exam_station_sessions')
                    ->where('id', $secondSessionId)
                    ->update(['transferred_from_session_id' => null]),
            );

            $attemptLifecycleId = (string) DB::table('internal_exam_attempt_lifecycle_events')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->where('version_after', 2)
                ->value('id');
            $this->expectImmediateGuardViolation(
                fn () => DB::table('internal_exam_attempt_lifecycle_events')
                    ->where('id', $attemptLifecycleId)
                    ->delete(),
            );

            $this->expectDeferredGuardViolation(function () use ($attempt): void {
                DB::table('internal_exam_attempts')->where('id', $attempt['id'])->update([
                    'status' => 'passed',
                    'version' => 3,
                    'finished_at' => now(),
                ]);
            });

            $result = $service->submitAsStaff(
                $actor['session_id'],
                $attempt['id'],
                [
                    ['ordinal' => 1, 'answer' => 'A'],
                    ['ordinal' => 2, 'answer' => true],
                ],
                (string) Str::uuid7(),
            );
            $this->assertTrue($result['passed']);
            $this->forceDeferredChecks();

            $resultId = (string) DB::table('internal_exam_results')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->value('id');
            $this->expectImmediateGuardViolation(
                fn () => DB::table('internal_exam_results')
                    ->where('id', $resultId)
                    ->update(['score' => 0]),
            );

            $questionId = (string) DB::table('internal_exam_attempt_questions')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->orderBy('ordinal')
                ->value('id');
            $this->expectImmediateGuardViolation(
                fn () => DB::table('internal_exam_attempt_questions')
                    ->where('id', $questionId)
                    ->update(['points_awarded' => 0]),
            );

            $document = app(InternalExamAnswerSheetService::class)->download(
                $actor['session_id'],
                $attempt['id'],
                (string) Str::uuid7(),
            );
            $this->forceDeferredChecks();

            $documentId = (string) $document['document_id'];
            $assetId = (string) DB::table('internal_exam_documents')
                ->where('id', $documentId)
                ->value('asset_id');

            $this->expectImmediateGuardViolation(
                fn () => DB::table('internal_exam_documents')
                    ->where('id', $documentId)
                    ->update(['content_hash' => str_repeat('f', 64)]),
            );

            $this->expectImmediateGuardViolation(
                fn () => DB::table('file_assets')
                    ->where('id', $assetId)
                    ->update(['sha256' => str_repeat('e', 64)]),
            );

            $this->assertSame(
                [1, 2],
                DB::table('internal_exam_station_sessions')
                    ->where('internal_exam_attempt_id', $attempt['id'])
                    ->orderBy('session_sequence')
                    ->pluck('session_sequence')
                    ->map(static fn ($value): int => (int) $value)
                    ->all(),
            );
        } finally {
            DB::rollBack();
        }
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function examActor(): array
    {
        $actor = FoundationSchema::actor();
        foreach ([
            'students.view',
            'students.create',
            'students.edit',
            'courses.view',
            'courses.create',
            'courses.edit',
            'course_requirements.correct',
            'exams.view',
            'exams.generate',
            'exams.access.send',
            'exams.start.local',
            'exams.results.view',
            'exams.documents.download',
            'exams.inventory.adjust',
            'exams.stations.view',
            'exams.stations.manage',
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
        $student = app(StudentService::class)->create(
            $actor['session_id'],
            [
                'first_name' => 'Anna',
                'last_name' => 'Egzamin',
                'contact_email' => 'exam.guard.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
                'pesel' => '02070803628',
                'no_pesel' => false,
            ],
            (string) Str::uuid7(),
        );

        $instructor = app(StaffService::class)->create(
            $actor['session_id'],
            [
                'email' => 'exam.trigger.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
                'first_name' => 'Jan',
                'last_name' => 'Instruktor',
                'staff_type_codes' => ['Instructor'],
                'category_ids' => [],
                'location_ids' => [],
            ],
            (string) Str::uuid7(),
        );

        return app(CourseEnrollmentService::class)->create(
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

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @param  array<string,mixed>  $course
     * @return array{inventory_id:string,capability_id:string,definition_id:string}
     */
    private function examFixtures(array $actor, array $course): array
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
            'source_reference' => 'stage4-trigger-proof',
            'created_at' => $now,
        ]);

        $composition = [
            'questions' => [
                [
                    'group' => 'basic',
                    'source_question_identifier' => 'trigger-q1',
                    'source_question_revision_identifier' => 'v1',
                    'question_snapshot_schema_version' => 1,
                    'question_snapshot' => [
                        'text' => 'Trigger proof question one',
                        'answer_type' => 'single',
                        'ordered_answer_options' => ['A', 'B'],
                        'correct_answer' => 'A',
                        'category_context' => 'B',
                    ],
                    'max_points' => 3,
                ],
                [
                    'group' => 'specialized',
                    'source_question_identifier' => 'trigger-q2',
                    'source_question_revision_identifier' => 'v1',
                    'question_snapshot_schema_version' => 1,
                    'question_snapshot' => [
                        'text' => 'Trigger proof question two',
                        'answer_type' => 'boolean',
                        'ordered_answer_options' => [true, false],
                        'correct_answer' => true,
                        'category_context' => 'B',
                    ],
                    'max_points' => 2,
                ],
            ],
        ];
        $scoring = [
            'question_scoring' => 'all_or_nothing',
            'pass_rule' => 'minimum_score',
            'pass_threshold' => 4,
        ];

        DB::table('internal_exam_definitions')->insert([
            'id' => $definitionId,
            'driving_category_id' => $categoryId,
            'exam_part' => 'theory',
            'language_code' => 'pl',
            'engine_kind' => 'question_test',
            'definition_version' => 'stage4-trigger-v1',
            'definition_schema_version' => 1,
            'composition_snapshot' => json_encode($composition, JSON_THROW_ON_ERROR),
            'scoring_policy_snapshot' => json_encode($scoring, JSON_THROW_ON_ERROR),
            'definition_content_hash' => hash(
                'sha256',
                json_encode([$composition, $scoring], JSON_THROW_ON_ERROR),
            ),
            'published_at' => $now,
            'retired_at' => null,
            'created_at' => $now,
        ]);

        DB::table('internal_exam_inventory_entries')->insert([
            'id' => $inventoryId,
            'organization_id' => $actor['organization_id'],
            'source_type' => 'free',
            'source_order_item_id' => null,
            'source_order_item_grant_ordinal' => null,
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

        return [
            'inventory_id' => $inventoryId,
            'capability_id' => $capabilityId,
            'definition_id' => $definitionId,
        ];
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @return array{id:string,credential:string}
     */
    private function station(array $actor): array
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

        return [
            'id' => $stationId,
            'credential' => (string) $issued['raw_credential'],
        ];
    }

    private function forceDeferredChecks(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function expectImmediateGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_exams_immediate');

        try {
            $operation();
            $this->fail('Expected immediate exams trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_exams_immediate');
        }
    }

    private function expectDeferredGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_exams_deferred');

        try {
            $operation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Expected deferred exams trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_exams_deferred');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }
}
