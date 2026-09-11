<?php

namespace App\Modules\CalendarTraining;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\ResourcesCore\ResourceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AvailabilitySlotService
{
    public function __construct(
        private readonly AvailabilityScopeAuthorizer $scopeAuthorizer,
        private readonly ScheduleClaimService $claims,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /**
     * @param  array{from?:?string,to?:?string,staff_id?:?string,vehicle_id?:?string,location_id?:?string}  $filters
     * @return list<array<string,mixed>>
     */
    public function list(string $sessionId, array $filters): array
    {
        $visibility = $this->scopeAuthorizer->visibility($sessionId);
        $query = DB::table('availability_slots')
            ->where('organization_id', $visibility['membership']['organization_id']);

        if (($filters['from'] ?? null) !== null) {
            $query->where('ends_at', '>', CarbonImmutable::parse((string) $filters['from'])->toIso8601String());
        }
        if (($filters['to'] ?? null) !== null) {
            $query->where('starts_at', '<', CarbonImmutable::parse((string) $filters['to'])->toIso8601String());
        }
        foreach ([
            'staff_id' => 'instructor_id',
            'vehicle_id' => 'vehicle_id',
            'location_id' => 'location_id',
        ] as $filter => $column) {
            if (($filters[$filter] ?? null) !== null) {
                $query->where($column, $filters[$filter]);
            }
        }

        if (! $visibility['unrestricted']) {
            $query->where(function (Builder $scope) use ($visibility): void {
                $hasClause = false;
                if ($visibility['own_instructor_id'] !== null) {
                    $scope->where('instructor_id', $visibility['own_instructor_id']);
                    $hasClause = true;
                }
                if ($visibility['assigned_student_ids'] !== []) {
                    if ($hasClause) {
                        $scope->orWhereIn('booked_student_id', $visibility['assigned_student_ids']);
                    } else {
                        $scope->whereIn('booked_student_id', $visibility['assigned_student_ids']);
                    }
                    $hasClause = true;
                }
                if ($visibility['assigned_location_ids'] !== []) {
                    if ($hasClause) {
                        $scope->orWhereIn('location_id', $visibility['assigned_location_ids']);
                    } else {
                        $scope->whereIn('location_id', $visibility['assigned_location_ids']);
                    }
                }
            });
        }

        return array_values($query
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (\stdClass $row): array => $this->present($row))
            ->all());
    }

