<?php

namespace App\Modules\InternalExams;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\ResourcesCore\ResourceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @phpstan-type AttemptRow object{id:mixed,student_id:mixed,status:mixed,started_at:mixed,version:mixed,internal_exam_definition_id:mixed,candidate_snapshot:mixed,driving_category_id:mixed,exam_part:mixed,language_code:mixed,requirement_basis_snapshot:mixed,exam_definition_version_snapshot:mixed,exam_definition_hash_snapshot:mixed,evidence_schema_version:mixed,question_set_hash:mixed}
 * @phpstan-type AccessRow object{id:mixed,internal_exam_attempt_id:mixed,status:mixed,launch_mode:mixed,station_id:mixed,version:mixed,expires_at:mixed}
 * @phpstan-type ReservationRow object{id:mixed,internal_exam_inventory_entry_id:mixed,version:mixed}
 * @phpstan-type InventoryRow object{id:mixed,current_state:mixed}
 * @phpstan-type DefinitionRow object{id:mixed,driving_category_id:mixed,exam_part:mixed,language_code:mixed,engine_kind:mixed,definition_version:mixed,definition_content_hash:mixed,definition_schema_version:mixed,composition_snapshot:mixed,scoring_policy_snapshot:mixed}
 * @phpstan-type StationRow object{id:mixed,administrative_status:mixed,last_authenticated_heartbeat_at:mixed}
 * @phpstan-type StationSessionRow object{id:mixed,exam_station_id:mixed}
 * @phpstan-type QuestionRow object{id:mixed,ordinal:mixed,question_snapshot:mixed,max_points_snapshot:mixed,question_snapshot_hash:mixed}
 * @phpstan-type ReviewQuestionRow object{ordinal:mixed,group:mixed,question_snapshot:mixed,candidate_answer:mixed,is_correct:mixed,points_awarded:mixed,max_points_snapshot:mixed}
 * @phpstan-type LedgerRow object{event_type:mixed}
 */
final class InternalExamService
{
    private const PRESTART_ACCESS = ['draft', 'ready', 'delivered_or_assigned', 'opened'];

    private const CANDIDATE_SNAPSHOT_PATCH_FIELDS = [
        'first_name',
        'last_name',
        'birth_date',
        'contact_email',
        'no_pesel_declared',
    ];

    public function __construct(
        private readonly InternalExamScopeAuthorizer $scope,
        private readonly InternalExamTokenService $tokens,
        private readonly ExamStationCredentialService $stationCredentials,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /** @return list<array<string,mixed>> */
    public function attemptsForCourse(string $sessionId, string $courseId): array
    {
        $actor = $this->scope->requireCourse($sessionId, 'exams.view', $courseId);

        $ids = DB::table('internal_exam_attempts')
            ->where('organization_id', $actor['organization_id'])
            ->where('course_enrollment_id', $courseId)
            ->orderByDesc('course_attempt_sequence')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return array_values(array_map(
            fn (string $id): array => $this->presentAttempt($actor['organization_id'], $id),
            $ids,
        ));
    }

    /** @return array<string,mixed> */
    public function attemptGet(string $sessionId, string $attemptId): array
    {
        $actor = $this->scope->requireAttempt($sessionId, 'exams.view', $attemptId);

        return $this->presentAttempt($actor['organization_id'], $attemptId);
    }

    /** @param array<string,mixed> $attempt */
    public function etag(array $attempt): string
    {
        return '"v'.(int) $attempt['version'].'"';
    }

    /**
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    public function editCandidateSnapshot(
        string $sessionId,
        string $attemptId,
        array $patch,
        ?string $expectedTag,
    ): array {
        $actor = $this->scope->requireAttempt($sessionId, 'exams.generate', $attemptId);

        return DB::transaction(function () use ($actor, $attemptId, $patch, $expectedTag): array {
            /** @var AttemptRow|null $attempt */
            $attempt = DB::table('internal_exam_attempts')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $attemptId)
                ->lockForUpdate()
                ->first();
            if ($attempt === null) {
                throw ResourceDomainException::notFound();
            }

            $this->assertAttemptExpectedVersion($attempt, $expectedTag);
            if ((string) $attempt->status !== 'created' || $attempt->started_at !== null) {
                throw ResourceDomainException::conflict('Candidate snapshot can be edited only before internal exam start.');
            }

            $current = json_decode((string) $attempt->candidate_snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($current)) {
                throw ResourceDomainException::conflict('Internal exam candidate snapshot is invalid.');
            }
            if (($current['student_id'] ?? null) !== (string) $attempt->student_id) {
                throw ResourceDomainException::conflict('Internal exam candidate snapshot student binding is inconsistent.');
            }

            $next = $this->applyCandidateSnapshotPatch($current, $patch);
            if ($this->canonicalJson($next) === $this->canonicalJson($current)) {
                return $this->presentAttempt($actor['organization_id'], $attemptId);
            }

            $versionBefore = (int) $attempt->version;
            $versionAfter = $versionBefore + 1;
            $now = CarbonImmutable::now();

