<?php

namespace App\Modules\CalendarTraining;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\ResourcesCore\ResourceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrainingSessionService
{
    public function __construct(
        private readonly TrainingSessionScopeAuthorizer $scopeAuthorizer,
        private readonly ScheduleClaimService $claims,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /** @return list<array<string,mixed>> */
    public function listForCourse(string $sessionId, string $courseId): array
    {
        $access = $this->scopeAuthorizer->courseListAccess($sessionId, 'training_sessions.view', $courseId);
        $query = DB::table('training_sessions')
            ->where('organization_id', $access['membership']['organization_id'])
            ->where('course_enrollment_id', $courseId)
            ->orderBy('starts_at')
            ->orderBy('id');

        if (! $access['unrestricted']) {
            $query->where('instructor_id', $access['own_instructor_id']);
        }

        return array_values($query->get()->map(fn (\stdClass $row): array => $this->present($row))->all());
    }

    /** @return array<string,mixed> */
    public function get(string $sessionId, string $trainingSessionId): array
    {
        $actor = $this->scopeAuthorizer->requireSessionTarget($sessionId, 'training_sessions.view', $trainingSessionId);
        $row = DB::table('training_sessions')
            ->where('organization_id', $actor['organization_id'])
            ->where('id', $trainingSessionId)
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $this->present($row);
    }

    /**
     * @param  array{session_type:string,starts_at:string,ends_at:string,instructor_id:string,vehicle_id?:?string,location_id?:?string}  $input
     * @return array<string,mixed>
     */
    public function create(string $sessionId, string $courseId, array $input, string $requestId): array
    {
        $snapshot = $this->scopeAuthorizer->membership($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $courseId, $input, $requestId): array {
            $course = $this->lockedCourse($snapshot['organization_id'], $courseId);
            $actor = $this->scopeAuthorizer->requireCourseForCreate(
                $sessionId,
                'training_sessions.create',
                $courseId,
                $input['instructor_id'],
            );
            $this->assertCourseActive($course);
            $sessionType = $this->sessionType($input['session_type']);
            [$startsAt, $endsAt, $minutes] = $this->interval($input['starts_at'], $input['ends_at']);
            $instructorId = $input['instructor_id'];
            $vehicleId = $input['vehicle_id'] ?? null;
            $locationId = $input['location_id'] ?? null;
            $this->assertResources($actor['organization_id'], $instructorId, $vehicleId, $locationId);

            $id = (string) Str::uuid7();
            $now = now();
            DB::table('training_sessions')->insert([
                'id' => $id,
                'organization_id' => $actor['organization_id'],
                'course_enrollment_id' => $courseId,
                'session_type' => $sessionType,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'duration_minutes' => $minutes,
                'instructor_id' => $instructorId,
                'vehicle_id' => $vehicleId,
                'location_id' => $locationId,
                'status' => 'planned',
                'created_by_user_id' => $actor['user_id'],
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->claims->replaceForTrainingSession(
                $actor['organization_id'],
                $id,
                (string) $course->student_id,
                $instructorId,
                $vehicleId,
                $locationId,
                $startsAt,
                $endsAt,
            );

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'training.session.created',
                'training_session',
                $id,
                $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['session_type', 'starts_at', 'ends_at', 'duration_minutes', 'instructor_id', 'vehicle_id', 'location_id'], 'state' => 'planned'],
            );

            return $this->present(DB::table('training_sessions')->where('id', $id)->firstOrFail());
        });
    }

