<?php

namespace Tests\Feature;

use App\Modules\InternalExams\ExamStationCredentialService;
use App\Modules\InternalExams\InternalExamService;
use App\Modules\InternalExams\InternalExamTokenService;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\ResourcesCore\StaffService;
use App\Modules\StudentsCourses\CourseEnrollmentService;
use App\Modules\StudentsCourses\StudentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class InternalExamCoreTest extends TestCase
{
    /** @var array<string,string> */
    private array $stationCredentialSecrets = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('internal_exams.station_heartbeat_fresh_seconds', 120);
        config()->set('internal_exams.execution_token_ttl_minutes', 60);
        config()->set('internal_exams.result_token_ttl_minutes', 1440);
        config()->set('internal_exams.remote_access_ttl_minutes', 120);
        config()->set('internal_exams.remote_public_base_url', 'https://learn.example.test/internal-exam');
        config()->set('internal_exams.token_verifier_key_v1', 'synthetic-internal-exam-verifier-key-v1-2026-09-11');
        config()->set('internal_exams.station_verifier_key_v1', 'synthetic-station-verifier-key-v1-2026-09-11');
        $this->stationCredentialSecrets = [];
        FoundationSchema::reset();
    }

    public function test_attempt_creation_reserves_exactly_one_consistent_inventory_unit_atomically(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $fixtures = $this->examFixtures($actor, $course);

        $attempt = app(InternalExamService::class)->createAttempt(
            $actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7(),
        );

        $this->assertSame('created', $attempt['status']);
        $this->assertSame(1, $attempt['version']);
        $reservation = DB::table('internal_exam_reservations')->where('internal_exam_attempt_id', $attempt['id'])->firstOrFail();
        $this->assertSame('reserved', $reservation->status);
        $this->assertSame('reserved', DB::table('internal_exam_inventory_entries')->where('id', $fixtures['inventory_id'])->value('current_state'));
        $this->assertSame(
            ['unit_granted', 'unit_reserved'],
            DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_inventory_entry_id', $fixtures['inventory_id'])
                ->orderBy('event_sequence')
                ->pluck('event_type')
                ->all(),
        );
        $this->assertSame(1, DB::table('internal_exam_attempt_lifecycle_events')->where('internal_exam_attempt_id', $attempt['id'])->count());
        $this->assertNull(DB::table('internal_exam_attempts')->where('id', $attempt['id'])->value('internal_exam_definition_id'));
    }

    public function test_attempt_creation_without_available_inventory_rolls_back_without_orphan_attempt(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $this->capabilityAndDefinition($course);

        $exception = $this->captureDomainException(fn () => app(InternalExamService::class)->createAttempt(
            $actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7(),
        ));

        $this->assertSame('RESOURCE_VERSION_CONFLICT', $exception->machineCode);
        $this->assertDatabaseCount('internal_exam_attempts', 0);
        $this->assertDatabaseCount('internal_exam_reservations', 0);
    }

    public function test_prestart_revoke_releases_same_unit_and_next_attempt_can_reserve_it_again(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $fixtures = $this->examFixtures($actor, $course);
        $station = $this->station($actor);
        $service = app(InternalExamService::class);

        $attempt = $service->createAttempt($actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7());
        $access = $service->createAccess(
            $actor['session_id'], $attempt['id'], 'assigned_exam_station', $station, null, null, (string) Str::uuid7(),
        );
        $revoked = $service->revokePrestart(
            $actor['session_id'], $access['id'], 'candidate unavailable', (string) Str::uuid7(),
        );

        $this->assertSame('revoked', $revoked['status']);
        $this->assertSame('created', DB::table('internal_exam_attempts')->where('id', $attempt['id'])->value('status'));
        $this->assertSame('released', DB::table('internal_exam_reservations')->where('internal_exam_attempt_id', $attempt['id'])->value('status'));
        $this->assertSame('available', DB::table('internal_exam_inventory_entries')->where('id', $fixtures['inventory_id'])->value('current_state'));

        $second = $service->createAttempt($actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7());
        $this->assertNotSame($attempt['id'], $second['id']);
        $this->assertSame(
            $fixtures['inventory_id'],
            DB::table('internal_exam_reservations')->where('internal_exam_attempt_id', $second['id'])->value('internal_exam_inventory_entry_id'),
        );
        $this->assertSame(
            ['unit_granted', 'unit_reserved', 'unit_released', 'unit_reserved'],
            DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_inventory_entry_id', $fixtures['inventory_id'])
                ->orderBy('event_sequence')
                ->pluck('event_type')
                ->all(),
        );
    }

    public function test_local_start_consumes_once_and_freezes_definition_and_question_evidence(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $fixtures = $this->examFixtures($actor, $course);
        $station = $this->station($actor);
        $service = app(InternalExamService::class);

        $attempt = $service->createAttempt($actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7());
        $access = $service->createAccess(
            $actor['session_id'], $attempt['id'], 'assigned_exam_station', $station, null, null, (string) Str::uuid7(),
        );
        $started = $service->startLocal($actor['session_id'], $access['id'], $this->stationCredential($station), (string) Str::uuid7());

        $this->assertSame('in_progress', $started['status']);
        $this->assertSame(2, $started['version']);
        $this->assertSame('consumed', DB::table('internal_exam_inventory_entries')->where('id', $fixtures['inventory_id'])->value('current_state'));
        $this->assertSame('consumed', DB::table('internal_exam_reservations')->where('internal_exam_attempt_id', $attempt['id'])->value('status'));
        $this->assertSame(2, DB::table('internal_exam_attempt_questions')->where('internal_exam_attempt_id', $attempt['id'])->count());
        $this->assertNotNull(DB::table('internal_exam_attempts')->where('id', $attempt['id'])->value('question_set_hash'));
        $this->assertSame(1, DB::table('internal_exam_station_sessions')->where('internal_exam_attempt_id', $attempt['id'])->whereNull('ended_at')->count());
        $this->assertSame(
            1,
            DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->where('event_type', 'unit_consumed')
                ->count(),
        );

        $retry = $this->captureDomainException(fn () => $service->startLocal(
            $actor['session_id'], $access['id'], $this->stationCredential($station), (string) Str::uuid7(),
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $retry->machineCode);
        $this->assertSame(
            1,
            DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->where('event_type', 'unit_consumed')
                ->count(),
        );
    }

    public function test_station_failover_preserves_access_binding_and_does_not_consume_inventory_again(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $this->examFixtures($actor, $course);
        $stationA = $this->station($actor);
        $stationB = $this->station($actor);
        $service = app(InternalExamService::class);

        $attempt = $service->createAttempt($actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7());
        $access = $service->createAccess(
            $actor['session_id'], $attempt['id'], 'assigned_exam_station', $stationA, null, null, (string) Str::uuid7(),
        );
        $service->startLocal($actor['session_id'], $access['id'], $this->stationCredential($stationA), (string) Str::uuid7());
        $beforeConsumed = DB::table('internal_exam_inventory_ledger_entries')
            ->where('internal_exam_attempt_id', $attempt['id'])
            ->where('event_type', 'unit_consumed')
            ->count();

        $transfer = $service->transferStation(
            $actor['session_id'],
            $attempt['id'],
            $this->stationCredential($stationB),
            'workstation failure',
            (string) Str::uuid7(),
        );

        $this->assertSame(2, $transfer['session_sequence']);
        $this->assertSame($stationB, $transfer['exam_station_id']);
        $this->assertSame($stationA, DB::table('internal_exam_accesses')->where('id', $access['id'])->value('station_id'));
        $this->assertSame('transferred', DB::table('internal_exam_station_sessions')->where('session_sequence', 1)->value('end_reason'));
        $this->assertSame($stationB, DB::table('internal_exam_station_sessions')->where('session_sequence', 2)->whereNull('ended_at')->value('exam_station_id'));
        $this->assertSame(
            $beforeConsumed,
            DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->where('event_type', 'unit_consumed')
                ->count(),
        );
    }

    public function test_http_station_transfer_requires_current_target_credential_and_replays_without_second_consume(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $this->examFixtures($actor, $course);
        $stationA = $this->station($actor);
        $stationB = $this->station($actor);
        $service = app(InternalExamService::class);
        $credentials = app(ExamStationCredentialService::class);

        $attempt = $service->createAttempt(
            $actor['session_id'],
            $course['id'],
            'theory',
            'pl',
            (string) Str::uuid7(),
        );
        $access = $service->createAccess(
            $actor['session_id'],
            $attempt['id'],
            'assigned_exam_station',
            $stationA,
            null,
            null,
            (string) Str::uuid7(),
        );
        $service->startLocal(
            $actor['session_id'],
            $access['id'],
            $this->stationCredential($stationA),
            (string) Str::uuid7(),
        );

        $consumedBefore = DB::table('internal_exam_inventory_ledger_entries')
            ->where('internal_exam_attempt_id', $attempt['id'])
            ->where('event_type', 'unit_consumed')
            ->count();
        $oldTargetCredential = $this->stationCredential($stationB);
        $rotated = $credentials->rotate(
            $actor['organization_id'],
            $stationB,
            $actor['user_id'],
            'failover_target_rotation',
        );
        $newTargetCredential = (string) $rotated['raw_credential'];
        $this->stationCredentialSecrets[$stationB] = $newTargetCredential;

        $revokedTarget = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('X-Exam-Station-Credential', $oldTargetCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/internal-exam-attempts/{$attempt['id']}/station-transfer", [
                'reason' => 'workstation failure',
            ]);
        $revokedTarget->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_EXAM_STATION_CREDENTIAL');
        $this->assertSame(
            1,
            DB::table('internal_exam_station_sessions')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->whereNull('ended_at')
                ->count(),
        );

        $key = (string) Str::uuid7();
        $transferred = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('X-Exam-Station-Credential', $newTargetCredential)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/internal-exam-attempts/{$attempt['id']}/station-transfer", [
                'reason' => 'workstation failure',
            ]);
        $transferred->assertOk()
            ->assertJsonPath('exam_station_id', $stationB)
            ->assertJsonPath('session_sequence', 2);
        $newSessionId = (string) $transferred->json('station_session_id');

        $replay = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('X-Exam-Station-Credential', $newTargetCredential)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/internal-exam-attempts/{$attempt['id']}/station-transfer", [
                'reason' => 'workstation failure',
            ]);
        $replay->assertOk()
            ->assertJsonPath('station_session_id', $newSessionId)
            ->assertJsonPath('session_sequence', 2);

        $this->assertSame(
            2,
            DB::table('internal_exam_station_sessions')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('internal_exam_station_sessions')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->whereNull('ended_at')
                ->count(),
        );
        $this->assertSame(
            $stationA,
            DB::table('internal_exam_accesses')->where('id', $access['id'])->value('station_id'),
        );
        $this->assertSame(
            $consumedBefore,
            DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->where('event_type', 'unit_consumed')
                ->count(),
        );
        $this->assertSame(
            'transferred',
            DB::table('internal_exam_station_sessions')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->where('session_sequence', 1)
                ->value('end_reason'),
        );
        $this->assertSame(
            $stationB,
            DB::table('internal_exam_station_sessions')
                ->where('id', $newSessionId)
                ->whereNull('ended_at')
                ->value('exam_station_id'),
        );
    }

    public function test_station_credential_rotation_revokes_old_secret_and_clears_authenticated_heartbeat(): void
    {
        $actor = $this->examActor();
        $station = $this->station($actor);
        $credentials = app(ExamStationCredentialService::class);
        $oldCredential = $this->stationCredential($station);

        $firstHeartbeat = $credentials->heartbeat($oldCredential, $actor['organization_id']);
        $this->assertSame($station, $firstHeartbeat['station_id']);
        $this->assertNotNull(DB::table('exam_stations')->where('id', $station)->value('last_authenticated_heartbeat_at'));

        $storedVerifier = (string) DB::table('exam_station_credentials')
            ->where('exam_station_id', $station)
            ->whereNull('revoked_at')
            ->value('secret_verifier');
        $this->assertNotSame($oldCredential, $storedVerifier);

        $rotated = $credentials->rotate(
            $actor['organization_id'],
            $station,
            $actor['user_id'],
            'operator_rotation',
        );
        $this->assertSame(2, $rotated['credential_sequence']);
        $this->assertNull(DB::table('exam_stations')->where('id', $station)->value('last_authenticated_heartbeat_at'));

        $oldRejected = $this->captureDomainException(
            fn () => $credentials->heartbeat($oldCredential, $actor['organization_id']),
        );
        $this->assertSame('INVALID_EXAM_STATION_CREDENTIAL', $oldRejected->machineCode);

        $newCredential = (string) $rotated['raw_credential'];
        $this->stationCredentialSecrets[$station] = $newCredential;
        $secondHeartbeat = $credentials->heartbeat($newCredential, $actor['organization_id']);
        $this->assertSame($station, $secondHeartbeat['station_id']);
        $this->assertNotNull(DB::table('exam_stations')->where('id', $station)->value('last_authenticated_heartbeat_at'));
    }

    public function test_http_station_credential_exact_binding_rotation_and_local_assigned_start_consume_once(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $fixtures = $this->examFixtures($actor, $course);
        $stationA = $this->station($actor);
        $stationB = $this->station($actor);
        $credentialA = $this->stationCredential($stationA);
        $credentialB = $this->stationCredential($stationB);
        $service = app(InternalExamService::class);
        $credentials = app(ExamStationCredentialService::class);

        $attempt = $service->createAttempt(
            $actor['session_id'],
            $course['id'],
            'theory',
            'pl',
            (string) Str::uuid7(),
        );

        $accessResponse = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('X-Exam-Station-Credential', $credentialA)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/internal-exam-attempts/{$attempt['id']}/accesses", [
                'mode' => 'local_current_workstation',
            ]);
        $accessResponse->assertCreated();
        $accessId = (string) $accessResponse->json('id');
        $this->assertSame($stationA, $accessResponse->json('station_id'));

        $wrongStationStart = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('X-Exam-Station-Credential', $credentialB)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/internal-exam-accesses/{$accessId}/start");
        $wrongStationStart->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_EXAM_STATION_CREDENTIAL');
        $this->assertSame('reserved', DB::table('internal_exam_inventory_entries')->where('id', $fixtures['inventory_id'])->value('current_state'));

        $rotated = $credentials->rotate(
            $actor['organization_id'],
            $stationA,
            $actor['user_id'],
            'prestart_rotation',
        );
        $newCredentialA = (string) $rotated['raw_credential'];
        $this->stationCredentialSecrets[$stationA] = $newCredentialA;

        $revokedStart = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('X-Exam-Station-Credential', $credentialA)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/internal-exam-accesses/{$accessId}/start");
        $revokedStart->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_EXAM_STATION_CREDENTIAL');

        $startKey = (string) Str::uuid7();
        $started = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('X-Exam-Station-Credential', $newCredentialA)
            ->withHeader('Idempotency-Key', $startKey)
            ->postJson("/api/v1/internal-exam-accesses/{$accessId}/start");
        $started->assertOk()->assertJsonPath('status', 'in_progress');

        $replay = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('X-Exam-Station-Credential', $newCredentialA)
            ->withHeader('Idempotency-Key', $startKey)
            ->postJson("/api/v1/internal-exam-accesses/{$accessId}/start");
        $replay->assertOk()->assertJsonPath('status', 'in_progress');
        $this->assertSame(
            1,
            DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->where('event_type', 'unit_consumed')
                ->count(),
        );

        $heartbeat = $this->withHeader('X-Exam-Station-Credential', $newCredentialA)
            ->postJson('/api/v1/internal-exam-stations/heartbeat');
        $heartbeat->assertOk()->assertJsonPath('station_id', $stationA);

        $secondInventoryId = $this->grantInventory($actor);
        $secondAttempt = $service->createAttempt(
            $actor['session_id'],
            $course['id'],
            'theory',
            'pl',
            (string) Str::uuid7(),
        );
        $assigned = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/internal-exam-attempts/{$secondAttempt['id']}/accesses", [
                'mode' => 'assigned_exam_station',
                'station_id' => $stationB,
            ]);
        $assigned->assertCreated();
        $assignedAccessId = (string) $assigned->json('id');

        $assignedStart = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('X-Exam-Station-Credential', $credentialB)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/internal-exam-accesses/{$assignedAccessId}/start");
        $assignedStart->assertOk()->assertJsonPath('status', 'in_progress');
        $this->assertSame(
            1,
            DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_attempt_id', $secondAttempt['id'])
                ->where('event_type', 'unit_consumed')
                ->count(),
        );
        $this->assertSame('consumed', DB::table('internal_exam_inventory_entries')->where('id', $secondInventoryId)->value('current_state'));
    }

    public function test_submit_scores_only_from_frozen_evidence_and_finishes_without_second_consumption(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $this->examFixtures($actor, $course);
        $station = $this->station($actor);
        $service = app(InternalExamService::class);

        $attempt = $service->createAttempt($actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7());
        $access = $service->createAccess(
            $actor['session_id'], $attempt['id'], 'assigned_exam_station', $station, null, null, (string) Str::uuid7(),
        );
        $service->startLocal($actor['session_id'], $access['id'], $this->stationCredential($station), (string) Str::uuid7());

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
        $this->assertSame(5, $result['score']);
        $this->assertSame(5, $result['max_score']);
        $this->assertSame(4, $result['pass_threshold']);
        $this->assertSame('passed', DB::table('internal_exam_attempts')->where('id', $attempt['id'])->value('status'));
        $this->assertSame('completed', DB::table('internal_exam_accesses')->where('id', $access['id'])->value('status'));
        $this->assertSame(1, DB::table('internal_exam_results')->where('internal_exam_attempt_id', $attempt['id'])->count());
        $this->assertSame(0, DB::table('internal_exam_attempt_questions')->where('internal_exam_attempt_id', $attempt['id'])->whereNull('points_awarded')->count());
        $this->assertSame(
            1,
            DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->where('event_type', 'unit_consumed')
                ->count(),
        );

        $retry = $this->captureDomainException(fn () => $service->submitAsStaff(
            $actor['session_id'], $attempt['id'], [
                ['ordinal' => 1, 'answer' => 'A'],
                ['ordinal' => 2, 'answer' => true],
            ], (string) Str::uuid7(),
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $retry->machineCode);
        $this->assertSame(1, DB::table('internal_exam_results')->where('internal_exam_attempt_id', $attempt['id'])->count());
    }

    public function test_technical_abort_preserves_consumed_inventory_and_frozen_question_evidence(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $fixtures = $this->examFixtures($actor, $course);
        $station = $this->station($actor);
        $service = app(InternalExamService::class);

        $attempt = $service->createAttempt($actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7());
        $access = $service->createAccess(
            $actor['session_id'], $attempt['id'], 'assigned_exam_station', $station, null, null, (string) Str::uuid7(),
        );
        $service->startLocal($actor['session_id'], $access['id'], $this->stationCredential($station), (string) Str::uuid7());

        $aborted = $service->technicalAbort(
            $actor['session_id'], $attempt['id'], 'station failure', (string) Str::uuid7(),
        );

        $this->assertSame('technical_abort', $aborted['status']);
        $this->assertSame('consumed', DB::table('internal_exam_inventory_entries')->where('id', $fixtures['inventory_id'])->value('current_state'));
        $this->assertSame('consumed', DB::table('internal_exam_reservations')->where('internal_exam_attempt_id', $attempt['id'])->value('status'));
        $this->assertSame(2, DB::table('internal_exam_attempt_questions')->where('internal_exam_attempt_id', $attempt['id'])->count());
        $this->assertDatabaseCount('internal_exam_results', 0);
        $this->assertSame('technical_abort', DB::table('internal_exam_station_sessions')->where('internal_exam_attempt_id', $attempt['id'])->value('end_reason'));
        $this->assertSame(
            1,
            DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->where('event_type', 'unit_consumed')
                ->count(),
        );
    }

    public function test_remote_access_token_is_nonrecoverable_purpose_scoped_and_exact_expiry_fails_closed(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $this->examFixtures($actor, $course);
        $service = app(InternalExamService::class);
        $tokens = app(InternalExamTokenService::class);

        $attempt = $service->createAttempt($actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7());
        $access = $service->createAccess(
            $actor['session_id'],
            $attempt['id'],
            'remote_link',
            null,
            null,
            CarbonImmutable::now()->addMinutes(90),
            (string) Str::uuid7(),
        );

        $raw = $access['one_time_remote_token'];
        $this->assertIsString($raw);
        $this->assertNotSame('', $raw);
        $tokenRow = DB::table('internal_exam_access_tokens')->where('internal_exam_access_id', $access['id'])->firstOrFail();
        $this->assertSame(64, strlen((string) $tokenRow->secret_verifier));
        $this->assertStringNotContainsString($raw, (string) $tokenRow->secret_verifier);

        $context = $tokens->verify($raw, 'exam_execution');
        $this->assertSame($attempt['id'], $context['attempt_id']);
        $this->assertSame($access['id'], $context['access_id']);
        $this->assertSame($actor['organization_id'], $context['organization_id']);

        $wrongPurpose = $this->captureDomainException(fn () => $tokens->verify($raw, 'finished_result_read'));
        $this->assertSame('INVALID_EXAM_ACCESS_TOKEN', $wrongPurpose->machineCode);

        [$locator, $secret] = explode('.', $raw, 2);
        $last = substr($secret, -1);
        $tampered = $locator.'.'.substr($secret, 0, -1).($last === 'A' ? 'B' : 'A');
        $wrongSecret = $this->captureDomainException(fn () => $tokens->verify($tampered, 'exam_execution'));
        $this->assertSame('INVALID_EXAM_ACCESS_TOKEN', $wrongSecret->machineCode);

        $atBoundary = $this->captureDomainException(fn () => $tokens->verify(
            $raw,
            'exam_execution',
            CarbonImmutable::parse((string) $tokenRow->expires_at),
        ));
        $this->assertSame('INVALID_EXAM_ACCESS_TOKEN', $atBoundary->machineCode);

        $auditText = DB::table('audit_logs')->get()->map(
            static fn (object $row): string => (string) ($row->before_redacted_json ?? '').(string) ($row->after_redacted_json ?? ''),
        )->implode('|');
        $outboxText = DB::table('outbox_messages')->pluck('payload')->implode('|');
        $this->assertStringNotContainsString($raw, $auditText);
        $this->assertStringNotContainsString($raw, $outboxText);
    }

    public function test_remote_send_rotates_secret_on_same_access_and_reservation_without_new_inventory_effect(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $fixtures = $this->examFixtures($actor, $course);
        $service = app(InternalExamService::class);
        $tokens = app(InternalExamTokenService::class);

        $attempt = $service->createAttempt($actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7());
        $access = $service->createAccess(
            $actor['session_id'],
            $attempt['id'],
            'remote_link',
            null,
            null,
            CarbonImmutable::now()->addMinutes(90),
            (string) Str::uuid7(),
        );
        $first = (string) $access['one_time_remote_token'];

        $sent = $service->sendRemoteAccess($actor['session_id'], $access['id'], (string) Str::uuid7());
        $second = (string) $sent['one_time_remote_token'];

        $this->assertNotSame($first, $second);
        $this->assertSame('delivered_or_assigned', $sent['status']);
        $this->assertSame(2, $sent['version']);
        $this->assertSame(2, DB::table('internal_exam_access_tokens')->where('internal_exam_access_id', $access['id'])->count());
        $this->assertSame(1, DB::table('internal_exam_access_tokens')->where('internal_exam_access_id', $access['id'])->whereNull('revoked_at')->count());
        $this->assertSame(1, DB::table('internal_exam_reservations')->where('internal_exam_attempt_id', $attempt['id'])->count());
        $this->assertSame('reserved', DB::table('internal_exam_inventory_entries')->where('id', $fixtures['inventory_id'])->value('current_state'));
        $this->assertSame(
            0,
            DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->where('event_type', 'unit_consumed')
                ->count(),
        );

        $old = $this->captureDomainException(fn () => $tokens->verify($first, 'exam_execution'));
        $this->assertSame('INVALID_EXAM_ACCESS_TOKEN', $old->machineCode);
        $this->assertSame($access['id'], $tokens->verify($second, 'exam_execution')['access_id']);

        $resent = $service->sendRemoteAccess($actor['session_id'], $access['id'], (string) Str::uuid7());
        $third = (string) $resent['one_time_remote_token'];
        $this->assertNotSame($second, $third);
        $this->assertSame(2, $resent['version']);
        $this->assertSame(3, DB::table('internal_exam_access_tokens')->where('internal_exam_access_id', $access['id'])->count());
        $this->assertSame(1, DB::table('internal_exam_access_tokens')->where('internal_exam_access_id', $access['id'])->whereNull('revoked_at')->count());
        $this->assertSame(1, DB::table('internal_exam_reservations')->where('internal_exam_attempt_id', $attempt['id'])->count());
    }

    public function test_remote_prestart_revoke_revokes_current_secret_and_releases_reserved_unit(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $fixtures = $this->examFixtures($actor, $course);
        $service = app(InternalExamService::class);
        $tokens = app(InternalExamTokenService::class);

        $attempt = $service->createAttempt($actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7());
        $access = $service->createAccess(
            $actor['session_id'],
            $attempt['id'],
            'remote_link',
            null,
            null,
            CarbonImmutable::now()->addMinutes(90),
            (string) Str::uuid7(),
        );
        $raw = (string) $access['one_time_remote_token'];

        $service->revokePrestart($actor['session_id'], $access['id'], 'candidate cancelled', (string) Str::uuid7());

        $invalid = $this->captureDomainException(fn () => $tokens->verify($raw, 'exam_execution'));
        $this->assertSame('INVALID_EXAM_ACCESS_TOKEN', $invalid->machineCode);
        $this->assertNotNull(DB::table('internal_exam_access_tokens')->where('internal_exam_access_id', $access['id'])->value('revoked_at'));
        $this->assertSame('prestart_revoked', DB::table('internal_exam_access_tokens')->where('internal_exam_access_id', $access['id'])->value('revoke_reason_code'));
        $this->assertSame('released', DB::table('internal_exam_reservations')->where('internal_exam_attempt_id', $attempt['id'])->value('status'));
        $this->assertSame('available', DB::table('internal_exam_inventory_entries')->where('id', $fixtures['inventory_id'])->value('current_state'));
    }

    public function test_remote_execution_token_starts_exact_access_once_and_remains_submit_capable_until_finish(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $fixtures = $this->examFixtures($actor, $course);
        $service = app(InternalExamService::class);
        $tokens = app(InternalExamTokenService::class);

        $attempt = $service->createAttempt($actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7());
        $access = $service->createAccess(
            $actor['session_id'],
            $attempt['id'],
            'remote_link',
            null,
            null,
            CarbonImmutable::now()->addMinutes(90),
            (string) Str::uuid7(),
        );
        $execution = (string) $access['one_time_remote_token'];

        $started = $service->startRemote($execution, $access['id'], (string) Str::uuid7());

        $this->assertSame('in_progress', $started['status']);
        $this->assertSame('started', DB::table('internal_exam_accesses')->where('id', $access['id'])->value('status'));
        $this->assertSame('consumed', DB::table('internal_exam_inventory_entries')->where('id', $fixtures['inventory_id'])->value('current_state'));
        $this->assertSame($attempt['id'], $tokens->verify($execution, 'exam_execution')['attempt_id']);
        $this->assertSame(
            1,
            DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->where('event_type', 'unit_consumed')
                ->count(),
        );

        $retry = $this->captureDomainException(fn () => $service->startRemote(
            $execution,
            $access['id'],
            (string) Str::uuid7(),
        ));
        $this->assertSame('RESOURCE_VERSION_CONFLICT', $retry->machineCode);
        $this->assertSame(
            1,
            DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_attempt_id', $attempt['id'])
                ->where('event_type', 'unit_consumed')
                ->count(),
        );

        $crossAccess = $this->captureDomainException(fn () => $service->startRemote(
            $execution,
            (string) Str::uuid7(),
            (string) Str::uuid7(),
        ));
        $this->assertSame('INVALID_EXAM_ACCESS_TOKEN', $crossAccess->machineCode);
    }

    public function test_remote_submit_retires_execution_token_mints_result_token_and_scopes_review_to_frozen_evidence(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $this->examFixtures($actor, $course);
        $service = app(InternalExamService::class);
        $tokens = app(InternalExamTokenService::class);

        $attempt = $service->createAttempt($actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7());
        $access = $service->createAccess(
            $actor['session_id'],
            $attempt['id'],
            'remote_link',
            null,
            null,
            CarbonImmutable::now()->addMinutes(90),
            (string) Str::uuid7(),
        );
        $execution = (string) $access['one_time_remote_token'];
        $service->startRemote($execution, $access['id'], (string) Str::uuid7());

        $result = $service->submitRemote(
            $execution,
            $attempt['id'],
            [
                ['ordinal' => 1, 'answer' => 'A'],
                ['ordinal' => 2, 'answer' => true],
            ],
            (string) Str::uuid7(),
        );

        $this->assertTrue($result['passed']);
        $this->assertSame(5, $result['score']);
        $resultToken = $result['one_time_result_token'];
        $this->assertIsString($resultToken);
        $this->assertNotSame('', $resultToken);

        $oldExecution = $this->captureDomainException(fn () => $tokens->verify($execution, 'exam_execution'));
        $this->assertSame('INVALID_EXAM_ACCESS_TOKEN', $oldExecution->machineCode);
        $this->assertSame($attempt['id'], $tokens->verify($resultToken, 'finished_result_read')['attempt_id']);

        $this->assertSame(
            0,
            DB::table('internal_exam_access_tokens')
                ->where('internal_exam_access_id', $access['id'])
                ->where('purpose', 'exam_execution')
                ->whereNull('revoked_at')
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('internal_exam_access_tokens')
                ->where('internal_exam_access_id', $access['id'])
                ->where('purpose', 'finished_result_read')
                ->whereNull('revoked_at')
                ->count(),
        );

        $review = $service->resultWithToken($resultToken, $attempt['id']);
        $questions = $service->questionsWithToken($resultToken, $attempt['id']);
        $this->assertTrue($review['passed']);
        $this->assertCount(2, $questions);
        $this->assertSame('A', $questions[0]['candidate_answer']);
        $this->assertTrue($questions[0]['is_correct']);
        $this->assertSame(true, $questions[1]['candidate_answer']);

        $resultCannotStart = $this->captureDomainException(fn () => $service->startRemote(
            $resultToken,
            $access['id'],
            (string) Str::uuid7(),
        ));
        $this->assertSame('INVALID_EXAM_ACCESS_TOKEN', $resultCannotStart->machineCode);

        $resultCannotSubmit = $this->captureDomainException(fn () => $service->submitRemote(
            $resultToken,
            $attempt['id'],
            [
                ['ordinal' => 1, 'answer' => 'A'],
                ['ordinal' => 2, 'answer' => true],
            ],
            (string) Str::uuid7(),
        ));
        $this->assertSame('INVALID_EXAM_ACCESS_TOKEN', $resultCannotSubmit->machineCode);

        $wrongAttempt = $this->captureDomainException(fn () => $service->resultWithToken(
            $resultToken,
            (string) Str::uuid7(),
        ));
        $this->assertSame('INVALID_EXAM_ACCESS_TOKEN', $wrongAttempt->machineCode);

        $auditText = DB::table('audit_logs')->get()->map(
            static fn (object $row): string => (string) ($row->before_redacted_json ?? '').(string) ($row->after_redacted_json ?? ''),
        )->implode('|');
        $outboxText = DB::table('outbox_messages')->pluck('payload')->implode('|');
        $this->assertStringNotContainsString($resultToken, $auditText);
        $this->assertStringNotContainsString($resultToken, $outboxText);
        $this->assertSame(
            'system',
            DB::table('audit_logs')
                ->where('action', 'internal_exam.submitted')
                ->where('entity_id', $attempt['id'])
                ->latest('created_at')
                ->value('actor_kind'),
        );
    }

    public function test_authorized_staff_submit_of_remote_attempt_preserves_remote_final_token_equivalence(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $this->examFixtures($actor, $course);
        $service = app(InternalExamService::class);
        $tokens = app(InternalExamTokenService::class);

        $attempt = $service->createAttempt($actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7());
        $access = $service->createAccess(
            $actor['session_id'],
            $attempt['id'],
            'remote_link',
            null,
            null,
            CarbonImmutable::now()->addMinutes(90),
            (string) Str::uuid7(),
        );
        $execution = (string) $access['one_time_remote_token'];
        $service->startRemote($execution, $access['id'], (string) Str::uuid7());

        $result = $service->submitAsStaff(
            $actor['session_id'],
            $attempt['id'],
            [
                ['ordinal' => 1, 'answer' => 'A'],
                ['ordinal' => 2, 'answer' => true],
            ],
            (string) Str::uuid7(),
        );

        $resultToken = $result['one_time_result_token'];
        $this->assertIsString($resultToken);
        $this->assertSame($attempt['id'], $tokens->verify($resultToken, 'finished_result_read')['attempt_id']);
        $this->assertSame(
            0,
            DB::table('internal_exam_access_tokens')
                ->where('internal_exam_access_id', $access['id'])
                ->where('purpose', 'exam_execution')
                ->whereNull('revoked_at')
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('internal_exam_access_tokens')
                ->where('internal_exam_access_id', $access['id'])
                ->where('purpose', 'finished_result_read')
                ->whereNull('revoked_at')
                ->count(),
        );
    }

    public function test_remote_technical_abort_revokes_execution_without_result_token_or_inventory_refund(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $fixtures = $this->examFixtures($actor, $course);
        $service = app(InternalExamService::class);
        $tokens = app(InternalExamTokenService::class);

        $attempt = $service->createAttempt($actor['session_id'], $course['id'], 'theory', 'pl', (string) Str::uuid7());
        $access = $service->createAccess(
            $actor['session_id'],
            $attempt['id'],
            'remote_link',
            null,
            null,
            CarbonImmutable::now()->addMinutes(90),
            (string) Str::uuid7(),
        );
        $execution = (string) $access['one_time_remote_token'];
        $service->startRemote($execution, $access['id'], (string) Str::uuid7());

        $aborted = $service->technicalAbort(
            $actor['session_id'],
            $attempt['id'],
            'remote client failure',
            (string) Str::uuid7(),
        );

        $this->assertSame('technical_abort', $aborted['status']);
        $invalid = $this->captureDomainException(fn () => $tokens->verify($execution, 'exam_execution'));
        $this->assertSame('INVALID_EXAM_ACCESS_TOKEN', $invalid->machineCode);
        $this->assertSame(
            0,
            DB::table('internal_exam_access_tokens')
                ->where('internal_exam_access_id', $access['id'])
                ->where('purpose', 'finished_result_read')
                ->whereNull('revoked_at')
                ->count(),
        );
        $this->assertSame('consumed', DB::table('internal_exam_inventory_entries')->where('id', $fixtures['inventory_id'])->value('current_state'));
        $this->assertSame('consumed', DB::table('internal_exam_reservations')->where('internal_exam_attempt_id', $attempt['id'])->value('status'));
        $this->assertDatabaseCount('internal_exam_results', 0);
    }

    public function test_http_remote_exam_secret_responses_are_nonreplayable_and_bearer_lifecycle_is_scoped(): void
    {
        $actor = $this->examActor();
        $course = $this->course($actor);
        $fixtures = $this->examFixtures($actor, $course);

        $attemptKey = (string) Str::uuid7();
        $attemptResponse = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $attemptKey)
            ->postJson("/api/v1/course-enrollments/{$course['id']}/internal-exam-attempts", [
                'exam_part' => 'theory',
                'language_code' => 'pl',
            ]);
        $attemptResponse->assertCreated();
        $attemptId = (string) $attemptResponse->json('id');
        $this->assertSame('created', $attemptResponse->json('status'));

        $accessKey = (string) Str::uuid7();
        $accessResponse = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $accessKey)
            ->postJson("/api/v1/internal-exam-attempts/{$attemptId}/accesses", [
                'mode' => 'remote_link',
            ]);
        $accessResponse->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $accessId = (string) $accessResponse->json('id');
        $firstUrl = (string) $accessResponse->json('one_time_remote_url');
        $this->assertStringStartsWith('https://learn.example.test/internal-exam#exam_access_token=', $firstUrl);
        $firstToken = $this->tokenFromOneTimeUrl($firstUrl);

        $accessReplay = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $accessKey)
            ->postJson("/api/v1/internal-exam-attempts/{$attemptId}/accesses", [
                'mode' => 'remote_link',
            ]);
        $accessReplay->assertCreated();
        $this->assertSame($accessId, $accessReplay->json('id'));
        $this->assertNull($accessReplay->json('one_time_remote_url'));
        $this->assertSame(1, DB::table('internal_exam_access_tokens')->where('internal_exam_access_id', $accessId)->count());

        $sendKey = (string) Str::uuid7();
        $sendResponse = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $sendKey)
            ->postJson("/api/v1/internal-exam-accesses/{$accessId}/send");
        $sendResponse->assertStatus(202)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $secondUrl = (string) $sendResponse->json('one_time_remote_url');
        $secondToken = $this->tokenFromOneTimeUrl($secondUrl);
        $this->assertNotSame($firstToken, $secondToken);

        $sendReplay = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', $sendKey)
            ->postJson("/api/v1/internal-exam-accesses/{$accessId}/send");
        $sendReplay->assertStatus(202);
        $this->assertNull($sendReplay->json('one_time_remote_url'));
        $this->assertSame(2, DB::table('internal_exam_access_tokens')->where('internal_exam_access_id', $accessId)->count());

        $oldToken = $this->captureDomainException(
            fn () => app(InternalExamTokenService::class)->verify($firstToken, 'exam_execution'),
        );
        $this->assertSame('INVALID_EXAM_ACCESS_TOKEN', $oldToken->machineCode);

        $staffStart = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/internal-exam-accesses/{$accessId}/start");
        $staffStart->assertStatus(409)->assertJsonPath('error.code', 'STATION_AUTH_REQUIRED');
        $this->assertSame('reserved', DB::table('internal_exam_inventory_entries')->where('id', $fixtures['inventory_id'])->value('current_state'));

        $startKey = (string) Str::uuid7();
        $startResponse = $this->withHeader('Authorization', 'Bearer '.$secondToken)
            ->withHeader('Idempotency-Key', $startKey)
            ->postJson("/api/v1/internal-exam-accesses/{$accessId}/start");
        $startResponse->assertOk();
        $this->assertSame('in_progress', $startResponse->json('status'));
        $this->assertSame('consumed', DB::table('internal_exam_inventory_entries')->where('id', $fixtures['inventory_id'])->value('current_state'));

        $startReplay = $this->withHeader('Authorization', 'Bearer '.$secondToken)
            ->withHeader('Idempotency-Key', $startKey)
            ->postJson("/api/v1/internal-exam-accesses/{$accessId}/start");
        $startReplay->assertOk();
        $this->assertSame('in_progress', $startReplay->json('status'));
        $this->assertSame(
            1,
            DB::table('internal_exam_inventory_ledger_entries')
                ->where('internal_exam_attempt_id', $attemptId)
                ->where('event_type', 'unit_consumed')
                ->count(),
        );

        $submitKey = (string) Str::uuid7();
        $answers = [
            ['ordinal' => 1, 'answer' => 'A'],
            ['ordinal' => 2, 'answer' => true],
        ];
        $submitResponse = $this->withHeader('Authorization', 'Bearer '.$secondToken)
            ->withHeader('Idempotency-Key', $submitKey)
            ->postJson("/api/v1/internal-exam-attempts/{$attemptId}/submit", ['answers' => $answers]);
        $submitResponse->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertTrue($submitResponse->json('passed'));
        $resultUrl = (string) $submitResponse->json('one_time_result_url');
        $resultToken = $this->tokenFromOneTimeUrl($resultUrl);

        $submitReplay = $this->withHeader('Authorization', 'Bearer '.$secondToken)
            ->withHeader('Idempotency-Key', $submitKey)
            ->postJson("/api/v1/internal-exam-attempts/{$attemptId}/submit", ['answers' => $answers]);
        $submitReplay->assertOk();
        $this->assertTrue($submitReplay->json('passed'));
        $this->assertNull($submitReplay->json('one_time_result_url'));
        $this->assertSame(
            1,
            DB::table('internal_exam_access_tokens')
                ->where('internal_exam_access_id', $accessId)
                ->where('purpose', 'finished_result_read')
                ->count(),
        );

        $resultResponse = $this->withHeader('Authorization', 'Bearer '.$resultToken)
            ->getJson("/api/v1/internal-exam-attempts/{$attemptId}/result");
        $resultResponse->assertOk();
        $this->assertTrue($resultResponse->json('passed'));

        $questionsResponse = $this->withHeader('Authorization', 'Bearer '.$resultToken)
            ->getJson("/api/v1/internal-exam-attempts/{$attemptId}/questions");
        $questionsResponse->assertOk()->assertJsonCount(2);
        $this->assertSame('A', $questionsResponse->json('0.candidate_answer'));

        $resultCannotStart = $this->withHeader('Authorization', 'Bearer '.$resultToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/internal-exam-accesses/{$accessId}/start");
        $resultCannotStart->assertStatus(401)->assertJsonPath('error.code', 'INVALID_EXAM_ACCESS_TOKEN');

        $invalidAuthorizationDoesNotFallBackToSession = $this->withSession(['auth_session_id' => $actor['session_id']])
            ->withHeader('Authorization', 'Basic not-an-exam-token')
            ->getJson("/api/v1/internal-exam-attempts/{$attemptId}/result");
        $invalidAuthorizationDoesNotFallBackToSession
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_EXAM_ACCESS_TOKEN');

        $safeSnapshots = DB::table('idempotency_records')
            ->where('organization_id', $actor['organization_id'])
            ->pluck('safe_response_snapshot')
            ->implode('|');
        $this->assertStringNotContainsString($firstToken, $safeSnapshots);
        $this->assertStringNotContainsString($secondToken, $safeSnapshots);
        $this->assertStringNotContainsString($resultToken, $safeSnapshots);
    }

    /** @return array{organization_id:string,user_id:string,membership_id:string,session_id:string} */
    private function examActor(): array
    {
        $actor = FoundationSchema::actor();
        foreach ([
            'students.view', 'students.create', 'students.edit',
            'courses.view', 'courses.create', 'courses.edit', 'course_requirements.correct',
            'exams.view', 'exams.generate', 'exams.access.send', 'exams.start.local', 'exams.results.view',
            'exams.documents.download', 'exams.inventory.adjust',
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
        $student = app(StudentService::class)->create($actor['session_id'], [
            'first_name' => 'Anna',
            'last_name' => 'Egzamin',
            'pesel' => '02070803628',
            'no_pesel' => false,
        ], (string) Str::uuid7());

        $instructor = app(StaffService::class)->create($actor['session_id'], [
            'email' => 'exam.'.str_replace('-', '', (string) Str::uuid7()).'@example.test',
            'first_name' => 'Jan',
            'last_name' => 'Instruktor',
            'staff_type_codes' => ['Instructor'],
            'category_ids' => [],
            'location_ids' => [],
        ], (string) Str::uuid7());

        return app(CourseEnrollmentService::class)->create($actor['session_id'], $student['id'], [
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
        ], (string) Str::uuid7());
    }

    /**
     * @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor
     * @param  array<string,mixed>  $course
     * @return array{inventory_id:string,capability_id:string,definition_id:string}
     */
    private function examFixtures(array $actor, array $course): array
    {
        $base = $this->capabilityAndDefinition($course);
        $inventoryId = (string) Str::uuid7();
        $now = now()->subMinute();
        DB::table('internal_exam_inventory_entries')->insert([
            'id' => $inventoryId,
            'organization_id' => $actor['organization_id'],
            'source_type' => 'free',
            'source_order_item_id' => null,
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

        return ['inventory_id' => $inventoryId, ...$base];
    }

    /**
     * @param  array<string,mixed>  $course
     * @return array{capability_id:string,definition_id:string}
     */
    private function capabilityAndDefinition(array $course): array
    {
        $categoryId = (string) DB::table('driving_categories')->where('code', $course['driving_category_code'])->value('id');
        $capabilityId = (string) Str::uuid7();
        $definitionId = (string) Str::uuid7();
        $now = now()->subMinute();

        DB::table('internal_exam_capabilities')->insert([
            'id' => $capabilityId,
            'driving_category_id' => $categoryId,
            'exam_part' => 'theory',
            'language_code' => 'pl',
            'enabled_at' => $now,
            'disabled_at' => null,
            'source_reference' => 'test-fixture',
            'created_at' => $now,
        ]);

        $composition = [
            'questions' => [
                [
                    'group' => 'basic',
                    'source_question_identifier' => 'fixture-q1',
                    'source_question_revision_identifier' => 'v1',
                    'question_snapshot_schema_version' => 1,
                    'question_snapshot' => [
                        'text' => 'Fixture question one',
                        'answer_type' => 'single',
                        'ordered_answer_options' => ['A', 'B'],
                        'correct_answer' => 'A',
                        'category_context' => 'B',
                    ],
                    'max_points' => 3,
                ],
                [
                    'group' => 'specialized',
                    'source_question_identifier' => 'fixture-q2',
                    'source_question_revision_identifier' => 'v1',
                    'question_snapshot_schema_version' => 1,
                    'question_snapshot' => [
                        'text' => 'Fixture question two',
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
            'definition_version' => 'fixture-v1',
            'definition_schema_version' => 1,
            'composition_snapshot' => json_encode($composition, JSON_THROW_ON_ERROR),
            'scoring_policy_snapshot' => json_encode($scoring, JSON_THROW_ON_ERROR),
            'definition_content_hash' => hash('sha256', json_encode([$composition, $scoring], JSON_THROW_ON_ERROR)),
            'published_at' => $now,
            'retired_at' => null,
            'created_at' => $now,
        ]);

        return ['capability_id' => $capabilityId, 'definition_id' => $definitionId];
    }

    /** @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor */
    private function station(array $actor): string
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
        $this->stationCredentialSecrets[$stationId] = (string) $issued['raw_credential'];

        return $stationId;
    }

    private function stationCredential(string $stationId): string
    {
        $credential = $this->stationCredentialSecrets[$stationId] ?? null;
        if (! is_string($credential) || $credential === '') {
            $this->fail('Expected provisioned station credential.');
        }

        return $credential;
    }

    /** @param  array{organization_id:string,user_id:string,membership_id:string,session_id:string}  $actor */
    private function grantInventory(array $actor): string
    {
        $inventoryId = (string) Str::uuid7();
        $now = now()->subMinute();
        DB::table('internal_exam_inventory_entries')->insert([
            'id' => $inventoryId,
            'organization_id' => $actor['organization_id'],
            'source_type' => 'free',
            'source_order_item_id' => null,
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

        return $inventoryId;
    }

    private function tokenFromOneTimeUrl(string $url): string
    {
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        if (! is_string($fragment) || $fragment === '') {
            $this->fail('Expected one-time URL fragment.');
        }

        parse_str($fragment, $parts);
        $token = $parts['exam_access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            $this->fail('Expected exam_access_token in one-time URL fragment.');
        }

        return $token;
    }

    /** @param  callable():mixed  $callback */
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