    /**
     * @param  array{instructor_id?:?string,vehicle_id?:?string,location_id?:?string,starts_at:string,ends_at:string}  $input
     * @return array<string,mixed>
     */
    public function create(string $sessionId, array $input, string $requestId): array
    {
        $snapshot = $this->scopeAuthorizer->membership($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $input, $requestId): array {
            [$startsAt, $endsAt] = $this->interval($input['starts_at'], $input['ends_at']);
            $instructorId = $this->nullableUuid($input['instructor_id'] ?? null);
            $vehicleId = $this->nullableUuid($input['vehicle_id'] ?? null);
            $locationId = $this->nullableUuid($input['location_id'] ?? null);
            $actor = $this->scopeAuthorizer->requireCreate($sessionId, $instructorId);
            if ($actor['organization_id'] !== $snapshot['organization_id']) {
                throw ResourceDomainException::notFound();
            }
            $this->assertResources($actor['organization_id'], $instructorId, $vehicleId, $locationId);

            $id = (string) Str::uuid7();
            $now = now();
            DB::table('availability_slots')->insert([
                'id' => $id,
                'organization_id' => $actor['organization_id'],
                'instructor_id' => $instructorId,
                'vehicle_id' => $vehicleId,
                'location_id' => $locationId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'available',
                'booked_student_id' => null,
                'booked_at' => null,
                'training_session_id' => null,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->appendLifecycle(
                $actor['organization_id'],
                $id,
                'created',
                null,
                'available',
                null,
                1,
                $actor['user_id'],
                null,
                null,
                ['starts_at', 'ends_at', 'instructor_id', 'vehicle_id', 'location_id'],
                $now,
            );
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'availability.slot.created',
                'availability_slot',
                $id,
                $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['starts_at', 'ends_at', 'instructor_id', 'vehicle_id', 'location_id'], 'state' => 'available'],
            );

            return $this->present(DB::table('availability_slots')->where('id', $id)->firstOrFail());
        });
    }

    /**
     * @param  array{instructor_id?:?string,vehicle_id?:?string,location_id?:?string,starts_at?:string,ends_at?:string}  $input
     * @return array<string,mixed>
     */
    public function update(
        string $sessionId,
        string $slotId,
        array $input,
        string $requestId,
        ?string $expectedTag,
    ): array {
        $membership = $this->scopeAuthorizer->membership($sessionId);

        return DB::transaction(function () use ($sessionId, $membership, $slotId, $input, $requestId, $expectedTag): array {
            $row = $this->lockedSlot($membership['organization_id'], $slotId);
            $this->assertAvailable($row);
            $this->assertVersion($row, $expectedTag);

            $instructorId = array_key_exists('instructor_id', $input)
                ? $this->nullableUuid($input['instructor_id'])
                : $this->nullableUuid($row->instructor_id);
            $vehicleId = array_key_exists('vehicle_id', $input)
                ? $this->nullableUuid($input['vehicle_id'])
                : $this->nullableUuid($row->vehicle_id);
            $locationId = array_key_exists('location_id', $input)
                ? $this->nullableUuid($input['location_id'])
                : $this->nullableUuid($row->location_id);
            $rawStart = array_key_exists('starts_at', $input) ? (string) $input['starts_at'] : (string) $row->starts_at;
            $rawEnd = array_key_exists('ends_at', $input) ? (string) $input['ends_at'] : (string) $row->ends_at;
            [$startsAt, $endsAt] = $this->interval($rawStart, $rawEnd);

            $actor = $this->scopeAuthorizer->requireTarget($sessionId, $row, $instructorId);
            $this->assertResources($actor['organization_id'], $instructorId, $vehicleId, $locationId);

            $changed = [];
            if (! $this->sameInstant((string) $row->starts_at, $startsAt)) {
                $changed[] = 'starts_at';
            }
            if (! $this->sameInstant((string) $row->ends_at, $endsAt)) {
                $changed[] = 'ends_at';
            }
            foreach ([
                'instructor_id' => [$row->instructor_id, $instructorId],
                'vehicle_id' => [$row->vehicle_id, $vehicleId],
                'location_id' => [$row->location_id, $locationId],
            ] as $field => [$before, $after]) {
                if ($this->nullableUuid($before) !== $after) {
                    $changed[] = $field;
                }
            }

            if ($changed === []) {
                return $this->present($row);
            }

            $now = now();
            $nextVersion = (int) $row->version + 1;
            DB::table('availability_slots')->where('id', $slotId)->update([
                'instructor_id' => $instructorId,
                'vehicle_id' => $vehicleId,
                'location_id' => $locationId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'version' => $nextVersion,
                'updated_at' => $now,
            ]);
            $this->appendLifecycle(
                $actor['organization_id'],
                $slotId,
                'updated',
                'available',
                'available',
                (int) $row->version,
                $nextVersion,
                $actor['user_id'],
                null,
                null,
                $changed,
                $now,
            );
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'availability.slot.updated',
                'availability_slot',
                $slotId,
                $requestId,
                ['fields' => $changed, 'state' => 'available'],
                ['fields' => $changed, 'state' => 'available'],
            );

            return $this->present(DB::table('availability_slots')->where('id', $slotId)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function book(
        string $sessionId,
        string $slotId,
        string $studentId,
        string $requestId,
        ?string $expectedTag,
    ): array {
        $snapshot = $this->scopeAuthorizer->membership($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $slotId, $studentId, $requestId, $expectedTag): array {
            $actor = $this->scopeAuthorizer->requireBookingStudent($sessionId, $studentId);
            if ($actor['organization_id'] !== $snapshot['organization_id']) {
                throw ResourceDomainException::notFound();
            }

            $row = $this->lockedSlot($actor['organization_id'], $slotId);
            $this->assertAvailable($row);
            $this->assertVersion($row, $expectedTag);
            $this->assertResources(
                $actor['organization_id'],
                $this->nullableUuid($row->instructor_id),
                $this->nullableUuid($row->vehicle_id),
                $this->nullableUuid($row->location_id),
            );

            $now = now();
            $nextVersion = (int) $row->version + 1;
            DB::table('availability_slots')->where('id', $slotId)->update([
                'status' => 'booked',
                'booked_student_id' => $studentId,
                'booked_at' => $now,
                'version' => $nextVersion,
                'updated_at' => $now,
            ]);

            $this->claims->replaceForAvailabilityBooking(
                $actor['organization_id'],
                $slotId,
                $studentId,
                $this->nullableUuid($row->instructor_id),
                $this->nullableUuid($row->vehicle_id),
                $this->nullableUuid($row->location_id),
                (string) $row->starts_at,
                (string) $row->ends_at,
            );
            $this->appendLifecycle(
                $actor['organization_id'],
                $slotId,
                'booked',
                'available',
                'booked',
                (int) $row->version,
                $nextVersion,
                $actor['user_id'],
                $studentId,
                null,
                ['status', 'booked_student_id', 'booked_at'],
                $now,
            );
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'availability.slot.booked',
                'availability_slot',
                $slotId,
                $requestId,
                ['fields' => ['status'], 'state' => 'available'],
                ['fields' => ['status', 'booked_student_id', 'booked_at'], 'state' => 'booked'],
            );

            return $this->present(DB::table('availability_slots')->where('id', $slotId)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function cancel(
        string $sessionId,
        string $slotId,
        ?string $reason,
        string $requestId,
        ?string $expectedTag,
    ): array {
        $membership = $this->scopeAuthorizer->membership($sessionId);

        return DB::transaction(function () use ($sessionId, $membership, $slotId, $reason, $requestId, $expectedTag): array {
            $row = $this->lockedSlot($membership['organization_id'], $slotId);
            if (! in_array((string) $row->status, ['available', 'booked'], true)) {
                throw ResourceDomainException::conflict('Only an available or booked AvailabilitySlot may be cancelled.');
            }
            if ($row->training_session_id !== null) {
                throw ResourceDomainException::conflict('Formalized AvailabilitySlot must be managed through its TrainingSession.');
            }
            $this->assertVersion($row, $expectedTag);
            $actor = $this->scopeAuthorizer->requireTarget(
                $sessionId,
                $row,
                $this->nullableUuid($row->instructor_id),
            );

            $studentSnapshot = $this->nullableUuid($row->booked_student_id);
            if ((string) $row->status === 'booked') {
                $this->claims->releaseAvailabilityBooking($actor['organization_id'], $slotId);
            }

            $now = now();
            $nextVersion = (int) $row->version + 1;
            DB::table('availability_slots')->where('id', $slotId)->update([
                'status' => 'cancelled',
                'booked_student_id' => null,
                'booked_at' => null,
                'version' => $nextVersion,
                'updated_at' => $now,
            ]);
            $this->appendLifecycle(
                $actor['organization_id'],
                $slotId,
                'cancelled',
                (string) $row->status,
                'cancelled',
                (int) $row->version,
                $nextVersion,
                $actor['user_id'],
                $studentSnapshot,
                $this->nullableText($reason),
                ['status', 'booked_student_id', 'booked_at'],
                $now,
            );
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'availability.slot.cancelled',
                'availability_slot',
                $slotId,
                $requestId,
                ['fields' => ['status', 'booked_student_id'], 'state' => (string) $row->status],
                ['fields' => ['status', 'booked_student_id'], 'state' => 'cancelled'],
                $this->nullableText($reason),
            );

            return $this->present(DB::table('availability_slots')->where('id', $slotId)->firstOrFail());
        });
    }

    /** @param array<string,mixed> $slot */
    public function etag(array $slot): string
    {
        return '"v'.(int) $slot['version'].'"';
    }

    private function lockedSlot(string $organizationId, string $slotId): \stdClass
    {
        $row = DB::table('availability_slots')
            ->where('organization_id', $organizationId)
            ->where('id', $slotId)
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $row;
    }

    private function assertAvailable(\stdClass $row): void
    {
        if ((string) $row->status !== 'available') {
            throw ResourceDomainException::conflict('AvailabilitySlot is no longer available.');
        }
        if ($row->booked_student_id !== null || $row->booked_at !== null || $row->training_session_id !== null) {
            throw ResourceDomainException::conflict('AvailabilitySlot current state is inconsistent with available status.');
        }
    }

    private function assertVersion(\stdClass $row, ?string $expectedTag): void
    {
        if ($expectedTag === null || trim($expectedTag) === '') {
            throw new ResourceDomainException('PRECONDITION_REQUIRED', 428, 'If-Match with current AvailabilitySlot version is required.');
        }
        $normalized = trim(trim($expectedTag), '"');
        $normalized = str_starts_with($normalized, 'v') ? substr($normalized, 1) : $normalized;
        if (! ctype_digit($normalized) || (int) $normalized !== (int) $row->version) {
            throw ResourceDomainException::conflict('AvailabilitySlot changed since it was loaded.');
        }
    }

    /** @return array{0:string,1:string} */
    private function interval(string $rawStart, string $rawEnd): array
    {
        try {
            $start = CarbonImmutable::parse($rawStart);
            $end = CarbonImmutable::parse($rawEnd);
        } catch (\Throwable) {
            throw ResourceDomainException::rule('Availability slot times must be valid date-time values.');
        }
        if (! $end->greaterThan($start)) {
            throw ResourceDomainException::rule('Availability slot end must be after start.');
        }

        return [$start->toIso8601String(), $end->toIso8601String()];
    }

    private function sameInstant(string $left, string $right): bool
    {
        try {
            return CarbonImmutable::parse($left)->equalTo(CarbonImmutable::parse($right));
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertResources(
        string $organizationId,
        ?string $instructorId,
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

    /**
     * @param  list<string>  $changedFields
     */
    private function appendLifecycle(
        string $organizationId,
        string $slotId,
        string $eventType,
        ?string $fromStatus,
        string $toStatus,
        ?int $versionBefore,
        int $versionAfter,
        string $actorUserId,
        ?string $bookingStudentSnapshot,
        ?string $reason,
        array $changedFields,
        mixed $occurredAt,
    ): void {
        DB::table('availability_slot_lifecycle_events')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organizationId,
            'availability_slot_id' => $slotId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'slot_version_before' => $versionBefore,
            'slot_version_after' => $versionAfter,
            'actor_user_id' => $actorUserId,
            'booking_student_id_snapshot' => $bookingStudentSnapshot,
            'reason' => $reason,
            'changed_fields_redacted' => json_encode($changedFields, JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
        ]);
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
    private function present(\stdClass $row): array
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