            $updated = DB::table('internal_exam_attempts')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $attemptId)
                ->where('version', $versionBefore)
                ->update([
                    'candidate_snapshot' => json_encode($next, JSON_THROW_ON_ERROR),
                    'version' => $versionAfter,
                ]);
            if ($updated !== 1) {
                throw ResourceDomainException::conflict('Internal exam Attempt changed since it was loaded.');
            }

            $this->appendAttemptEvent(
                $actor['organization_id'],
                $attemptId,
                'candidate_snapshot_updated',
                'created',
                'created',
                $versionBefore,
                $versionAfter,
                $actor['user_id'],
                null,
                $now,
            );

            return $this->presentAttempt($actor['organization_id'], $attemptId);
        });
    }

    /** @return array<string,mixed> */
    public function resultAsStaff(string $sessionId, string $attemptId): array
    {
        $actor = $this->scope->requireAttempt($sessionId, 'exams.results.view', $attemptId);
        $resultId = DB::table('internal_exam_results')
            ->where('organization_id', $actor['organization_id'])
            ->where('internal_exam_attempt_id', $attemptId)
            ->value('id');
        if (! is_string($resultId) || $resultId === '') {
            throw ResourceDomainException::notFound('Internal exam result not found.');
        }

        return $this->presentResult($actor['organization_id'], $resultId);
    }

    /** @return list<array<string,mixed>> */
    public function questionsAsStaff(string $sessionId, string $attemptId): array
    {
        $actor = $this->scope->requireAttempt($sessionId, 'exams.results.view', $attemptId);
        $rows = DB::table('internal_exam_attempt_questions')
            ->where('organization_id', $actor['organization_id'])
            ->where('internal_exam_attempt_id', $attemptId)
            ->orderBy('ordinal')
            ->get()
            ->map(static function (object $row): array {
                /** @var ReviewQuestionRow $row */
                return [
                    'ordinal' => (int) $row->ordinal,
                    'group' => (string) $row->group,
                    'question_snapshot' => json_decode((string) $row->question_snapshot, true, 512, JSON_THROW_ON_ERROR),
                    'candidate_answer' => $row->candidate_answer === null
                        ? null
                        : json_decode((string) $row->candidate_answer, true, 512, JSON_THROW_ON_ERROR),
                    'is_correct' => $row->is_correct === null ? null : (bool) $row->is_correct,
                    'points_awarded' => $row->points_awarded === null ? null : (int) $row->points_awarded,
                    'max_points' => (int) $row->max_points_snapshot,
                ];
            })
            ->values()
            ->all();

        return array_values($rows);
    }

    /** @return array<string,mixed> */
    public function createAttempt(
        string $sessionId,
        string $courseId,
        string $examPart,
        string $languageCode,
        string $requestId,
    ): array {
        if (! in_array($examPart, ['theory', 'practical'], true)) {
            throw ResourceDomainException::rule('Unsupported internal exam part.');
        }
        $languageCode = mb_strtolower(trim($languageCode));
        if ($languageCode === '') {
            throw ResourceDomainException::rule('Internal exam language is required.');
        }

        $actor = $this->scope->requireCourse($sessionId, 'exams.generate', $courseId);

        return DB::transaction(function () use ($actor, $courseId, $examPart, $languageCode, $requestId): array {
            $course = DB::table('course_enrollments')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $courseId)
                ->lockForUpdate()
                ->first();
            if ($course === null) {
                throw ResourceDomainException::notFound();
            }
            if ($course->cancelled_at !== null || $course->interrupted_at !== null) {
                throw ResourceDomainException::conflict('Internal exam requires an active CourseEnrollment.');
            }

            $student = DB::table('students')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $course->student_id)
                ->first();
            if ($student === null || $student->archived_at !== null) {
                throw ResourceDomainException::conflict('Internal exam requires an active Student.');
            }

            $profile = DB::table('training_requirement_profiles')
                ->where('organization_id', $actor['organization_id'])
                ->where('course_enrollment_id', $courseId)
                ->whereNull('superseded_at')
                ->first();
            if ($profile === null || (int) $profile->requirements_revision !== (int) $course->requirements_revision) {
                throw ResourceDomainException::conflict('Current training requirement profile is missing or stale.');
            }
            $required = $examPart === 'theory'
                ? (bool) $profile->internal_theory_exam_required
                : (bool) $profile->internal_practical_exam_required;
            if (! $required) {
                throw ResourceDomainException::rule('Selected internal exam part is not required for this course.');
            }

            $capability = DB::table('internal_exam_capabilities')
                ->where('driving_category_id', $course->driving_category_id)
                ->where('exam_part', $examPart)
                ->where('language_code', $languageCode)
                ->whereNull('disabled_at')
                ->lock('FOR SHARE')
                ->first();
            if ($capability === null) {
                throw ResourceDomainException::rule('Selected category, exam part and language are not currently supported.');
            }

            $inventory = $this->lockAvailableInventory($actor['organization_id']);
            $now = CarbonImmutable::now();
            $attemptId = (string) Str::uuid7();
            $reservationId = (string) Str::uuid7();
            $courseSequence = (int) DB::table('internal_exam_attempts')
                ->where('organization_id', $actor['organization_id'])
                ->where('course_enrollment_id', $courseId)
                ->max('course_attempt_sequence') + 1;

            $categoryCode = DB::table('driving_categories')->where('id', $course->driving_category_id)->value('code');
            if (! is_string($categoryCode) || $categoryCode === '') {
                throw ResourceDomainException::conflict('Course driving category is unavailable.');
            }
            $profileInput = json_decode((string) $profile->input_snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($profileInput)) {
                throw ResourceDomainException::conflict('Training requirement input snapshot is invalid.');
            }
            $requirementBasis = [
                'training_requirement_profile_id' => (string) $profile->id,
                'requirements_revision' => (int) $profile->requirements_revision,
                'rule_set_version' => (string) $profile->rule_set_version,
                'rule_set_content_hash' => $profileInput['rule_set_content_hash'] ?? null,
                'exam_part' => $examPart,
                'required_profile_flag_name' => $examPart === 'theory' ? 'internal_theory_exam_required' : 'internal_practical_exam_required',
                'required_profile_flag_value_true' => true,
                'exemption_basis_code' => $profile->exemption_basis_code,
                'driving_category_id' => (string) $course->driving_category_id,
                'driving_category_code' => $categoryCode,
                'training_type' => (string) $course->training_type,
                'internal_exam_capability_id' => (string) $capability->id,
                'language_code' => $languageCode,
                'basis_evaluated_at' => $now->toIso8601String(),
            ];
            $candidateSnapshot = [
                'student_id' => (string) $student->id,
                'first_name' => (string) $student->first_name,
                'last_name' => (string) $student->last_name,
                'birth_date' => $student->birth_date === null ? null : (string) $student->birth_date,
                'contact_email' => $student->contact_email_normalized === null ? null : (string) $student->contact_email_normalized,
                'no_pesel_declared' => (bool) $student->no_pesel_declared,
            ];

            DB::table('internal_exam_attempts')->insert([
                'id' => $attemptId,
                'organization_id' => $actor['organization_id'],
                'student_id' => (string) $course->student_id,
                'course_enrollment_id' => $courseId,
                'exam_part' => $examPart,
                'driving_category_id' => (string) $course->driving_category_id,
                'language_code' => $languageCode,
                'candidate_snapshot' => json_encode($candidateSnapshot, JSON_THROW_ON_ERROR),
                'training_requirement_profile_id' => (string) $profile->id,
                'requirements_revision' => (int) $profile->requirements_revision,
                'internal_exam_capability_id' => (string) $capability->id,
                'requirement_basis_snapshot' => json_encode($requirementBasis, JSON_THROW_ON_ERROR),
                'course_attempt_sequence' => $courseSequence,
                'status' => 'created',
                'version' => 1,
                'started_at' => null,
                'finished_at' => null,
                'technical_aborted_at' => null,
                'invalidated_at' => null,
                'internal_exam_definition_id' => null,
                'exam_definition_version_snapshot' => null,
                'exam_definition_hash_snapshot' => null,
                'evidence_schema_version' => null,
                'question_set_hash' => null,
                'created_at' => $now,
            ]);
            $this->appendAttemptEvent(
                $actor['organization_id'], $attemptId, 'created', null, 'created', null, 1,
                $actor['user_id'], null, $now,
            );

            DB::table('internal_exam_reservations')->insert([
                'id' => $reservationId,
                'organization_id' => $actor['organization_id'],
                'internal_exam_inventory_entry_id' => (string) $inventory->id,
                'internal_exam_attempt_id' => $attemptId,
                'status' => 'reserved',
                'version' => 1,
                'reserved_at' => $now,
                'released_at' => null,
                'consumed_at' => null,
                'created_at' => $now,
            ]);
            $this->appendInventoryLedger(
                $actor['organization_id'],
                (string) $inventory->id,
                $reservationId,
                $attemptId,
                null,
                'unit_reserved',
                -1,
                $actor['user_id'],
                null,
                $now,
            );
            DB::table('internal_exam_inventory_entries')
                ->where('id', $inventory->id)
                ->update(['current_state' => 'reserved']);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'internal_exam.attempt.created', 'internal_exam_attempt', $attemptId, $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['course', 'part', 'language', 'reservation'], 'state' => 'created'],
            );

            return $this->presentAttempt($actor['organization_id'], $attemptId);
        });
    }

    /** @return array<string,mixed> */
    public function createAccess(
        string $sessionId,
        string $attemptId,
        string $mode,
        ?string $requestedStationId,
        ?string $trustedStationId,
        ?CarbonImmutable $expiresAt,
        string $requestId,
    ): array {
        $actor = $this->scope->requireAttempt($sessionId, 'exams.generate', $attemptId);

        return DB::transaction(function () use (
            $actor, $attemptId, $mode, $requestedStationId, $trustedStationId, $expiresAt, $requestId,
        ): array {
            $attempt = $this->lockAttempt($actor['organization_id'], $attemptId);
            if ((string) $attempt->status !== 'created' || $attempt->started_at !== null) {
                throw ResourceDomainException::conflict('Access can be created only for an unstarted attempt.');
            }
            if (DB::table('internal_exam_accesses')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->whereIn('status', [...self::PRESTART_ACCESS, 'started'])
                ->exists()) {
                throw ResourceDomainException::conflict('Attempt already has a nonterminal access.');
            }
            $reservation = DB::table('internal_exam_reservations')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->first();
            if ($reservation === null) {
                throw ResourceDomainException::conflict('Attempt has no active reserved inventory unit.');
            }

            $stationId = null;
            $now = CarbonImmutable::now();
            if ($mode === 'remote_link') {
                if ($requestedStationId !== null || $trustedStationId !== null || $expiresAt === null || ! $now->lt($expiresAt)) {
                    throw ResourceDomainException::rule('Remote access requires a future expiry and no station binding.');
                }
            } elseif ($mode === 'local_current_workstation') {
                if ($requestedStationId !== null || $trustedStationId === null) {
                    throw ResourceDomainException::rule('Current-workstation access requires a trusted server-resolved station.');
                }
                $stationId = $trustedStationId;
                $expiresAt = null;
                $this->requireStationExists($actor['organization_id'], $stationId);
            } elseif ($mode === 'assigned_exam_station') {
                if ($requestedStationId === null || $trustedStationId !== null) {
                    throw ResourceDomainException::rule('Assigned-station access requires exactly one selected station.');
                }
                $stationId = $requestedStationId;
                $expiresAt = null;
                $this->requireStationExists($actor['organization_id'], $stationId);
            } else {
                throw ResourceDomainException::rule('Unsupported internal exam launch mode.');
            }

            $id = (string) Str::uuid7();
            $status = $mode === 'remote_link' ? 'ready' : 'delivered_or_assigned';
            DB::table('internal_exam_accesses')->insert([
                'id' => $id,
                'organization_id' => $actor['organization_id'],
                'internal_exam_attempt_id' => $attemptId,
                'launch_mode' => $mode,
                'station_id' => $stationId,
                'status' => $status,
                'version' => 1,
                'expires_at' => $expiresAt,
                'ready_at' => $now,
                'delivered_or_assigned_at' => $mode === 'remote_link' ? null : $now,
                'opened_at' => null,
                'started_at' => null,
                'completed_at' => null,
                'cancelled_at' => null,
                'expired_at' => null,
                'revoked_at' => null,
                'technical_aborted_at' => null,
                'invalidated_at' => null,
                'created_at' => $now,
            ]);
            $this->appendAccessEvent(
                $actor['organization_id'], $id, $attemptId, 'created', null, $status, null, 1,
                $actor['user_id'], null, $now,
            );

            $oneTimeToken = null;
            if ($mode === 'remote_link') {
                $issued = $this->tokens->issueExecution(
                    $actor['organization_id'],
                    $id,
                    $attemptId,
                    $actor['user_id'],
                    $now,
                    $expiresAt,
                );
                $oneTimeToken = $issued['raw_token'];
            }

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'internal_exam.access.created', 'internal_exam_access', $id, $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['attempt', 'mode', 'station'], 'state' => $status],
            );

            $presented = $this->presentAccess($actor['organization_id'], $id);
            $presented['one_time_remote_token'] = $oneTimeToken;

            return $presented;
        });
    }

    /** @return array<string,mixed> */
    public function sendRemoteAccess(
        string $sessionId,
        string $accessId,
        string $requestId,
    ): array {
        $actor = $this->scope->requireAccess($sessionId, 'exams.access.send', $accessId);

        return DB::transaction(function () use ($actor, $accessId, $requestId): array {
            /** @var AccessRow|null $accessSnapshot */
            $accessSnapshot = DB::table('internal_exam_accesses')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $accessId)
                ->first();
            if ($accessSnapshot === null) {
                throw ResourceDomainException::notFound();
            }

            $attempt = $this->lockAttempt($actor['organization_id'], (string) $accessSnapshot->internal_exam_attempt_id);
            /** @var AccessRow $access */
            $access = DB::table('internal_exam_accesses')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $accessId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $attempt->status !== 'created'
                || (string) $access->launch_mode !== 'remote_link'
                || ! in_array((string) $access->status, ['ready', 'delivered_or_assigned', 'opened'], true)) {
                throw ResourceDomainException::conflict('Remote access is not eligible for send or resend.');
            }

            $expiresAtValue = DB::table('internal_exam_accesses')->where('id', $accessId)->value('expires_at');
            if (! is_string($expiresAtValue)) {
                throw ResourceDomainException::conflict('Remote access expiry is missing.');
            }
            $now = CarbonImmutable::now();
            $accessExpiresAt = CarbonImmutable::parse($expiresAtValue);
            if (! $now->lt($accessExpiresAt)) {
                throw ResourceDomainException::conflict('Remote access has expired.');
            }

            $issued = $this->tokens->rotateExecution(
                $actor['organization_id'],
                $accessId,
                (string) $attempt->id,
                $actor['user_id'],
                $now,
                $accessExpiresAt,
                'rotated_for_send',
            );

            $afterStatus = (string) $access->status;
            if ((string) $access->status === 'ready') {
                $afterStatus = 'delivered_or_assigned';
                $versionAfter = (int) $access->version + 1;
                DB::table('internal_exam_accesses')->where('id', $accessId)->update([
                    'status' => $afterStatus,
                    'version' => $versionAfter,
                    'delivered_or_assigned_at' => $now,
                ]);
                $this->appendAccessEvent(
                    $actor['organization_id'], $accessId, (string) $attempt->id, 'delivered',
                    'ready', $afterStatus, (int) $access->version, $versionAfter,
                    $actor['user_id'], null, $now,
                );
            }

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'internal_exam.access.sent', 'internal_exam_access', $accessId, $requestId,
                ['fields' => ['status'], 'state' => (string) $access->status],
                ['fields' => ['status', 'delivery'], 'state' => $afterStatus],
            );

            $presented = $this->presentAccess($actor['organization_id'], $accessId);
            $presented['one_time_remote_token'] = $issued['raw_token'];

            return $presented;
        });
    }

    /** @return array<string,mixed> */
    public function revokePrestart(
        string $sessionId,
        string $accessId,
        string $reason,
        string $requestId,
    ): array {
        if (trim($reason) === '') {
            throw ResourceDomainException::rule('Revocation reason is required.');
        }
        $actor = $this->scope->requireAccess($sessionId, 'exams.generate', $accessId);

        return DB::transaction(function () use ($actor, $accessId, $reason, $requestId): array {
            /** @var AccessRow|null $accessSnapshot */
            $accessSnapshot = DB::table('internal_exam_accesses')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $accessId)
                ->first();
            if ($accessSnapshot === null) {
                throw ResourceDomainException::notFound();
            }
            $attempt = $this->lockAttempt($actor['organization_id'], (string) $accessSnapshot->internal_exam_attempt_id);
            /** @var AccessRow $access */
            $access = DB::table('internal_exam_accesses')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $accessId)
                ->lockForUpdate()
                ->firstOrFail();
            if ((string) $attempt->status !== 'created' || ! in_array((string) $access->status, self::PRESTART_ACCESS, true)) {
                throw ResourceDomainException::conflict('Only a pre-start access can be revoked.');
            }

            /** @var ReservationRow|null $reservation */
            $reservation = DB::table('internal_exam_reservations')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attempt->id)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->first();
            if ($reservation === null) {
                throw ResourceDomainException::conflict('Reserved inventory is missing for pre-start revocation.');
            }
            /** @var InventoryRow|null $inventory */
            $inventory = DB::table('internal_exam_inventory_entries')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $reservation->internal_exam_inventory_entry_id)
                ->lockForUpdate()
                ->first();
            if ($inventory === null || (string) $inventory->current_state !== 'reserved') {
                throw ResourceDomainException::conflict('Reserved inventory state is inconsistent.');
            }

            $now = CarbonImmutable::now();
            $this->tokens->revokeExecution(
                $actor['organization_id'],
                $accessId,
                'prestart_revoked',
                $now,
            );
            $accessVersion = (int) $access->version + 1;
            DB::table('internal_exam_accesses')->where('id', $accessId)->update([
                'status' => 'revoked',
                'version' => $accessVersion,
                'revoked_at' => $now,
            ]);
            $this->appendAccessEvent(
                $actor['organization_id'], $accessId, (string) $attempt->id, 'revoked',
                (string) $access->status, 'revoked', (int) $access->version, $accessVersion,
                $actor['user_id'], $reason, $now,
            );

            DB::table('internal_exam_reservations')->where('id', $reservation->id)->update([
                'status' => 'released',
                'version' => (int) $reservation->version + 1,
                'released_at' => $now,
            ]);
            $this->appendInventoryLedger(
                $actor['organization_id'],
                (string) $inventory->id,
                (string) $reservation->id,
                (string) $attempt->id,
                null,
                'unit_released',
                1,
                $actor['user_id'],
                $reason,
                $now,
            );
            DB::table('internal_exam_inventory_entries')->where('id', $inventory->id)->update(['current_state' => 'available']);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'internal_exam.access.revoked', 'internal_exam_access', $accessId, $requestId,
                ['fields' => ['status'], 'state' => (string) $access->status],
                ['fields' => ['status', 'reservation'], 'state' => 'revoked'],
                $reason,
            );

            return $this->presentAccess($actor['organization_id'], $accessId);
        });
    }

    /** @return array<string,mixed> */
    public function startLocal(
        string $sessionId,
        string $accessId,
        string $rawStationCredential,
        string $requestId,
    ): array {
        $actor = $this->scope->requireAccess($sessionId, 'exams.start.local', $accessId);

        return DB::transaction(function () use ($actor, $accessId, $rawStationCredential, $requestId): array {
            /** @var AccessRow|null $accessSnapshot */
            $accessSnapshot = DB::table('internal_exam_accesses')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $accessId)
                ->first();
            if ($accessSnapshot === null) {
                throw ResourceDomainException::notFound();
            }
            $attempt = $this->lockAttempt($actor['organization_id'], (string) $accessSnapshot->internal_exam_attempt_id);
            /** @var AccessRow $access */
            $access = DB::table('internal_exam_accesses')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $accessId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $attempt->status !== 'created' || ! in_array((string) $access->status, ['ready', 'delivered_or_assigned'], true)) {
                throw ResourceDomainException::conflict('Attempt and access are not startable.');
            }
            if ((string) $access->launch_mode === 'remote_link') {
                throw ResourceDomainException::conflict('Remote access must start through the execution-token boundary.');
            }
            if ($access->station_id === null) {
                throw ResourceDomainException::conflict('Station-bound access is missing its station binding.');
            }

            $trustedStationId = (string) $access->station_id;
            $now = CarbonImmutable::now();
            $stationContext = $this->stationCredentials->authenticateForStation(
                $rawStationCredential,
                $actor['organization_id'],
                $trustedStationId,
                $now,
            );
            if ($stationContext['station_id'] !== $trustedStationId) {
                throw new ResourceDomainException(
                    'INVALID_EXAM_STATION_CREDENTIAL',
                    401,
                    'Invalid or revoked exam station credential.',
                );
            }

            $definition = $this->lockCurrentDefinition($attempt);

            if (DB::table('internal_exam_station_sessions')
                ->where('organization_id', $actor['organization_id'])
                ->where('exam_station_id', $trustedStationId)
                ->whereNull('ended_at')
                ->exists()) {
                throw ResourceDomainException::conflict('Exam station is already occupied.');
            }
            if (DB::table('internal_exam_station_sessions')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attempt->id)
                ->whereNull('ended_at')
                ->exists()) {
                throw ResourceDomainException::conflict('Attempt already has an active station session.');
            }

            /** @var ReservationRow|null $reservation */
            $reservation = DB::table('internal_exam_reservations')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attempt->id)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->first();
            if ($reservation === null) {
                throw ResourceDomainException::conflict('Start requires exactly one reserved inventory unit.');
            }
            /** @var InventoryRow|null $inventory */
            $inventory = DB::table('internal_exam_inventory_entries')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $reservation->internal_exam_inventory_entry_id)
                ->lockForUpdate()
                ->first();
            if ($inventory === null || (string) $inventory->current_state !== 'reserved') {
                throw ResourceDomainException::conflict('Reserved inventory state is inconsistent.');
            }

            $questionSetHash = $this->materializeDefinitionEvidence($actor['organization_id'], $attempt, $definition);
            $attemptVersion = (int) $attempt->version + 1;
            $accessVersion = (int) $access->version + 1;

            DB::table('internal_exam_attempts')->where('id', $attempt->id)->update([
                'status' => 'in_progress',
                'version' => $attemptVersion,
                'started_at' => $now,
                'internal_exam_definition_id' => (string) $definition->id,
                'exam_definition_version_snapshot' => (string) $definition->definition_version,
                'exam_definition_hash_snapshot' => (string) $definition->definition_content_hash,
                'evidence_schema_version' => (int) $definition->definition_schema_version,
                'question_set_hash' => $questionSetHash,
            ]);
            $this->appendAttemptEvent(
                $actor['organization_id'], (string) $attempt->id, 'started',
                'created', 'in_progress', (int) $attempt->version, $attemptVersion,
                $actor['user_id'], null, $now,
            );

            DB::table('internal_exam_accesses')->where('id', $accessId)->update([
                'status' => 'started',
                'version' => $accessVersion,
                'started_at' => $now,
            ]);
            $this->appendAccessEvent(
                $actor['organization_id'], $accessId, (string) $attempt->id, 'started',
                (string) $access->status, 'started', (int) $access->version, $accessVersion,
                $actor['user_id'], null, $now,
            );

            DB::table('internal_exam_reservations')->where('id', $reservation->id)->update([
                'status' => 'consumed',
                'version' => (int) $reservation->version + 1,
                'consumed_at' => $now,
            ]);
            $this->appendInventoryLedger(
                $actor['organization_id'],
                (string) $inventory->id,
                (string) $reservation->id,
                (string) $attempt->id,
                null,
                'unit_consumed',
                0,
                $actor['user_id'],
                null,
                $now,
            );
            DB::table('internal_exam_inventory_entries')->where('id', $inventory->id)->update(['current_state' => 'consumed']);

            $sequence = (int) DB::table('internal_exam_station_sessions')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attempt->id)
                ->max('session_sequence') + 1;
            DB::table('internal_exam_station_sessions')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $actor['organization_id'],
                'internal_exam_attempt_id' => (string) $attempt->id,
                'internal_exam_access_id' => $accessId,
                'exam_station_id' => $trustedStationId,
                'session_sequence' => $sequence,
                'transferred_from_session_id' => null,
                'started_at' => $now,
                'ended_at' => null,
                'end_reason' => null,
                'created_by_user_id' => $actor['user_id'],
                'created_at' => $now,
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'internal_exam.started', 'internal_exam_attempt', (string) $attempt->id, $requestId,
                ['fields' => ['status'], 'state' => 'created'],
                ['fields' => ['status', 'definition', 'inventory', 'station'], 'state' => 'in_progress'],
            );

            return $this->presentAttempt($actor['organization_id'], (string) $attempt->id);
        });
    }

    /** @return array<string,mixed> */
    public function startRemote(
        string $rawToken,
        string $accessId,
        string $requestId,
    ): array {
        $context = $this->tokens->verify($rawToken, 'exam_execution');
        if ($context['access_id'] !== $accessId) {
            throw $this->invalidExamToken();
        }

        return DB::transaction(function () use ($rawToken, $context, $accessId, $requestId): array {
            $attempt = $this->lockAttempt($context['organization_id'], $context['attempt_id']);

            /** @var AccessRow|null $access */
            $access = DB::table('internal_exam_accesses')
                ->where('organization_id', $context['organization_id'])
                ->where('id', $accessId)
                ->where('internal_exam_attempt_id', $context['attempt_id'])
                ->lockForUpdate()
                ->first();
            if ($access === null) {
                throw $this->invalidExamToken();
            }

            $lockedContext = $this->tokens->verifyForUpdate($rawToken, 'exam_execution');
            if ($lockedContext['organization_id'] !== $context['organization_id']
                || $lockedContext['access_id'] !== $accessId
                || $lockedContext['attempt_id'] !== (string) $attempt->id) {
                throw $this->invalidExamToken();
            }

            if ((string) $access->launch_mode !== 'remote_link'
                || (string) $attempt->status !== 'created'
                || ! in_array((string) $access->status, ['ready', 'delivered_or_assigned', 'opened'], true)) {
                throw ResourceDomainException::conflict('Remote attempt and access are not startable.');
            }

            $now = CarbonImmutable::now();
            if ($access->expires_at === null || ! $now->lt(CarbonImmutable::parse((string) $access->expires_at))) {
                throw $this->invalidExamToken();
            }

            $definition = $this->lockCurrentDefinition($attempt);

            /** @var ReservationRow|null $reservation */
            $reservation = DB::table('internal_exam_reservations')
                ->where('organization_id', $context['organization_id'])
                ->where('internal_exam_attempt_id', $attempt->id)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->first();
            if ($reservation === null) {
                throw ResourceDomainException::conflict('Start requires exactly one reserved inventory unit.');
            }

            /** @var InventoryRow|null $inventory */
            $inventory = DB::table('internal_exam_inventory_entries')
                ->where('organization_id', $context['organization_id'])
                ->where('id', $reservation->internal_exam_inventory_entry_id)
                ->lockForUpdate()
                ->first();
            if ($inventory === null || (string) $inventory->current_state !== 'reserved') {
                throw ResourceDomainException::conflict('Reserved inventory state is inconsistent.');
            }

            $questionSetHash = $this->materializeDefinitionEvidence($context['organization_id'], $attempt, $definition);
            $attemptVersion = (int) $attempt->version + 1;
            $accessVersion = (int) $access->version + 1;

            DB::table('internal_exam_attempts')->where('id', $attempt->id)->update([
                'status' => 'in_progress',
                'version' => $attemptVersion,
                'started_at' => $now,
                'internal_exam_definition_id' => (string) $definition->id,
                'exam_definition_version_snapshot' => (string) $definition->definition_version,
                'exam_definition_hash_snapshot' => (string) $definition->definition_content_hash,
                'evidence_schema_version' => (int) $definition->definition_schema_version,
                'question_set_hash' => $questionSetHash,
            ]);
            $this->appendAttemptEvent(
                $context['organization_id'], (string) $attempt->id, 'started',
                'created', 'in_progress', (int) $attempt->version, $attemptVersion,
                null, null, $now,
            );

            DB::table('internal_exam_accesses')->where('id', $accessId)->update([
                'status' => 'started',
                'version' => $accessVersion,
                'started_at' => $now,
            ]);
            $this->appendAccessEvent(
                $context['organization_id'], $accessId, (string) $attempt->id, 'started',
                (string) $access->status, 'started', (int) $access->version, $accessVersion,
                null, null, $now,
            );

            DB::table('internal_exam_reservations')->where('id', $reservation->id)->update([
                'status' => 'consumed',
                'version' => (int) $reservation->version + 1,
                'consumed_at' => $now,
            ]);
            $this->appendInventoryLedger(
                $context['organization_id'],
                (string) $inventory->id,
                (string) $reservation->id,
                (string) $attempt->id,
                null,
                'unit_consumed',
                0,
                null,
                null,
                $now,
            );
            DB::table('internal_exam_inventory_entries')
                ->where('id', $inventory->id)
                ->update(['current_state' => 'consumed']);

            $this->auditOutbox->recordOrganizationSystemEvent(
                $context['organization_id'],
                'internal_exam.started',
                'internal_exam_attempt',
                (string) $attempt->id,
                $requestId,
                ['fields' => ['status'], 'state' => 'created'],
                ['fields' => ['status', 'definition', 'inventory'], 'state' => 'in_progress'],
            );

            return $this->presentAttempt($context['organization_id'], (string) $attempt->id);
        });
    }

    /**
     * @param  list<array<string,mixed>>  $answers
     * @return array<string,mixed>
     */
    public function submitAsStaff(
        string $sessionId,
        string $attemptId,
        array $answers,
        string $requestId,
    ): array {
        $actor = $this->scope->requireAttempt($sessionId, 'exams.generate', $attemptId);

        return DB::transaction(function () use ($actor, $attemptId, $answers, $requestId): array {
            $attempt = $this->lockAttempt($actor['organization_id'], $attemptId);
            if ((string) $attempt->status !== 'in_progress' || $attempt->started_at === null) {
                throw ResourceDomainException::conflict('Only an in-progress attempt can be submitted.');
            }
            /** @var AccessRow|null $access */
            $access = DB::table('internal_exam_accesses')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->where('status', 'started')
                ->lockForUpdate()
                ->first();
            if ($access === null) {
                throw ResourceDomainException::conflict('Started access is missing.');
            }
            $reservation = DB::table('internal_exam_reservations')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->where('status', 'consumed')
                ->lockForUpdate()
                ->first();
            if ($reservation === null) {
                throw ResourceDomainException::conflict('Submit requires previously consumed inventory.');
            }
            if (DB::table('internal_exam_results')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->exists()) {
                throw ResourceDomainException::conflict('Attempt already has an immutable result.');
            }

            /** @var DefinitionRow|null $definition */
            $definition = DB::table('internal_exam_definitions')->where('id', $attempt->internal_exam_definition_id)->first();
            if ($definition === null || (string) $definition->engine_kind !== 'question_test') {
                throw ResourceDomainException::conflict('This submit path currently requires a frozen question-test definition.');
            }
            $scoring = json_decode((string) $definition->scoring_policy_snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($scoring)
                || ($scoring['question_scoring'] ?? null) !== 'all_or_nothing'
                || ($scoring['pass_rule'] ?? null) !== 'minimum_score'
                || ! isset($scoring['pass_threshold'])
                || ! is_int($scoring['pass_threshold'])) {
                throw ResourceDomainException::conflict('Frozen scoring policy is unsupported or incomplete.');
            }
            $threshold = (int) $scoring['pass_threshold'];

            $answerMap = [];
            foreach ($answers as $answer) {
                if (! isset($answer['ordinal']) || ! is_int($answer['ordinal']) || ! array_key_exists('answer', $answer)) {
                    throw ResourceDomainException::rule('Every submitted answer requires integer ordinal and answer.');
                }
                $ordinal = (int) $answer['ordinal'];
                if ($ordinal < 1 || array_key_exists($ordinal, $answerMap)) {
                    throw ResourceDomainException::rule('Answer ordinals must be unique positive integers.');
                }
                $answerMap[$ordinal] = $answer['answer'];
            }

            $questions = DB::table('internal_exam_attempt_questions')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->orderBy('ordinal')
                ->lockForUpdate()
                ->get();
            if ($questions->count() === 0 || count($answerMap) !== $questions->count()) {
                throw ResourceDomainException::rule('Submitted answers must exactly cover the frozen question set.');
            }

            $score = 0;
            $maxScore = 0;
            $finalEvidence = [];
            $now = CarbonImmutable::now();
            foreach ($questions as $question) {
                /** @var QuestionRow $question */
                $ordinal = (int) $question->ordinal;
                if (! array_key_exists($ordinal, $answerMap)) {
                    throw ResourceDomainException::rule('Submitted answers must exactly cover the frozen question set.');
                }
                $snapshot = json_decode((string) $question->question_snapshot, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($snapshot) || ! array_key_exists('correct_answer', $snapshot)) {
                    throw ResourceDomainException::conflict('Frozen question evaluation key is missing.');
                }
                $candidate = $answerMap[$ordinal];
                $correct = $this->canonicalJson($candidate) === $this->canonicalJson($snapshot['correct_answer']);
                $points = $correct ? (int) $question->max_points_snapshot : 0;
                $score += $points;
                $maxScore += (int) $question->max_points_snapshot;
                DB::table('internal_exam_attempt_questions')->where('id', $question->id)->update([
                    'candidate_answer' => json_encode($candidate, JSON_THROW_ON_ERROR),
                    'is_correct' => $correct,
                    'points_awarded' => $points,
                    'answered_at' => $now,
                ]);
                $finalEvidence[] = [
                    'ordinal' => $ordinal,
                    'question_snapshot_hash' => (string) $question->question_snapshot_hash,
                    'candidate_answer' => $candidate,
                    'is_correct' => $correct,
                    'points_awarded' => $points,
                    'max_points' => (int) $question->max_points_snapshot,
                ];
            }
            if ($threshold < 0 || $threshold > $maxScore) {
                throw ResourceDomainException::conflict('Frozen numeric pass threshold is outside the frozen score range.');
            }
            $passed = $score >= $threshold;
            $status = $passed ? 'passed' : 'failed';
            $resultSnapshot = [
                'engine_kind' => 'question_test',
                'score' => $score,
                'max_score' => $maxScore,
                'pass_threshold' => $threshold,
                'passed' => $passed,
                'questions' => $finalEvidence,
            ];
            $evidenceBundle = [
                'attempt_id' => $attemptId,
                'organization_id' => $actor['organization_id'],
                'candidate_snapshot' => json_decode((string) $attempt->candidate_snapshot, true, 512, JSON_THROW_ON_ERROR),
                'driving_category_id' => (string) $attempt->driving_category_id,
                'exam_part' => (string) $attempt->exam_part,
                'language_code' => (string) $attempt->language_code,
                'requirement_basis_snapshot' => json_decode((string) $attempt->requirement_basis_snapshot, true, 512, JSON_THROW_ON_ERROR),
                'internal_exam_definition_id' => (string) $attempt->internal_exam_definition_id,
                'exam_definition_version_snapshot' => (string) $attempt->exam_definition_version_snapshot,
                'exam_definition_hash_snapshot' => (string) $attempt->exam_definition_hash_snapshot,
                'questions' => $finalEvidence,
                'scoring_policy_snapshot' => $scoring,
                'score' => $score,
                'max_score' => $maxScore,
                'passed' => $passed,
                'finished_at' => $now->toIso8601String(),
            ];
            $resultId = (string) Str::uuid7();
            DB::table('internal_exam_results')->insert([
                'id' => $resultId,
                'organization_id' => $actor['organization_id'],
                'internal_exam_attempt_id' => $attemptId,
                'evidence_schema_version' => (int) $attempt->evidence_schema_version,
                'score' => $score,
                'max_score' => $maxScore,
                'pass_threshold_snapshot' => $threshold,
                'passed' => $passed,
                'scoring_policy_snapshot' => json_encode($scoring, JSON_THROW_ON_ERROR),
                'question_set_hash' => $attempt->question_set_hash,
                'evidence_bundle_hash' => hash('sha256', $this->canonicalJson($evidenceBundle)),
                'answer_sheet_template_binding_snapshot' => null,
                'result_snapshot' => json_encode($resultSnapshot, JSON_THROW_ON_ERROR),
                'result_snapshot_hash' => hash('sha256', $this->canonicalJson($resultSnapshot)),
                'created_at' => $now,
            ]);

            $attemptVersion = (int) $attempt->version + 1;
            $accessVersion = (int) $access->version + 1;
            DB::table('internal_exam_attempts')->where('id', $attemptId)->update([
                'status' => $status,
                'version' => $attemptVersion,
                'finished_at' => $now,
            ]);
            $this->appendAttemptEvent(
                $actor['organization_id'], $attemptId, 'submitted',
                'in_progress', $status, (int) $attempt->version, $attemptVersion,
                $actor['user_id'], null, $now,
            );
            DB::table('internal_exam_accesses')->where('id', $access->id)->update([
                'status' => 'completed',
                'version' => $accessVersion,
                'completed_at' => $now,
            ]);
            $this->appendAccessEvent(
                $actor['organization_id'], (string) $access->id, $attemptId, 'completed',
                'started', 'completed', (int) $access->version, $accessVersion,
                $actor['user_id'], null, $now,
            );
            DB::table('internal_exam_station_sessions')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->whereNull('ended_at')
                ->update(['ended_at' => $now, 'end_reason' => 'exam_completed']);

            $oneTimeResultToken = null;
            if ((string) $access->launch_mode === 'remote_link') {
                $this->tokens->revokeExecution(
                    $actor['organization_id'],
                    (string) $access->id,
                    'attempt_completed',
                    $now,
                );
                $issued = $this->tokens->issueResultRead(
                    $actor['organization_id'],
                    (string) $access->id,
                    $attemptId,
                    $actor['user_id'],
                    $now,
                );
                $oneTimeResultToken = $issued['raw_token'];
            }

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'internal_exam.submitted', 'internal_exam_attempt', $attemptId, $requestId,
                ['fields' => ['status'], 'state' => 'in_progress'],
                ['fields' => ['status', 'result'], 'state' => $status],
            );

            $presented = $this->presentResult($actor['organization_id'], $resultId);
            $presented['one_time_result_token'] = $oneTimeResultToken;

            return $presented;
        });
    }

    /**
     * @param  list<array<string,mixed>>  $answers
     * @return array<string,mixed>
     */
    public function submitRemote(
        string $rawToken,
        string $attemptId,
        array $answers,
        string $requestId,
    ): array {
        $context = $this->tokens->verify($rawToken, 'exam_execution');
        if ($context['attempt_id'] !== $attemptId) {
            throw $this->invalidExamToken();
        }

        return DB::transaction(function () use ($rawToken, $context, $attemptId, $answers, $requestId): array {
            $attempt = $this->lockAttempt($context['organization_id'], $attemptId);
            if ((string) $attempt->status !== 'in_progress' || $attempt->started_at === null) {
                throw ResourceDomainException::conflict('Only an in-progress attempt can be submitted.');
            }

            /** @var AccessRow|null $access */
            $access = DB::table('internal_exam_accesses')
                ->where('organization_id', $context['organization_id'])
                ->where('id', $context['access_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->where('status', 'started')
                ->lockForUpdate()
                ->first();
            if ($access === null || (string) $access->launch_mode !== 'remote_link') {
                throw $this->invalidExamToken();
            }

            $lockedContext = $this->tokens->verifyForUpdate($rawToken, 'exam_execution');
            if ($lockedContext['organization_id'] !== $context['organization_id']
                || $lockedContext['access_id'] !== (string) $access->id
                || $lockedContext['attempt_id'] !== $attemptId) {
                throw $this->invalidExamToken();
            }

            /** @var ReservationRow|null $reservation */
            $reservation = DB::table('internal_exam_reservations')
                ->where('organization_id', $context['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->where('status', 'consumed')
                ->lockForUpdate()
                ->first();
            if ($reservation === null) {
                throw ResourceDomainException::conflict('Submit requires previously consumed inventory.');
            }

            if (DB::table('internal_exam_results')
                ->where('organization_id', $context['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->exists()) {
                throw ResourceDomainException::conflict('Attempt already has an immutable result.');
            }

            /** @var DefinitionRow|null $definition */
            $definition = DB::table('internal_exam_definitions')
                ->where('id', $attempt->internal_exam_definition_id)
                ->first();
            if ($definition === null || (string) $definition->engine_kind !== 'question_test') {
                throw ResourceDomainException::conflict('This submit path currently requires a frozen question-test definition.');
            }

            $scoring = json_decode((string) $definition->scoring_policy_snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($scoring)
                || ($scoring['question_scoring'] ?? null) !== 'all_or_nothing'
                || ($scoring['pass_rule'] ?? null) !== 'minimum_score'
                || ! isset($scoring['pass_threshold'])
                || ! is_int($scoring['pass_threshold'])) {
                throw ResourceDomainException::conflict('Frozen scoring policy is unsupported or incomplete.');
            }
            $threshold = (int) $scoring['pass_threshold'];

            $answerMap = [];
            foreach ($answers as $answer) {
                if (! isset($answer['ordinal']) || ! is_int($answer['ordinal']) || ! array_key_exists('answer', $answer)) {
                    throw ResourceDomainException::rule('Every submitted answer requires integer ordinal and answer.');
                }
                $ordinal = (int) $answer['ordinal'];
                if ($ordinal < 1 || array_key_exists($ordinal, $answerMap)) {
                    throw ResourceDomainException::rule('Answer ordinals must be unique positive integers.');
                }
                $answerMap[$ordinal] = $answer['answer'];
            }

            $questions = DB::table('internal_exam_attempt_questions')
                ->where('organization_id', $context['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->orderBy('ordinal')
                ->lockForUpdate()
                ->get();
            if ($questions->count() === 0 || count($answerMap) !== $questions->count()) {
                throw ResourceDomainException::rule('Submitted answers must exactly cover the frozen question set.');
            }

            $score = 0;
            $maxScore = 0;
            $finalEvidence = [];
            $now = CarbonImmutable::now();
            foreach ($questions as $question) {
                /** @var QuestionRow $question */
                $ordinal = (int) $question->ordinal;
                if (! array_key_exists($ordinal, $answerMap)) {
                    throw ResourceDomainException::rule('Submitted answers must exactly cover the frozen question set.');
                }
                $snapshot = json_decode((string) $question->question_snapshot, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($snapshot) || ! array_key_exists('correct_answer', $snapshot)) {
                    throw ResourceDomainException::conflict('Frozen question evaluation key is missing.');
                }
                $candidate = $answerMap[$ordinal];
                $correct = $this->canonicalJson($candidate) === $this->canonicalJson($snapshot['correct_answer']);
                $points = $correct ? (int) $question->max_points_snapshot : 0;
                $score += $points;
                $maxScore += (int) $question->max_points_snapshot;
                DB::table('internal_exam_attempt_questions')->where('id', $question->id)->update([
                    'candidate_answer' => json_encode($candidate, JSON_THROW_ON_ERROR),
                    'is_correct' => $correct,
                    'points_awarded' => $points,
                    'answered_at' => $now,
                ]);
                $finalEvidence[] = [
                    'ordinal' => $ordinal,
                    'question_snapshot_hash' => (string) $question->question_snapshot_hash,
                    'candidate_answer' => $candidate,
                    'is_correct' => $correct,
                    'points_awarded' => $points,
                    'max_points' => (int) $question->max_points_snapshot,
                ];
            }

            if ($threshold < 0 || $threshold > $maxScore) {
                throw ResourceDomainException::conflict('Frozen numeric pass threshold is outside the frozen score range.');
            }

            $passed = $score >= $threshold;
            $status = $passed ? 'passed' : 'failed';
            $resultSnapshot = [
                'engine_kind' => 'question_test',
                'score' => $score,
                'max_score' => $maxScore,
                'pass_threshold' => $threshold,
                'passed' => $passed,
                'questions' => $finalEvidence,
            ];
            $evidenceBundle = [
                'attempt_id' => $attemptId,
                'organization_id' => $context['organization_id'],
                'candidate_snapshot' => json_decode((string) $attempt->candidate_snapshot, true, 512, JSON_THROW_ON_ERROR),
                'driving_category_id' => (string) $attempt->driving_category_id,
                'exam_part' => (string) $attempt->exam_part,
                'language_code' => (string) $attempt->language_code,
                'requirement_basis_snapshot' => json_decode((string) $attempt->requirement_basis_snapshot, true, 512, JSON_THROW_ON_ERROR),
                'internal_exam_definition_id' => (string) $attempt->internal_exam_definition_id,
                'exam_definition_version_snapshot' => (string) $attempt->exam_definition_version_snapshot,
                'exam_definition_hash_snapshot' => (string) $attempt->exam_definition_hash_snapshot,
                'questions' => $finalEvidence,
                'scoring_policy_snapshot' => $scoring,
                'score' => $score,
                'max_score' => $maxScore,
                'passed' => $passed,
                'finished_at' => $now->toIso8601String(),
            ];

            $resultId = (string) Str::uuid7();
            DB::table('internal_exam_results')->insert([
                'id' => $resultId,
                'organization_id' => $context['organization_id'],
                'internal_exam_attempt_id' => $attemptId,
                'evidence_schema_version' => (int) $attempt->evidence_schema_version,
                'score' => $score,
                'max_score' => $maxScore,
                'pass_threshold_snapshot' => $threshold,
                'passed' => $passed,
                'scoring_policy_snapshot' => json_encode($scoring, JSON_THROW_ON_ERROR),
                'question_set_hash' => $attempt->question_set_hash,
                'evidence_bundle_hash' => hash('sha256', $this->canonicalJson($evidenceBundle)),
                'answer_sheet_template_binding_snapshot' => null,
                'result_snapshot' => json_encode($resultSnapshot, JSON_THROW_ON_ERROR),
                'result_snapshot_hash' => hash('sha256', $this->canonicalJson($resultSnapshot)),
                'created_at' => $now,
            ]);

            $attemptVersion = (int) $attempt->version + 1;
            $accessVersion = (int) $access->version + 1;
            DB::table('internal_exam_attempts')->where('id', $attemptId)->update([
                'status' => $status,
                'version' => $attemptVersion,
                'finished_at' => $now,
            ]);
            $this->appendAttemptEvent(
                $context['organization_id'], $attemptId, 'submitted',
                'in_progress', $status, (int) $attempt->version, $attemptVersion,
                null, null, $now,
            );

            DB::table('internal_exam_accesses')->where('id', $access->id)->update([
                'status' => 'completed',
                'version' => $accessVersion,
                'completed_at' => $now,
            ]);
            $this->appendAccessEvent(
                $context['organization_id'], (string) $access->id, $attemptId, 'completed',
                'started', 'completed', (int) $access->version, $accessVersion,
                null, null, $now,
            );

            $this->tokens->revokeExecution(
                $context['organization_id'],
                (string) $access->id,
                'attempt_completed',
                $now,
            );
            $issued = $this->tokens->issueResultRead(
                $context['organization_id'],
                (string) $access->id,
                $attemptId,
                null,
                $now,
            );

            $this->auditOutbox->recordOrganizationSystemEvent(
                $context['organization_id'],
                'internal_exam.submitted',
                'internal_exam_attempt',
                $attemptId,
                $requestId,
                ['fields' => ['status'], 'state' => 'in_progress'],
                ['fields' => ['status', 'result'], 'state' => $status],
            );

            $presented = $this->presentResult($context['organization_id'], $resultId);
            $presented['one_time_result_token'] = $issued['raw_token'];

            return $presented;
        });
    }

    /** @return array<string,mixed> */
    public function resultWithToken(string $rawToken, string $attemptId): array
    {
        $context = $this->tokens->verify($rawToken, 'finished_result_read');
        if ($context['attempt_id'] !== $attemptId) {
            throw $this->invalidExamToken();
        }

        return DB::transaction(function () use ($rawToken, $context, $attemptId): array {
            $attempt = $this->lockAttempt($context['organization_id'], $attemptId);

            /** @var AccessRow|null $access */
            $access = DB::table('internal_exam_accesses')
                ->where('organization_id', $context['organization_id'])
                ->where('id', $context['access_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->lockForUpdate()
                ->first();
            if ($access === null
                || (string) $access->launch_mode !== 'remote_link'
                || (string) $access->status !== 'completed'
                || ! in_array((string) $attempt->status, ['passed', 'failed'], true)) {
                throw $this->invalidExamToken();
            }

            $this->tokens->verifyForUpdate($rawToken, 'finished_result_read');

            $resultId = DB::table('internal_exam_results')
                ->where('organization_id', $context['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->value('id');
            if (! is_string($resultId) || $resultId === '') {
                throw $this->invalidExamToken();
            }

            return $this->presentResult($context['organization_id'], $resultId);
        });
    }

    /** @return list<array<string,mixed>> */
    public function questionsWithToken(string $rawToken, string $attemptId): array
    {
        $context = $this->tokens->verify($rawToken, 'finished_result_read');
        if ($context['attempt_id'] !== $attemptId) {
            throw $this->invalidExamToken();
        }

        return DB::transaction(function () use ($rawToken, $context, $attemptId): array {
            $attempt = $this->lockAttempt($context['organization_id'], $attemptId);

            /** @var AccessRow|null $access */
            $access = DB::table('internal_exam_accesses')
                ->where('organization_id', $context['organization_id'])
                ->where('id', $context['access_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->lockForUpdate()
                ->first();
            if ($access === null
                || (string) $access->launch_mode !== 'remote_link'
                || (string) $access->status !== 'completed'
                || ! in_array((string) $attempt->status, ['passed', 'failed'], true)) {
                throw $this->invalidExamToken();
            }

            $this->tokens->verifyForUpdate($rawToken, 'finished_result_read');

            $rows = DB::table('internal_exam_attempt_questions')
                ->where('organization_id', $context['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->orderBy('ordinal')
                ->get()
                ->map(static function (object $row): array {
                    /** @var ReviewQuestionRow $row */
                    return [
                        'ordinal' => (int) $row->ordinal,
                        'group' => (string) $row->group,
                        'question_snapshot' => json_decode((string) $row->question_snapshot, true, 512, JSON_THROW_ON_ERROR),
                        'candidate_answer' => $row->candidate_answer === null
                            ? null
                            : json_decode((string) $row->candidate_answer, true, 512, JSON_THROW_ON_ERROR),
                        'is_correct' => $row->is_correct === null ? null : (bool) $row->is_correct,
                        'points_awarded' => $row->points_awarded === null ? null : (int) $row->points_awarded,
                        'max_points' => (int) $row->max_points_snapshot,
                    ];
                })
                ->values()
                ->all();

            return array_values($rows);
        });
    }

    /** @return array<string,mixed> */
    public function technicalAbort(
        string $sessionId,
        string $attemptId,
        string $reason,
        string $requestId,
    ): array {
        if (trim($reason) === '') {
            throw ResourceDomainException::rule('Technical-abort reason is required.');
        }
        $actor = $this->scope->requireAttempt($sessionId, 'exams.generate', $attemptId);

        return DB::transaction(function () use ($actor, $attemptId, $reason, $requestId): array {
            $attempt = $this->lockAttempt($actor['organization_id'], $attemptId);
            if ((string) $attempt->status !== 'in_progress') {
                throw ResourceDomainException::conflict('Technical abort requires an in-progress attempt.');
            }
            /** @var AccessRow|null $access */
            $access = DB::table('internal_exam_accesses')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->where('status', 'started')
                ->lockForUpdate()
                ->first();
            if ($access === null) {
                throw ResourceDomainException::conflict('Started access is missing.');
            }
            $reservation = DB::table('internal_exam_reservations')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->where('status', 'consumed')
                ->first();
            if ($reservation === null) {
                throw ResourceDomainException::conflict('Technical abort requires previously consumed inventory.');
            }

            $now = CarbonImmutable::now();
            if ((string) $access->launch_mode === 'remote_link') {
                $this->tokens->revokeExecution(
                    $actor['organization_id'],
                    (string) $access->id,
                    'technical_abort',
                    $now,
                );
            }
            $attemptVersion = (int) $attempt->version + 1;
            $accessVersion = (int) $access->version + 1;
            DB::table('internal_exam_attempts')->where('id', $attemptId)->update([
                'status' => 'technical_abort',
                'version' => $attemptVersion,
                'finished_at' => $now,
                'technical_aborted_at' => $now,
            ]);
            $this->appendAttemptEvent(
                $actor['organization_id'], $attemptId, 'technical_abort',
                'in_progress', 'technical_abort', (int) $attempt->version, $attemptVersion,
                $actor['user_id'], $reason, $now,
            );
            DB::table('internal_exam_accesses')->where('id', $access->id)->update([
                'status' => 'technical_abort',
                'version' => $accessVersion,
                'technical_aborted_at' => $now,
            ]);
            $this->appendAccessEvent(
                $actor['organization_id'], (string) $access->id, $attemptId, 'technical_abort',
                'started', 'technical_abort', (int) $access->version, $accessVersion,
                $actor['user_id'], $reason, $now,
            );
            DB::table('internal_exam_station_sessions')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->whereNull('ended_at')
                ->update(['ended_at' => $now, 'end_reason' => 'technical_abort']);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'internal_exam.technical_aborted', 'internal_exam_attempt', $attemptId, $requestId,
                ['fields' => ['status'], 'state' => 'in_progress'],
                ['fields' => ['status'], 'state' => 'technical_abort'],
                $reason,
            );

            return $this->presentAttempt($actor['organization_id'], $attemptId);
        });
    }

    /** @return array<string,mixed> */
    public function transferStation(
        string $sessionId,
        string $attemptId,
        string $rawTargetStationCredential,
        string $reason,
        string $requestId,
    ): array {
        if (trim($reason) === '') {
            throw ResourceDomainException::rule('Station-transfer reason is required.');
        }
        $actor = $this->scope->requireAttempt($sessionId, 'exams.start.local', $attemptId);
        $targetBinding = $this->stationCredentials->resolveCurrent(
            $rawTargetStationCredential,
            $actor['organization_id'],
        );
        $targetStationId = $targetBinding['station_id'];

        return DB::transaction(function () use (
            $actor,
            $attemptId,
            $targetStationId,
            $rawTargetStationCredential,
            $reason,
            $requestId,
        ): array {
            $attempt = $this->lockAttempt($actor['organization_id'], $attemptId);
            if ((string) $attempt->status !== 'in_progress') {
                throw ResourceDomainException::conflict('Station transfer requires an in-progress attempt.');
            }

            /** @var AccessRow|null $access */
            $access = DB::table('internal_exam_accesses')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->where('status', 'started')
                ->lockForUpdate()
                ->first();
            if ($access === null || (string) $access->launch_mode === 'remote_link') {
                throw ResourceDomainException::conflict('Station transfer requires a started station-bound access.');
            }

            /** @var StationSessionRow|null $current */
            $current = DB::table('internal_exam_station_sessions')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();
            if ($current === null) {
                throw ResourceDomainException::conflict('Active station session is missing.');
            }

            $currentStationId = (string) $current->exam_station_id;
            if ($currentStationId === $targetStationId) {
                throw ResourceDomainException::rule('Target station must differ from the active station.');
            }

            $stationIds = [$currentStationId, $targetStationId];
            sort($stationIds, SORT_STRING);
            $lockedStations = DB::table('exam_stations')
                ->where('organization_id', $actor['organization_id'])
                ->whereIn('id', $stationIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($lockedStations->count() !== 2) {
                throw ResourceDomainException::notFound('Exam station not found.');
            }

            $now = CarbonImmutable::now();
            $targetContext = $this->stationCredentials->authenticateForStation(
                $rawTargetStationCredential,
                $actor['organization_id'],
                $targetStationId,
                $now,
            );
            if ($targetContext['station_id'] !== $targetStationId) {
                throw new ResourceDomainException(
                    'INVALID_EXAM_STATION_CREDENTIAL',
                    401,
                    'Invalid or revoked exam station credential.',
                );
            }

            if (DB::table('internal_exam_station_sessions')
                ->where('organization_id', $actor['organization_id'])
                ->where('exam_station_id', $targetStationId)
                ->whereNull('ended_at')
                ->exists()) {
                throw ResourceDomainException::conflict('Target exam station is occupied.');
            }

            DB::table('internal_exam_station_sessions')->where('id', $current->id)->update([
                'ended_at' => $now,
                'end_reason' => 'transferred',
            ]);
            $sequence = (int) DB::table('internal_exam_station_sessions')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->max('session_sequence') + 1;
            $newSessionId = (string) Str::uuid7();
            DB::table('internal_exam_station_sessions')->insert([
                'id' => $newSessionId,
                'organization_id' => $actor['organization_id'],
                'internal_exam_attempt_id' => $attemptId,
                'internal_exam_access_id' => (string) $access->id,
                'exam_station_id' => $targetStationId,
                'session_sequence' => $sequence,
                'transferred_from_session_id' => (string) $current->id,
                'started_at' => $now,
                'ended_at' => null,
                'end_reason' => null,
                'created_by_user_id' => $actor['user_id'],
                'created_at' => $now,
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'internal_exam.station_transferred', 'internal_exam_station_session', $newSessionId, $requestId,
                ['fields' => ['station'], 'state' => 'transferred_from_previous'],
                ['fields' => ['station'], 'state' => 'active'],
                $reason,
            );

            return [
                'attempt_id' => $attemptId,
                'station_session_id' => $newSessionId,
                'exam_station_id' => $targetStationId,
                'session_sequence' => $sequence,
            ];
        });
    }

    /** @return AttemptRow */
    private function lockAttempt(string $organizationId, string $attemptId): object
    {
        /** @var AttemptRow|null $attempt */
        $attempt = DB::table('internal_exam_attempts')
            ->where('organization_id', $organizationId)
            ->where('id', $attemptId)
            ->lockForUpdate()
            ->first();
        if ($attempt === null) {
            throw ResourceDomainException::notFound();
        }

        return $attempt;
    }

    /** @return InventoryRow */
    private function lockAvailableInventory(string $organizationId): object
    {
        $candidates = DB::table('internal_exam_inventory_entries')
            ->where('organization_id', $organizationId)
            ->where('current_state', 'available')
            ->orderBy('id')
            ->lock('FOR UPDATE SKIP LOCKED')
            ->get();

        foreach ($candidates as $candidate) {
            /** @var InventoryRow $candidate */
            /** @var LedgerRow|null $latest */
            $latest = DB::table('internal_exam_inventory_ledger_entries')
                ->where('organization_id', $organizationId)
                ->where('internal_exam_inventory_entry_id', $candidate->id)
                ->orderByDesc('event_sequence')
                ->first();
            if ($latest !== null && in_array((string) $latest->event_type, ['unit_granted', 'unit_adjustment_granted', 'unit_released'], true)) {
                return $candidate;
            }
        }

        throw ResourceDomainException::conflict('No internally consistent available exam inventory unit exists.');
    }

    /**
     * @param  AttemptRow  $attempt
     * @return DefinitionRow
     */
    private function lockCurrentDefinition(object $attempt): object
    {
        $definitions = DB::table('internal_exam_definitions')
            ->where('driving_category_id', $attempt->driving_category_id)
            ->where('exam_part', $attempt->exam_part)
            ->where('language_code', $attempt->language_code)
            ->whereNull('retired_at')
            ->lock('FOR SHARE')
            ->get();
        if ($definitions->count() !== 1) {
            throw ResourceDomainException::conflict('Exactly one current exam definition is required for this attempt.');
        }

        $definition = $definitions->first();
        if ($definition === null) {
            throw ResourceDomainException::conflict('Exactly one current exam definition is required for this attempt.');
        }
        /** @var DefinitionRow $definition */

        return $definition;
    }

    /**
     * @param  AttemptRow  $attempt
     * @param  DefinitionRow  $definition
     */
    private function materializeDefinitionEvidence(string $organizationId, object $attempt, object $definition): ?string
    {
        if ((string) $definition->engine_kind === 'non_question_assessment') {
            return null;
        }
        if ((string) $definition->engine_kind !== 'question_test') {
            throw ResourceDomainException::conflict('Unsupported exam definition engine.');
        }

        $composition = json_decode((string) $definition->composition_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $questions = is_array($composition) ? ($composition['questions'] ?? null) : null;
        if (! is_array($questions) || $questions === []) {
            throw ResourceDomainException::conflict('Question-test definition has no frozen question composition.');
        }

        $setEvidence = [];
        foreach (array_values($questions) as $index => $item) {
            if (! is_array($item) || ! isset($item['group'], $item['question_snapshot'], $item['max_points'])
                || ! is_string($item['group']) || ! is_array($item['question_snapshot']) || ! is_int($item['max_points'])
                || $item['max_points'] < 0) {
                throw ResourceDomainException::conflict('Question-test definition contains invalid question evidence.');
            }
            $ordinal = $index + 1;
            $snapshotHash = hash('sha256', $this->canonicalJson($item['question_snapshot']));
            DB::table('internal_exam_attempt_questions')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organizationId,
                'internal_exam_attempt_id' => (string) $attempt->id,
                'ordinal' => $ordinal,
                'group' => (string) $item['group'],
                'source_question_identifier' => isset($item['source_question_identifier']) ? (string) $item['source_question_identifier'] : null,
                'source_question_revision_identifier' => isset($item['source_question_revision_identifier']) ? (string) $item['source_question_revision_identifier'] : null,
                'question_snapshot_schema_version' => isset($item['question_snapshot_schema_version'])
                    ? (int) $item['question_snapshot_schema_version']
                    : (int) $definition->definition_schema_version,
                'question_snapshot' => json_encode($item['question_snapshot'], JSON_THROW_ON_ERROR),
                'question_snapshot_hash' => $snapshotHash,
                'media_evidence_snapshot' => isset($item['media_evidence_snapshot'])
                    ? json_encode($item['media_evidence_snapshot'], JSON_THROW_ON_ERROR)
                    : null,
                'max_points_snapshot' => (int) $item['max_points'],
                'candidate_answer' => null,
                'is_correct' => null,
                'points_awarded' => null,
                'answered_at' => null,
                'created_at' => CarbonImmutable::now(),
            ]);
            $setEvidence[] = [
                'ordinal' => $ordinal,
                'group' => (string) $item['group'],
                'question_snapshot_hash' => $snapshotHash,
                'max_points' => (int) $item['max_points'],
            ];
        }

        return hash('sha256', $this->canonicalJson($setEvidence));
    }

    private function requireStationExists(string $organizationId, string $stationId): void
    {
        if (! DB::table('exam_stations')->where('organization_id', $organizationId)->where('id', $stationId)->exists()) {
            throw ResourceDomainException::notFound('Exam station not found.');
        }
    }

    private function appendInventoryLedger(
        string $organizationId,
        string $inventoryId,
        ?string $reservationId,
        ?string $attemptId,
        ?string $adjustmentId,
        string $eventType,
        int $availableDelta,
        ?string $actorUserId,
        ?string $reason,
        CarbonImmutable $occurredAt,
    ): void {
        $sequence = (int) DB::table('internal_exam_inventory_ledger_entries')
            ->where('organization_id', $organizationId)
            ->where('internal_exam_inventory_entry_id', $inventoryId)
            ->max('event_sequence') + 1;
        DB::table('internal_exam_inventory_ledger_entries')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organizationId,
            'internal_exam_inventory_entry_id' => $inventoryId,
            'internal_exam_reservation_id' => $reservationId,
            'internal_exam_attempt_id' => $attemptId,
            'internal_exam_inventory_adjustment_id' => $adjustmentId,
            'event_sequence' => $sequence,
            'event_type' => $eventType,
            'available_delta' => $availableDelta,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
    }

    private function appendAttemptEvent(
        string $organizationId,
        string $attemptId,
        string $eventType,
        ?string $fromStatus,
        string $toStatus,
        ?int $versionBefore,
        int $versionAfter,
        ?string $actorUserId,
        ?string $reason,
        CarbonImmutable $occurredAt,
    ): void {
        DB::table('internal_exam_attempt_lifecycle_events')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organizationId,
            'internal_exam_attempt_id' => $attemptId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'version_before' => $versionBefore,
            'version_after' => $versionAfter,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
    }

    private function appendAccessEvent(
        string $organizationId,
        string $accessId,
        string $attemptId,
        string $eventType,
        ?string $fromStatus,
        string $toStatus,
        ?int $versionBefore,
        int $versionAfter,
        ?string $actorUserId,
        ?string $reason,
        CarbonImmutable $occurredAt,
    ): void {
        DB::table('internal_exam_access_lifecycle_events')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organizationId,
            'internal_exam_access_id' => $accessId,
            'internal_exam_attempt_id' => $attemptId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'version_before' => $versionBefore,
            'version_after' => $versionAfter,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
    }

    /** @return array<string,mixed> */
    private function presentAttempt(string $organizationId, string $attemptId): array
    {
        $row = DB::table('internal_exam_attempts as a')
            ->join('driving_categories as c', 'c.id', '=', 'a.driving_category_id')
            ->where('a.organization_id', $organizationId)
            ->where('a.id', $attemptId)
            ->select(['a.*', 'c.code as driving_category_code'])
            ->firstOrFail();

        return [
            'id' => (string) $row->id,
            'student_id' => (string) $row->student_id,
            'course_enrollment_id' => (string) $row->course_enrollment_id,
            'course_attempt_sequence' => (int) $row->course_attempt_sequence,
            'candidate_snapshot' => json_decode((string) $row->candidate_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'exam_part' => (string) $row->exam_part,
            'driving_category_code' => (string) $row->driving_category_code,
            'language_code' => (string) $row->language_code,
            'status' => (string) $row->status,
            'started_at' => $row->started_at === null ? null : (string) $row->started_at,
            'finished_at' => $row->finished_at === null ? null : (string) $row->finished_at,
            'created_at' => (string) $row->created_at,
            'version' => (int) $row->version,
        ];
    }

    /** @return array<string,mixed> */
    private function presentAccess(string $organizationId, string $accessId): array
    {
        $row = DB::table('internal_exam_accesses')
            ->where('organization_id', $organizationId)
            ->where('id', $accessId)
            ->firstOrFail();

        return [
            'id' => (string) $row->id,
            'attempt_id' => (string) $row->internal_exam_attempt_id,
            'mode' => (string) $row->launch_mode,
            'station_id' => $row->station_id === null ? null : (string) $row->station_id,
            'status' => (string) $row->status,
            'expires_at' => $row->expires_at === null ? null : (string) $row->expires_at,
            'version' => (int) $row->version,
        ];
    }

    /** @return array<string,mixed> */
    private function presentResult(string $organizationId, string $resultId): array
    {
        $row = DB::table('internal_exam_results')
            ->where('organization_id', $organizationId)
            ->where('id', $resultId)
            ->firstOrFail();

        return [
            'attempt_id' => (string) $row->internal_exam_attempt_id,
            'passed' => (bool) $row->passed,
            'score' => $row->score === null ? null : (int) $row->score,
            'max_score' => $row->max_score === null ? null : (int) $row->max_score,
            'pass_threshold' => $row->pass_threshold_snapshot === null ? null : (int) $row->pass_threshold_snapshot,
            'evidence_bundle_hash' => (string) $row->evidence_bundle_hash,
        ];
    }

    /**
     * @param  array<string,mixed>  $current
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    private function applyCandidateSnapshotPatch(array $current, array $patch): array
    {
        if ($patch === []) {
            throw ResourceDomainException::rule('Candidate snapshot patch must contain at least one editable field.');
        }

        $unknown = array_diff(array_keys($patch), self::CANDIDATE_SNAPSHOT_PATCH_FIELDS);
        if ($unknown !== []) {
            throw ResourceDomainException::rule('Candidate snapshot patch contains a non-editable field.');
        }

        $next = $current;

        foreach (['first_name', 'last_name'] as $field) {
            if (! array_key_exists($field, $patch)) {
                continue;
            }
            if (! is_string($patch[$field])) {
                throw ResourceDomainException::rule('Candidate name fields must be strings.');
            }
            $value = trim($patch[$field]);
            if ($value === '' || mb_strlen($value) > 120) {
                throw ResourceDomainException::rule('Candidate name fields must be nonblank and at most 120 characters.');
            }
            $next[$field] = $value;
        }

        if (array_key_exists('birth_date', $patch)) {
            $birthDate = $patch['birth_date'];
            if ($birthDate !== null && (! is_string($birthDate) || ! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $birthDate))) {
                throw ResourceDomainException::rule('Candidate birth_date must be null or YYYY-MM-DD.');
            }
            $next['birth_date'] = $birthDate;
        }

        if (array_key_exists('contact_email', $patch)) {
            $email = $patch['contact_email'];
            if ($email !== null && ! is_string($email)) {
                throw ResourceDomainException::rule('Candidate contact_email must be null or a valid email.');
            }
            $normalized = $email === null ? null : mb_strtolower(trim($email));
            if ($normalized === '') {
                $normalized = null;
            }
            if ($normalized !== null && (mb_strlen($normalized) > 320 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false)) {
                throw ResourceDomainException::rule('Candidate contact_email must be null or a valid email.');
            }
            $next['contact_email'] = $normalized;
        }

        if (array_key_exists('no_pesel_declared', $patch)) {
            if (! is_bool($patch['no_pesel_declared'])) {
                throw ResourceDomainException::rule('Candidate no_pesel_declared must be boolean.');
            }
            $next['no_pesel_declared'] = $patch['no_pesel_declared'];
        }

        if (($next['no_pesel_declared'] ?? false) === true && ($next['birth_date'] ?? null) === null) {
            throw ResourceDomainException::rule('Candidate birth_date is required when no PESEL is declared.');
        }

        return $next;
    }

    private function assertAttemptExpectedVersion(object $attempt, ?string $expectedTag): void
    {
        if ($expectedTag === null || trim($expectedTag) === '') {
            throw new ResourceDomainException(
                'PRECONDITION_REQUIRED',
                428,
                'If-Match with current internal exam Attempt version is required.',
            );
        }

        $normalized = trim(trim($expectedTag), '"');
        $normalized = str_starts_with($normalized, 'v') ? substr($normalized, 1) : $normalized;
        if (! ctype_digit($normalized) || (int) $normalized !== (int) $attempt->version) {
            throw ResourceDomainException::conflict('Internal exam Attempt changed since it was loaded.');
        }
    }

    private function invalidExamToken(): ResourceDomainException
    {
        return new ResourceDomainException(
            'INVALID_EXAM_ACCESS_TOKEN',
            401,
            'Invalid or expired internal exam access token.',
        );
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