    /**
     * @param  array{starts_at?:string,ends_at?:string,instructor_id?:string,vehicle_id?:?string,location_id?:?string}  $input
     * @return array<string,mixed>
     */
    public function update(
        string $sessionId,
        string $trainingSessionId,
        array $input,
        string $requestId,
        ?string $expectedTag,
    ): array {
        $snapshot = $this->scopeAuthorizer->membership($sessionId);
        $locator = DB::table('training_sessions')
            ->where('organization_id', $snapshot['organization_id'])
            ->where('id', $trainingSessionId)
            ->first();
        if ($locator === null) {
            throw ResourceDomainException::notFound();
        }

        return DB::transaction(function () use ($sessionId, $snapshot, $locator, $trainingSessionId, $input, $requestId, $expectedTag): array {
            $course = $this->lockedCourse($snapshot['organization_id'], (string) $locator->course_enrollment_id);
            $row = $this->lockedSession($snapshot['organization_id'], $trainingSessionId);
            $actor = $this->scopeAuthorizer->requireSessionTarget($sessionId, 'training_sessions.edit', $trainingSessionId);
            $this->assertCourseActive($course);
            $this->assertPlanned($row);
            $this->assertSessionVersion($row, $expectedTag);

            $rawStartsAt = array_key_exists('starts_at', $input) ? (string) $input['starts_at'] : (string) $row->starts_at;
            $rawEndsAt = array_key_exists('ends_at', $input) ? (string) $input['ends_at'] : (string) $row->ends_at;
            [$startsAt, $endsAt, $minutes] = $this->interval($rawStartsAt, $rawEndsAt);
            $instructorId = array_key_exists('instructor_id', $input) ? (string) $input['instructor_id'] : (string) $row->instructor_id;
            $vehicleId = array_key_exists('vehicle_id', $input) ? $this->nullableUuidValue($input['vehicle_id']) : $this->nullableUuidValue($row->vehicle_id);
            $locationId = array_key_exists('location_id', $input) ? $this->nullableUuidValue($input['location_id']) : $this->nullableUuidValue($row->location_id);
            $this->assertResources($actor['organization_id'], $instructorId, $vehicleId, $locationId);

            $changed = [];
            if (! $this->sameInstant((string) $row->starts_at, $startsAt)) {
                $changed[] = 'starts_at';
            }
            if (! $this->sameInstant((string) $row->ends_at, $endsAt)) {
                $changed[] = 'ends_at';
            }
            if ((int) $row->duration_minutes !== $minutes) {
                $changed[] = 'duration_minutes';
            }
            foreach ([
                'instructor_id' => [$row->instructor_id, $instructorId],
                'vehicle_id' => [$row->vehicle_id, $vehicleId],
                'location_id' => [$row->location_id, $locationId],
            ] as $field => [$before, $after]) {
                if ($this->nullableUuidValue($before) !== $after) {
                    $changed[] = $field;
                }
            }

            if ($changed === []) {
                return $this->present($row);
            }

            $this->claims->replaceForTrainingSession(
                $actor['organization_id'],
                $trainingSessionId,
                (string) $course->student_id,
                $instructorId,
                $vehicleId,
                $locationId,
                $startsAt,
                $endsAt,
            );

            DB::table('training_sessions')->where('id', $trainingSessionId)->update([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'duration_minutes' => $minutes,
                'instructor_id' => $instructorId,
                'vehicle_id' => $vehicleId,
                'location_id' => $locationId,
                'version' => (int) $row->version + 1,
                'updated_at' => now(),
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'training.session.updated',
                'training_session',
                $trainingSessionId,
                $requestId,
                ['fields' => $changed, 'state' => 'planned'],
                ['fields' => $changed, 'state' => 'planned'],
            );

            return $this->present(DB::table('training_sessions')->where('id', $trainingSessionId)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function recordAttendance(
        string $sessionId,
        string $trainingSessionId,
        string $status,
        string $requestId,
        ?string $expectedTag,
    ): array {
        if (! in_array($status, ['present', 'absent'], true)) {
            throw ResourceDomainException::rule('Attendance status must be present or absent.');
        }

        $snapshot = $this->scopeAuthorizer->membership($sessionId);
        $locator = DB::table('training_sessions')
            ->where('organization_id', $snapshot['organization_id'])
            ->where('id', $trainingSessionId)
            ->first();
        if ($locator === null) {
            throw ResourceDomainException::notFound();
        }

        return DB::transaction(function () use ($sessionId, $snapshot, $locator, $trainingSessionId, $status, $requestId, $expectedTag): array {
            $course = $this->lockedCourse($snapshot['organization_id'], (string) $locator->course_enrollment_id);
            $row = $this->lockedSession($snapshot['organization_id'], $trainingSessionId);
            $actor = $this->scopeAuthorizer->requireSessionTarget($sessionId, 'training_sessions.edit', $trainingSessionId);
            $this->assertCourseActive($course);
            $this->assertPlanned($row);
            $this->assertSessionVersion($row, $expectedTag);

            $attendanceRows = DB::table('training_session_attendance')
                ->where('organization_id', $actor['organization_id'])
                ->where('training_session_id', $trainingSessionId)
                ->lockForUpdate()
                ->get();
            if ($attendanceRows->count() > 1) {
                throw ResourceDomainException::conflict('TrainingSession has ambiguous attendance history.');
            }

            $now = now();
            if ($attendanceRows->isEmpty()) {
                DB::table('training_session_attendance')->insert([
                    'organization_id' => $actor['organization_id'],
                    'training_session_id' => $trainingSessionId,
                    'course_enrollment_id' => (string) $course->id,
                    'student_id' => (string) $course->student_id,
                    'status' => $status,
                    'confirmed_by_user_id' => $actor['user_id'],
                    'confirmed_at' => $now,
                ]);
            } else {
                DB::table('training_session_attendance')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('training_session_id', $trainingSessionId)
                    ->update([
                        'course_enrollment_id' => (string) $course->id,
                        'student_id' => (string) $course->student_id,
                        'status' => $status,
                        'confirmed_by_user_id' => $actor['user_id'],
                        'confirmed_at' => $now,
                    ]);
            }

            $nextVersion = (int) $row->version + 1;
            DB::table('training_sessions')->where('id', $trainingSessionId)->update([
                'version' => $nextVersion,
                'updated_at' => $now,
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'training.session.attendance_recorded',
                'training_session',
                $trainingSessionId,
                $requestId,
                ['fields' => ['attendance'], 'state' => 'planned'],
                ['fields' => ['attendance'], 'state' => 'planned'],
            );

            $attendance = DB::table('training_session_attendance')
                ->where('organization_id', $actor['organization_id'])
                ->where('training_session_id', $trainingSessionId)
                ->firstOrFail();

            return $this->presentAttendance($attendance, $nextVersion);
        });
    }

    /** @return array<string,mixed> */
    public function complete(string $sessionId, string $trainingSessionId, string $requestId): array
    {
        $snapshot = $this->scopeAuthorizer->membership($sessionId);
        $locator = DB::table('training_sessions')
            ->where('organization_id', $snapshot['organization_id'])
            ->where('id', $trainingSessionId)
            ->first();
        if ($locator === null) {
            throw ResourceDomainException::notFound();
        }

        return DB::transaction(function () use ($sessionId, $snapshot, $locator, $trainingSessionId, $requestId): array {
            $course = $this->lockedCourse($snapshot['organization_id'], (string) $locator->course_enrollment_id);
            $row = $this->lockedSession($snapshot['organization_id'], $trainingSessionId);
            $actor = $this->scopeAuthorizer->requireSessionTarget($sessionId, 'training_sessions.edit', $trainingSessionId);
            $this->assertCourseActive($course);
            $this->assertPlanned($row);

            $attendanceRows = DB::table('training_session_attendance')
                ->where('organization_id', $actor['organization_id'])
                ->where('training_session_id', $trainingSessionId)
                ->lockForUpdate()
                ->get();
            if ($attendanceRows->count() !== 1) {
                throw ResourceDomainException::conflict('Exactly one verified attendance row is required before completion.');
            }
            $attendance = $attendanceRows->first();
            if ($attendance === null || $attendance->confirmed_at === null || $attendance->confirmed_by_user_id === null) {
                throw ResourceDomainException::conflict('Verified attendance is required before completion.');
            }
            if (! in_array((string) $attendance->status, ['present', 'absent'], true)) {
                throw ResourceDomainException::conflict('Attendance status is invalid.');
            }

            [, , $duration] = $this->interval((string) $row->starts_at, (string) $row->ends_at);
            if ($duration !== (int) $row->duration_minutes) {
                throw ResourceDomainException::conflict('Stored TrainingSession duration does not match its interval.');
            }

            $creditCount = DB::table('training_hour_ledger_entries')
                ->where('organization_id', $actor['organization_id'])
                ->where('training_session_id', $trainingSessionId)
                ->where('entry_type', 'credit')
                ->count();
            if ($creditCount !== 0) {
                throw ResourceDomainException::conflict('TrainingSession already has formal base credit.');
            }

            if ((string) $attendance->status === 'present') {
                DB::table('training_hour_ledger_entries')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $actor['organization_id'],
                    'course_enrollment_id' => (string) $course->id,
                    'training_session_id' => $trainingSessionId,
                    'entry_type' => 'credit',
                    'training_part' => (string) $row->session_type,
                    'minutes' => $duration,
                    'source_entry_id' => null,
                    'reason' => 'TrainingSession completed with verified present attendance',
                    'actor_user_id' => $actor['user_id'],
                    'created_at' => now(),
                ]);
            }

            $this->claims->releaseTrainingSession($actor['organization_id'], $trainingSessionId);
            $now = now();
            DB::table('training_sessions')->where('id', $trainingSessionId)->update([
                'status' => 'completed',
                'completed_at' => $now,
                'completed_by_user_id' => $actor['user_id'],
                'cancelled_at' => null,
                'cancelled_by_user_id' => null,
                'cancellation_reason' => null,
                'version' => (int) $row->version + 1,
                'updated_at' => $now,
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'training.session.completed',
                'training_session',
                $trainingSessionId,
                $requestId,
                ['fields' => ['status'], 'state' => 'planned'],
                ['fields' => ['status', 'completed_at'], 'state' => 'completed'],
            );

            return $this->present(DB::table('training_sessions')->where('id', $trainingSessionId)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function cancel(string $sessionId, string $trainingSessionId, string $reason, string $requestId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ResourceDomainException::rule('Cancellation reason is required.');
        }

        $snapshot = $this->scopeAuthorizer->membership($sessionId);
        $locator = DB::table('training_sessions')
            ->where('organization_id', $snapshot['organization_id'])
            ->where('id', $trainingSessionId)
            ->first();
        if ($locator === null) {
            throw ResourceDomainException::notFound();
        }

        return DB::transaction(function () use ($sessionId, $snapshot, $locator, $trainingSessionId, $reason, $requestId): array {
            $this->lockedCourse($snapshot['organization_id'], (string) $locator->course_enrollment_id);
            $row = $this->lockedSession($snapshot['organization_id'], $trainingSessionId);
            $actor = $this->scopeAuthorizer->requireSessionTarget($sessionId, 'training_sessions.cancel', $trainingSessionId);
            $this->assertPlanned($row);

            $creditCount = DB::table('training_hour_ledger_entries')
                ->where('organization_id', $actor['organization_id'])
                ->where('training_session_id', $trainingSessionId)
                ->where('entry_type', 'credit')
                ->count();
            if ($creditCount !== 0) {
                throw ResourceDomainException::conflict('A credited TrainingSession cannot be cancelled in normal flow.');
            }

            $this->claims->releaseTrainingSession($actor['organization_id'], $trainingSessionId);
            $now = now();
            DB::table('training_sessions')->where('id', $trainingSessionId)->update([
                'status' => 'cancelled',
                'completed_at' => null,
                'completed_by_user_id' => null,
                'cancelled_at' => $now,
                'cancelled_by_user_id' => $actor['user_id'],
                'cancellation_reason' => $reason,
                'version' => (int) $row->version + 1,
                'updated_at' => $now,
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'training.session.cancelled',
                'training_session',
                $trainingSessionId,
                $requestId,
                ['fields' => ['status'], 'state' => 'planned'],
                ['fields' => ['status', 'cancelled_at'], 'state' => 'cancelled'],
                $reason,
            );

            return $this->present(DB::table('training_sessions')->where('id', $trainingSessionId)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function hours(string $sessionId, string $courseId): array
    {
        $actor = $this->scopeAuthorizer->requireCourseProjectionTarget($sessionId, 'training_sessions.view', $courseId);
        $course = DB::table('course_enrollments')
            ->where('organization_id', $actor['organization_id'])
            ->where('id', $courseId)
            ->first();
        if ($course === null) {
            throw ResourceDomainException::notFound();
        }

        $currentTheory = (int) DB::table('training_hour_ledger_entries')
            ->where('organization_id', $actor['organization_id'])
            ->where('course_enrollment_id', $courseId)
            ->where('training_part', 'theory')
            ->sum('minutes');
        $currentPractical = (int) DB::table('training_hour_ledger_entries')
            ->where('organization_id', $actor['organization_id'])
            ->where('course_enrollment_id', $courseId)
            ->where('training_part', 'practical')
            ->sum('minutes');

        $externalBase = DB::table('recognized_external_training')
            ->where('organization_id', $actor['organization_id'])
            ->where('course_enrollment_id', $courseId)
            ->whereNull('superseded_at')
            ->whereNull('revoked_at')
            ->where('recognized_for_driving_category_id', (string) $course->driving_category_id)
            ->where('recognized_for_training_type', (string) $course->training_type);
        $externalTheory = (int) (clone $externalBase)->where('training_part', 'theory')->sum('recognized_minutes');
        $externalPractical = (int) (clone $externalBase)->where('training_part', 'practical')->sum('recognized_minutes');

        return [
            'current_osk' => [
                'theory_minutes' => $currentTheory,
                'practical_minutes' => $currentPractical,
            ],
            'recognized_external' => [
                'theory_minutes' => $externalTheory,
                'practical_minutes' => $externalPractical,
            ],
            'totals' => [
                'theory_minutes' => $currentTheory + $externalTheory,
                'practical_minutes' => $currentPractical + $externalPractical,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function correctHours(
        string $sessionId,
        string $courseId,
        string $trainingPart,
        int $minutes,
        string $reason,
        ?string $sourceEntryId,
        string $requestId,
        ?string $expectedCourseTag,
    ): array {
        if (! in_array($trainingPart, ['theory', 'practical'], true)) {
            throw ResourceDomainException::rule('Training part must be theory or practical.');
        }
        if ($minutes === 0) {
            throw ResourceDomainException::rule('Correction minutes must be a non-zero signed delta.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ResourceDomainException::rule('Correction reason is required.');
        }

        $snapshot = $this->scopeAuthorizer->membership($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $courseId, $trainingPart, $minutes, $reason, $sourceEntryId, $requestId, $expectedCourseTag): array {
            $course = $this->lockedCourse($snapshot['organization_id'], $courseId);
            $actor = $this->scopeAuthorizer->requireCourseStudentTarget($sessionId, 'training_hours.correct', $courseId);
            $this->assertCourseVersion($course, $expectedCourseTag);

            $source = null;
            if ($sourceEntryId !== null) {
                $source = DB::table('training_hour_ledger_entries')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('course_enrollment_id', $courseId)
                    ->where('training_part', $trainingPart)
                    ->where('id', $sourceEntryId)
                    ->lockForUpdate()
                    ->first();
                if ($source === null) {
                    throw ResourceDomainException::notFound('Source ledger entry not found.');
                }
                if ((string) $source->entry_type === 'reversal') {
                    throw ResourceDomainException::rule('A reversal cannot be used as a correction source.');
                }
            }

            $entryType = $source !== null && $minutes === -((int) $source->minutes) ? 'reversal' : 'correction';
            if ($entryType === 'reversal') {
                $alreadyReversed = DB::table('training_hour_ledger_entries')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('source_entry_id', $sourceEntryId)
                    ->where('entry_type', 'reversal')
                    ->exists();
                if ($alreadyReversed) {
                    throw ResourceDomainException::conflict('Source ledger entry was already reversed.');
                }
            }

            $currentPartTotal = (int) DB::table('training_hour_ledger_entries')
                ->where('organization_id', $actor['organization_id'])
                ->where('course_enrollment_id', $courseId)
                ->where('training_part', $trainingPart)
                ->sum('minutes');
            if ($currentPartTotal + $minutes < 0) {
                throw ResourceDomainException::rule('Correction would make the course training-part total negative.');
            }

            $trainingSessionId = $source === null ? null : $this->nullableUuidValue($source->training_session_id);
            if ($trainingSessionId !== null) {
                $sessionSubtotal = (int) DB::table('training_hour_ledger_entries')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('course_enrollment_id', $courseId)
                    ->where('training_part', $trainingPart)
                    ->where('training_session_id', $trainingSessionId)
                    ->sum('minutes');
                if ($sessionSubtotal + $minutes < 0) {
                    throw ResourceDomainException::rule('Correction would make the linked TrainingSession subtotal negative.');
                }
            }

            $entryId = (string) Str::uuid7();
            DB::table('training_hour_ledger_entries')->insert([
                'id' => $entryId,
                'organization_id' => $actor['organization_id'],
                'course_enrollment_id' => $courseId,
                'training_session_id' => $trainingSessionId,
                'entry_type' => $entryType,
                'training_part' => $trainingPart,
                'minutes' => $minutes,
                'source_entry_id' => $sourceEntryId,
                'reason' => $reason,
                'actor_user_id' => $actor['user_id'],
                'created_at' => now(),
            ]);

            $beforeVersion = (int) $course->version;
            $afterVersion = $beforeVersion + 1;
            DB::table('course_enrollments')->where('id', $courseId)->update([
                'version' => $afterVersion,
                'updated_at' => now(),
            ]);
            $state = $this->courseLifecycleState($course);
            DB::table('course_enrollment_lifecycle_events')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $actor['organization_id'],
                'course_enrollment_id' => $courseId,
                'event_type' => $state === 'active' ? 'updated' : 'closed_course_corrected',
                'from_lifecycle_state' => $state,
                'to_lifecycle_state' => $state,
                'from_training_stage' => (string) $course->training_stage,
                'to_training_stage' => (string) $course->training_stage,
                'course_version_before' => $beforeVersion,
                'course_version_after' => $afterVersion,
                'reason' => $reason,
                'actor_user_id' => $actor['user_id'],
                'correction_of_event_id' => null,
                'event_payload_redacted' => null,
                'occurred_at' => now(),
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'training.hours.corrected',
                'course_enrollment',
                $courseId,
                $requestId,
                ['fields' => ['training_hour_ledger', 'version'], 'state' => $state],
                ['fields' => ['training_hour_ledger', 'version'], 'state' => $state],
                $reason,
            );

            $entry = DB::table('training_hour_ledger_entries')->where('id', $entryId)->firstOrFail();
            $result = $this->presentLedgerEntry($entry);
            $result['course_version'] = $afterVersion;

            return $result;
        });
    }

    /** @param array<string,mixed> $session */
    public function etag(array $session): string
    {
        return '"v'.(int) $session['version'].'"';
    }

    public function courseEtag(int $version): string
    {
        return '"v'.$version.'"';
    }

    private function lockedCourse(string $organizationId, string $courseId): \stdClass
    {
        $row = DB::table('course_enrollments')
            ->where('organization_id', $organizationId)
            ->where('id', $courseId)
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $row;
    }

    private function lockedSession(string $organizationId, string $trainingSessionId): \stdClass
    {
        $row = DB::table('training_sessions')
            ->where('organization_id', $organizationId)
            ->where('id', $trainingSessionId)
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $row;
    }

    private function assertCourseActive(\stdClass $course): void
    {
        if ($this->courseLifecycleState($course) !== 'active') {
            throw ResourceDomainException::conflict('TrainingSession mutation requires an active CourseEnrollment.');
        }
    }

    private function assertPlanned(\stdClass $session): void
    {
        if ((string) $session->status !== 'planned') {
            throw ResourceDomainException::conflict('Only a planned TrainingSession may be changed by this command.');
        }
    }

    private function sessionType(string $value): string
    {
        if (! in_array($value, ['theory', 'practical'], true)) {
            throw ResourceDomainException::rule('TrainingSession type must be theory or practical.');
        }

        return $value;
    }

    /** @return array{0:string,1:string,2:int} */
    private function interval(string $rawStart, string $rawEnd): array
    {
        try {
            $start = CarbonImmutable::parse($rawStart);
            $end = CarbonImmutable::parse($rawEnd);
        } catch (\Throwable) {
            throw ResourceDomainException::rule('TrainingSession times must be valid date-time values.');
        }

        if ((int) $start->micro !== 0 || (int) $end->micro !== 0) {
            throw ResourceDomainException::rule('TrainingSession interval must resolve to whole seconds and minutes.');
        }

        $seconds = $end->getTimestamp() - $start->getTimestamp();
        if ($seconds <= 0 || $seconds % 60 !== 0) {
            throw ResourceDomainException::rule('TrainingSession must have a positive whole-minute duration.');
        }

        return [$start->toIso8601String(), $end->toIso8601String(), intdiv($seconds, 60)];
    }

    private function assertResources(
        string $organizationId,
        string $instructorId,
        ?string $vehicleId,
        ?string $locationId,
    ): void {
        $this->assertActiveTenantResource('staff_profiles', $organizationId, $instructorId, 'Instructor');
        if ($vehicleId !== null) {
            $this->assertActiveTenantResource('vehicles', $organizationId, $vehicleId, 'Vehicle');
        }
        if ($locationId !== null) {
            $this->assertActiveTenantResource('locations', $organizationId, $locationId, 'Location');
        }
    }

    private function assertActiveTenantResource(string $table, string $organizationId, string $id, string $label): void
    {
        $exists = DB::table($table)
            ->where('organization_id', $organizationId)
            ->where('id', $id)
            ->whereNull('archived_at')
            ->exists();
        if (! $exists) {
            throw ResourceDomainException::rule($label.' must be an active resource in the same organization.');
        }
    }

    private function assertSessionVersion(\stdClass $row, ?string $expectedTag): void
    {
        if ($expectedTag === null || trim($expectedTag) === '') {
            throw new ResourceDomainException('PRECONDITION_REQUIRED', 428, 'If-Match with current TrainingSession version is required.');
        }
        $normalized = trim(trim($expectedTag), '"');
        $normalized = str_starts_with($normalized, 'v') ? substr($normalized, 1) : $normalized;
        if (! ctype_digit($normalized) || (int) $normalized !== (int) $row->version) {
            throw ResourceDomainException::conflict('TrainingSession changed since it was loaded.');
        }
    }

    private function assertCourseVersion(\stdClass $row, ?string $expectedTag): void
    {
        if ($expectedTag === null || trim($expectedTag) === '') {
            throw new ResourceDomainException('PRECONDITION_REQUIRED', 428, 'If-Match with current CourseEnrollment version is required.');
        }
        $normalized = trim(trim($expectedTag), '"');
        $normalized = str_starts_with($normalized, 'v') ? substr($normalized, 1) : $normalized;
        if (! ctype_digit($normalized) || (int) $normalized !== (int) $row->version) {
            throw ResourceDomainException::conflict('CourseEnrollment changed since it was loaded.');
        }
    }

    private function sameInstant(string $left, string $right): bool
    {
        try {
            return CarbonImmutable::parse($left)->equalTo(CarbonImmutable::parse($right));
        } catch (\Throwable) {
            return false;
        }
    }

    private function nullableUuidValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function courseLifecycleState(\stdClass $row): string
    {
        if ($row->completed_at !== null) {
            return 'completed';
        }
        if ($row->interrupted_at !== null) {
            return 'interrupted';
        }
        if ($row->cancelled_at !== null) {
            return 'cancelled';
        }

        return 'active';
    }

    /** @return array<string,mixed> */
    private function present(\stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'course_enrollment_id' => (string) $row->course_enrollment_id,
            'session_type' => (string) $row->session_type,
            'starts_at' => (string) $row->starts_at,
            'ends_at' => (string) $row->ends_at,
            'duration_minutes' => (int) $row->duration_minutes,
            'instructor_id' => (string) $row->instructor_id,
            'vehicle_id' => $this->nullableUuidValue($row->vehicle_id),
            'location_id' => $this->nullableUuidValue($row->location_id),
            'status' => (string) $row->status,
            'completed_at' => $row->completed_at === null ? null : (string) $row->completed_at,
            'completed_by_user_id' => $this->nullableUuidValue($row->completed_by_user_id),
            'cancelled_at' => $row->cancelled_at === null ? null : (string) $row->cancelled_at,
            'cancelled_by_user_id' => $this->nullableUuidValue($row->cancelled_by_user_id),
            'cancellation_reason' => $row->cancellation_reason === null ? null : (string) $row->cancellation_reason,
            'version' => (int) $row->version,
        ];
    }

    /** @return array<string,mixed> */
    private function presentAttendance(\stdClass $row, int $sessionVersion): array
    {
        return [
            'training_session_id' => (string) $row->training_session_id,
            'course_enrollment_id' => (string) $row->course_enrollment_id,
            'student_id' => (string) $row->student_id,
            'status' => (string) $row->status,
            'confirmed_by_user_id' => (string) $row->confirmed_by_user_id,
            'confirmed_at' => (string) $row->confirmed_at,
            'session_version' => $sessionVersion,
        ];
    }

    /** @return array<string,mixed> */
    private function presentLedgerEntry(\stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'course_enrollment_id' => (string) $row->course_enrollment_id,
            'training_session_id' => $this->nullableUuidValue($row->training_session_id),
            'entry_type' => (string) $row->entry_type,
            'training_part' => (string) $row->training_part,
            'minutes' => (int) $row->minutes,
            'source_entry_id' => $this->nullableUuidValue($row->source_entry_id),
            'reason' => $row->reason === null ? null : (string) $row->reason,
            'created_at' => (string) $row->created_at,
        ];
    }
}
