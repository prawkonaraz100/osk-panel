<?php

namespace App\Modules\InternalExams;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\ResourcesCore\ResourceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class InternalExamService
{
    private const PRESTART_ACCESS = ['draft', 'ready', 'delivered_or_assigned', 'opened'];

    public function __construct(
        private readonly InternalExamScopeAuthorizer $scope,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

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

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'internal_exam.access.created', 'internal_exam_access', $id, $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['attempt', 'mode', 'station'], 'state' => $status],
            );

            return $this->presentAccess($actor['organization_id'], $id);
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
            $accessSnapshot = DB::table('internal_exam_accesses')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $accessId)
                ->first();
            if ($accessSnapshot === null) {
                throw ResourceDomainException::notFound();
            }
            $attempt = $this->lockAttempt($actor['organization_id'], (string) $accessSnapshot->internal_exam_attempt_id);
            $access = DB::table('internal_exam_accesses')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $accessId)
                ->lockForUpdate()
                ->firstOrFail();
            if ((string) $attempt->status !== 'created' || ! in_array((string) $access->status, self::PRESTART_ACCESS, true)) {
                throw ResourceDomainException::conflict('Only a pre-start access can be revoked.');
            }

            $reservation = DB::table('internal_exam_reservations')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attempt->id)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->first();
            if ($reservation === null) {
                throw ResourceDomainException::conflict('Reserved inventory is missing for pre-start revocation.');
            }
            $inventory = DB::table('internal_exam_inventory_entries')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $reservation->internal_exam_inventory_entry_id)
                ->lockForUpdate()
                ->first();
            if ($inventory === null || (string) $inventory->current_state !== 'reserved') {
                throw ResourceDomainException::conflict('Reserved inventory state is inconsistent.');
            }

            $now = CarbonImmutable::now();
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
        string $trustedStationId,
        string $requestId,
    ): array {
        $actor = $this->scope->requireAccess($sessionId, 'exams.start.local', $accessId);

        return DB::transaction(function () use ($actor, $accessId, $trustedStationId, $requestId): array {
            $accessSnapshot = DB::table('internal_exam_accesses')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $accessId)
                ->first();
            if ($accessSnapshot === null) {
                throw ResourceDomainException::notFound();
            }
            $attempt = $this->lockAttempt($actor['organization_id'], (string) $accessSnapshot->internal_exam_attempt_id);
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
            if ($access->station_id === null || (string) $access->station_id !== $trustedStationId) {
                throw ResourceDomainException::conflict('Authenticated station context does not match the access station.');
            }

            $definition = $this->lockCurrentDefinition($attempt);
            $station = DB::table('exam_stations')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $trustedStationId)
                ->lockForUpdate()
                ->first();
            if ($station === null) {
                throw ResourceDomainException::notFound('Exam station not found.');
            }
            $this->assertStationOperational($actor['organization_id'], $station, CarbonImmutable::now());

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

            $reservation = DB::table('internal_exam_reservations')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attempt->id)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->first();
            if ($reservation === null) {
                throw ResourceDomainException::conflict('Start requires exactly one reserved inventory unit.');
            }
            $inventory = DB::table('internal_exam_inventory_entries')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $reservation->internal_exam_inventory_entry_id)
                ->lockForUpdate()
                ->first();
            if ($inventory === null || (string) $inventory->current_state !== 'reserved') {
                throw ResourceDomainException::conflict('Reserved inventory state is inconsistent.');
            }

            $questionSetHash = $this->materializeDefinitionEvidence($actor['organization_id'], $attempt, $definition);
            $now = CarbonImmutable::now();
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

    /** @param list<array<string,mixed>> $answers
     *  @return array<string,mixed>
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

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'], $actor['id'], $actor['user_id'],
                'internal_exam.submitted', 'internal_exam_attempt', $attemptId, $requestId,
                ['fields' => ['status'], 'state' => 'in_progress'],
                ['fields' => ['status', 'result'], 'state' => $status],
            );

            return $this->presentResult($actor['organization_id'], $resultId);
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
        string $targetStationId,
        string $reason,
        string $requestId,
    ): array {
        if (trim($reason) === '') {
            throw ResourceDomainException::rule('Station-transfer reason is required.');
        }
        $actor = $this->scope->requireAttempt($sessionId, 'exams.start.local', $attemptId);

        return DB::transaction(function () use ($actor, $attemptId, $targetStationId, $reason, $requestId): array {
            $attempt = $this->lockAttempt($actor['organization_id'], $attemptId);
            if ((string) $attempt->status !== 'in_progress') {
                throw ResourceDomainException::conflict('Station transfer requires an in-progress attempt.');
            }
            $access = DB::table('internal_exam_accesses')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->where('status', 'started')
                ->lockForUpdate()
                ->first();
            if ($access === null || (string) $access->launch_mode === 'remote_link') {
                throw ResourceDomainException::conflict('Station transfer requires a started station-bound access.');
            }
            $current = DB::table('internal_exam_station_sessions')
                ->where('organization_id', $actor['organization_id'])
                ->where('internal_exam_attempt_id', $attemptId)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();
            if ($current === null) {
                throw ResourceDomainException::conflict('Active station session is missing.');
            }
            if ((string) $current->exam_station_id === $targetStationId) {
                throw ResourceDomainException::rule('Target station must differ from the active station.');
            }

            $target = DB::table('exam_stations')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $targetStationId)
                ->lockForUpdate()
                ->first();
            if ($target === null) {
                throw ResourceDomainException::notFound('Target exam station not found.');
            }
            $now = CarbonImmutable::now();
            $this->assertStationOperational($actor['organization_id'], $target, $now);
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

    private function lockAttempt(string $organizationId, string $attemptId): object
    {
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

    private function lockAvailableInventory(string $organizationId): object
    {
        $candidates = DB::table('internal_exam_inventory_entries')
            ->where('organization_id', $organizationId)
            ->where('current_state', 'available')
            ->orderBy('id')
            ->lock('FOR UPDATE SKIP LOCKED')
            ->get();

        foreach ($candidates as $candidate) {
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

        return $definitions->first();
    }

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

    private function assertStationOperational(string $organizationId, object $station, CarbonImmutable $now): void
    {
        if ((string) $station->administrative_status !== 'enabled' || $station->last_authenticated_heartbeat_at === null) {
            throw ResourceDomainException::conflict('Exam station is not operational.');
        }
        $threshold = config('internal_exams.station_heartbeat_fresh_seconds');
        if (! is_int($threshold) && ! (is_string($threshold) && ctype_digit($threshold))) {
            throw new LogicException('INTERNAL_EXAM_STATION_HEARTBEAT_FRESH_SECONDS must be configured.');
        }
        $threshold = (int) $threshold;
        if ($threshold < 1) {
            throw new LogicException('Internal exam station heartbeat threshold must be positive.');
        }
        $heartbeat = CarbonImmutable::parse((string) $station->last_authenticated_heartbeat_at);
        if ($heartbeat->lt($now->subSeconds($threshold))) {
            throw ResourceDomainException::conflict('Exam station heartbeat is stale.');
        }
        if (! DB::table('exam_station_credentials')
            ->where('organization_id', $organizationId)
            ->where('exam_station_id', $station->id)
            ->whereNull('revoked_at')
            ->exists()) {
            throw ResourceDomainException::conflict('Exam station has no current credential.');
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
            'exam_part' => (string) $row->exam_part,
            'driving_category_code' => (string) $row->driving_category_code,
            'language_code' => (string) $row->language_code,
            'status' => (string) $row->status,
            'started_at' => $row->started_at === null ? null : (string) $row->started_at,
            'finished_at' => $row->finished_at === null ? null : (string) $row->finished_at,
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
