<?php

namespace App\Modules\CalendarTraining;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\ResourcesCore\ResourceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AvailabilityFormalizationService
{
    public function __construct(
        private readonly AvailabilityScopeAuthorizer $availabilityScope,
        private readonly TrainingSessionScopeAuthorizer $trainingScope,
        private readonly ScheduleClaimService $claims,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /**
     * @param  array{course_enrollment_id?:?string,display_name?:?string,custom_meeting_place?:?string}  $input
     * @return array{availability_slot:array<string,mixed>,training_session:array<string,mixed>}
     */
    public function formalize(
        string $sessionId,
        string $slotId,
        array $input,
        string $requestId,
        ?string $expectedTag,
    ): array {
        $membership = $this->availabilityScope->membership($sessionId);
        $slotSnapshot = DB::table('availability_slots')
            ->where('organization_id', $membership['organization_id'])
            ->where('id', $slotId)
            ->first();
        if ($slotSnapshot === null) {
            throw ResourceDomainException::notFound();
        }
        if ((string) $slotSnapshot->status !== 'booked'
            || $slotSnapshot->booked_student_id === null
            || $slotSnapshot->training_session_id !== null) {
            throw ResourceDomainException::conflict('AvailabilitySlot is not an unformalized booked slot.');
        }

        $studentId = (string) $slotSnapshot->booked_student_id;
        $explicitCourseId = $this->nullableUuid($input['course_enrollment_id'] ?? null);

        return DB::transaction(function () use (
            $sessionId,
            $membership,
            $slotId,
            $studentId,
            $explicitCourseId,
            $input,
            $requestId,
            $expectedTag,
        ): array {
            $course = $this->lockedEligibleCourse(
                $membership['organization_id'],
                $studentId,
                $explicitCourseId,
            );

            $slot = DB::table('availability_slots')
                ->where('organization_id', $membership['organization_id'])
                ->where('id', $slotId)
                ->lockForUpdate()
                ->first();
            if ($slot === null) {
                throw ResourceDomainException::notFound();
            }
            $this->assertFormalizableSlot($slot, $studentId);
            $this->assertSlotVersion($slot, $expectedTag);

            $instructorId = $this->nullableUuid($slot->instructor_id);
            if ($instructorId === null) {
                throw ResourceDomainException::rule('Formalized practical TrainingSession requires an instructor.');
            }

            $actor = $this->trainingScope->requireCourseForCreate(
                $sessionId,
                'training_sessions.create',
                (string) $course->id,
                $instructorId,
            );
            $vehicleId = $this->nullableUuid($slot->vehicle_id);
            $locationId = $this->nullableUuid($slot->location_id);
            $displayName = $this->nullableText($input['display_name'] ?? null);
            $customMeetingPlace = $this->nullableText($input['custom_meeting_place'] ?? null);
            if ($locationId !== null && $customMeetingPlace !== null) {
                throw ResourceDomainException::rule('Saved location and custom meeting place are mutually exclusive.');
            }
            $this->assertResources($actor['organization_id'], $instructorId, $vehicleId, $locationId);
            [$startsAt, $endsAt, $minutes] = $this->formalInterval((string) $slot->starts_at, (string) $slot->ends_at);

            $trainingSessionId = (string) Str::uuid7();
            $now = now();
            DB::table('training_sessions')->insert([
                'id' => $trainingSessionId,
                'organization_id' => $actor['organization_id'],
                'course_enrollment_id' => (string) $course->id,
                'session_type' => 'practical',
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

            if ($displayName !== null || $customMeetingPlace !== null) {
                DB::table('training_session_calendar_details')->insert([
                    'organization_id' => $actor['organization_id'],
                    'training_session_id' => $trainingSessionId,
                    'display_name' => $displayName,
                    'custom_meeting_place' => $customMeetingPlace,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->claims->transferAvailabilityBookingToTrainingSession(
                $actor['organization_id'],
                $slotId,
                $trainingSessionId,
                $studentId,
                $instructorId,
                $vehicleId,
                $locationId,
                $startsAt,
                $endsAt,
            );

            $nextSlotVersion = (int) $slot->version + 1;
            DB::table('availability_slots')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $slotId)
                ->update([
                    'training_session_id' => $trainingSessionId,
                    'version' => $nextSlotVersion,
                    'updated_at' => $now,
                ]);

            DB::table('availability_slot_lifecycle_events')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $actor['organization_id'],
                'availability_slot_id' => $slotId,
                'event_type' => 'formalized',
                'from_status' => 'booked',
                'to_status' => 'booked',
                'slot_version_before' => (int) $slot->version,
                'slot_version_after' => $nextSlotVersion,
                'actor_user_id' => $actor['user_id'],
                'booking_student_id_snapshot' => $studentId,
                'reason' => null,
                'changed_fields_redacted' => json_encode(['training_session_id'], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'training.session.created',
                'training_session',
                $trainingSessionId,
                $requestId,
                ['fields' => [], 'state' => 'absent'],
                [
                    'fields' => [
                        'session_type', 'starts_at', 'ends_at', 'duration_minutes',
                        'instructor_id', 'vehicle_id', 'location_id',
                        'display_name', 'custom_meeting_place',
                    ],
                    'state' => 'planned',
                ],
            );
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'availability.slot.formalized',
                'availability_slot',
                $slotId,
                $requestId,
                ['fields' => ['training_session_id'], 'state' => 'booked_unformalized'],
                ['fields' => ['training_session_id'], 'state' => 'booked_formalized'],
            );

            $updatedSlot = DB::table('availability_slots')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $slotId)
                ->firstOrFail();

            return [
                'availability_slot' => $this->presentSlot($updatedSlot),
                'training_session' => [
                    'id' => $trainingSessionId,
                    'course_enrollment_id' => (string) $course->id,
                    'session_type' => 'practical',
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'duration_minutes' => $minutes,
                    'instructor_id' => $instructorId,
                    'vehicle_id' => $vehicleId,
                    'location_id' => $locationId,
                    'display_name' => $displayName,
                    'custom_meeting_place' => $customMeetingPlace,
                    'status' => 'planned',
                    'completed_at' => null,
                    'completed_by_user_id' => null,
                    'cancelled_at' => null,
                    'cancelled_by_user_id' => null,
                    'cancellation_reason' => null,
                    'version' => 1,
                ],
            ];
        });
    }

    /**
     * @return array{availability_slot:array<string,mixed>,training_session:array<string,mixed>}
     */
    public function replayResult(string $organizationId, string $slotId, string $trainingSessionId): array
    {
        $slot = DB::table('availability_slots')
            ->where('organization_id', $organizationId)
            ->where('id', $slotId)
            ->where('training_session_id', $trainingSessionId)
            ->first();
        $session = DB::table('training_sessions')
            ->where('organization_id', $organizationId)
            ->where('id', $trainingSessionId)
            ->first();
        if ($slot === null || $session === null) {
            throw ResourceDomainException::conflict('Formalized booking result is no longer resolvable.');
        }
        $details = DB::table('training_session_calendar_details')
            ->where('organization_id', $organizationId)
            ->where('training_session_id', $trainingSessionId)
            ->first();

        return [
            'availability_slot' => $this->presentSlot($slot),
            'training_session' => [
                'id' => (string) $session->id,
                'course_enrollment_id' => (string) $session->course_enrollment_id,
                'session_type' => (string) $session->session_type,
                'starts_at' => (string) $session->starts_at,
                'ends_at' => (string) $session->ends_at,
                'duration_minutes' => (int) $session->duration_minutes,
                'instructor_id' => (string) $session->instructor_id,
                'vehicle_id' => $this->nullableUuid($session->vehicle_id),
                'location_id' => $this->nullableUuid($session->location_id),
                'display_name' => $details === null ? null : $this->nullableText($details->display_name),
                'custom_meeting_place' => $details === null ? null : $this->nullableText($details->custom_meeting_place),
                'status' => (string) $session->status,
                'completed_at' => $session->completed_at === null ? null : (string) $session->completed_at,
                'completed_by_user_id' => $this->nullableUuid($session->completed_by_user_id),
                'cancelled_at' => $session->cancelled_at === null ? null : (string) $session->cancelled_at,
                'cancelled_by_user_id' => $this->nullableUuid($session->cancelled_by_user_id),
                'cancellation_reason' => $session->cancellation_reason === null ? null : (string) $session->cancellation_reason,
                'version' => (int) $session->version,
            ],
        ];
    }

    private function lockedEligibleCourse(
        string $organizationId,
        string $studentId,
        ?string $explicitCourseId,
    ): \stdClass {
        if ($explicitCourseId !== null) {
            $course = DB::table('course_enrollments')
                ->where('organization_id', $organizationId)
                ->where('id', $explicitCourseId)
                ->lockForUpdate()
                ->first();
            if ($course === null) {
                throw ResourceDomainException::notFound('CourseEnrollment not found.');
            }
            $this->assertActiveCourse($course);
            if (! hash_equals((string) $course->student_id, $studentId)) {
                throw ResourceDomainException::rule('CourseEnrollment Student does not match the booked AvailabilitySlot Student.');
            }

            return $course;
        }

        $courses = DB::table('course_enrollments')
            ->where('organization_id', $organizationId)
            ->where('student_id', $studentId)
            ->whereNull('completed_at')
            ->whereNull('interrupted_at')
            ->whereNull('cancelled_at')
            ->lockForUpdate()
            ->get();

        if ($courses->count() !== 1) {
            throw ResourceDomainException::rule(
                $courses->isEmpty()
                    ? 'Booked Student has no eligible active CourseEnrollment.'
                    : 'Booked Student has multiple eligible active CourseEnrollments; explicit course_enrollment_id is required.',
            );
        }
        $course = $courses->first();
        if ($course === null) {
            throw ResourceDomainException::rule('Booked Student has no eligible active CourseEnrollment.');
        }

        return $course;
    }

    private function assertActiveCourse(\stdClass $course): void
    {
        if ($course->completed_at !== null || $course->interrupted_at !== null || $course->cancelled_at !== null) {
            throw ResourceDomainException::conflict('Formalization requires an active CourseEnrollment.');
        }
    }

    private function assertFormalizableSlot(\stdClass $slot, string $studentId): void
    {
        if ((string) $slot->status !== 'booked'
            || $slot->booked_student_id === null
            || ! hash_equals((string) $slot->booked_student_id, $studentId)) {
            throw ResourceDomainException::conflict('AvailabilitySlot booking changed before formalization.');
        }
        if ($slot->training_session_id !== null) {
            throw ResourceDomainException::conflict('AvailabilitySlot is already formalized.');
        }
    }

    private function assertSlotVersion(\stdClass $slot, ?string $expectedTag): void
    {
        if ($expectedTag === null || trim($expectedTag) === '') {
            throw new ResourceDomainException(
                'PRECONDITION_REQUIRED',
                428,
                'If-Match with current AvailabilitySlot version is required.',
            );
        }
        $normalized = trim(trim($expectedTag), '"');
        $normalized = str_starts_with($normalized, 'v') ? substr($normalized, 1) : $normalized;
        if (! ctype_digit($normalized) || (int) $normalized !== (int) $slot->version) {
            throw ResourceDomainException::conflict('AvailabilitySlot changed since it was loaded.');
        }
    }

    /** @return array{0:string,1:string,2:int} */
    private function formalInterval(string $rawStart, string $rawEnd): array
    {
        try {
            $start = CarbonImmutable::parse($rawStart);
            $end = CarbonImmutable::parse($rawEnd);
        } catch (\Throwable) {
            throw ResourceDomainException::rule('AvailabilitySlot times cannot form a valid TrainingSession.');
        }

        if ((int) $start->micro !== 0 || (int) $end->micro !== 0) {
            throw ResourceDomainException::rule('Formal TrainingSession interval must resolve to whole seconds and minutes.');
        }
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        if ($seconds <= 0 || $seconds % 60 !== 0) {
            throw ResourceDomainException::rule('Formal TrainingSession must have a positive whole-minute duration.');
        }

        return [$start->toIso8601String(), $end->toIso8601String(), intdiv($seconds, 60)];
    }

    private function assertResources(
        string $organizationId,
        string $instructorId,
        ?string $vehicleId,
        ?string $locationId,
    ): void {
        foreach ([
            [$instructorId, 'staff_profiles', 'Instructor'],
            [$vehicleId, 'vehicles', 'Vehicle'],
            [$locationId, 'locations', 'Location'],
        ] as [$id, $table, $label]) {
            if ($id === null) {
                continue;
            }
            $exists = DB::table($table)
                ->where('organization_id', $organizationId)
                ->where('id', $id)
                ->whereNull('archived_at')
                ->exists();
            if (! $exists) {
                throw ResourceDomainException::rule($label.' must be an active resource in the same organization.');
            }
        }
    }

    private function nullableUuid(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array<string,mixed> */
    private function presentSlot(\stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'instructor_id' => $this->nullableUuid($row->instructor_id),
            'vehicle_id' => $this->nullableUuid($row->vehicle_id),
            'location_id' => $this->nullableUuid($row->location_id),
            'starts_at' => (string) $row->starts_at,
            'ends_at' => (string) $row->ends_at,
            'status' => (string) $row->status,
            'booked_student_id' => $this->nullableUuid($row->booked_student_id),
            'booked_at' => $row->booked_at === null ? null : (string) $row->booked_at,
            'training_session_id' => $this->nullableUuid($row->training_session_id),
            'version' => (int) $row->version,
        ];
    }
}
