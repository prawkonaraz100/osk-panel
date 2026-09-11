<?php

namespace App\Modules\CalendarTraining;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\ResourcesCore\ResourceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CalendarEventService
{
    public function __construct(
        private readonly CalendarEventScopeAuthorizer $scopeAuthorizer,
        private readonly CalendarDrivingLessonService $drivingLessons,
        private readonly CalendarAvailabilityBookingProjectionService $availabilityBookings,
        private readonly ScheduleClaimService $claims,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /**
     * @param  array{from?:?string,to?:?string,student_id?:?string,staff_id?:?string,vehicle_id?:?string,location_id?:?string,event_type?:list<string>}  $filters
     * @return list<array<string,mixed>>
     */
    public function list(string $sessionId, array $filters): array
    {
        $visibility = $this->scopeAuthorizer->visibility($sessionId);
        $items = [];
        $types = $filters['event_type'] ?? [];

        if ($types === [] || in_array('general_event', $types, true)) {
            $query = DB::table('calendar_events')
                ->where('organization_id', $visibility['membership']['organization_id'])
                ->where('event_type', 'general_event');

            $this->applyRangeAndResourceFilters($query, $filters);

            if (! $visibility['unrestricted']) {
                $query->where(function (Builder $scope) use ($visibility): void {
                    $hasClause = false;
                    if ($visibility['own_instructor_id'] !== null) {
                        $scope->where('instructor_id', $visibility['own_instructor_id']);
                        $hasClause = true;
                    }
                    if ($visibility['assigned_student_ids'] !== []) {
                        if ($hasClause) {
                            $scope->orWhereIn('student_id', $visibility['assigned_student_ids']);
                        } else {
                            $scope->whereIn('student_id', $visibility['assigned_student_ids']);
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

            foreach ($query->get() as $row) {
                $items[] = $this->present($row);
            }
        }

        if ($types === [] || in_array('driving_lesson', $types, true)) {
            $items = [
                ...$items,
                ...$this->availabilityBookings->list($sessionId, $filters),
                ...$this->drivingLessons->list($sessionId, $filters),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $time = strcmp((string) $left['starts_at'], (string) $right['starts_at']);

            return $time !== 0 ? $time : strcmp((string) $left['id'], (string) $right['id']);
        });

        return $items;
    }

    /** @return array<string,mixed> */
    public function get(string $sessionId, string $eventId): array
    {
        $membership = $this->scopeAuthorizer->visibility($sessionId)['membership'];
        $row = DB::table('calendar_events')
            ->where('organization_id', $membership['organization_id'])
            ->where('id', $eventId)
            ->where('event_type', 'general_event')
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        $this->scopeAuthorizer->requireViewTarget($sessionId, $row);

        return $this->present($row);
    }

    /**
     * @param  array{event_type:string,name?:?string,starts_at:string,ends_at:string,student_id?:?string,instructor_id?:?string,vehicle_id?:?string,location_id?:?string,custom_meeting_place?:?string}  $input
     * @return array<string,mixed>
     */
    public function create(string $sessionId, array $input, string $requestId): array
    {
        $snapshot = $this->scopeAuthorizer->visibility($sessionId)['membership'];

        return DB::transaction(function () use ($sessionId, $snapshot, $input, $requestId): array {
            $normalized = $this->normalizeInput($input, null);
            $actor = $this->scopeAuthorizer->requireCreate($sessionId, $normalized['instructor_id']);
            if ($actor['organization_id'] !== $snapshot['organization_id']) {
                throw ResourceDomainException::notFound();
            }
            $this->assertResources($actor['organization_id'], $normalized);

            $id = (string) Str::uuid7();
            $now = now();
            DB::table('calendar_events')->insert([
                'id' => $id,
                'organization_id' => $actor['organization_id'],
                'event_type' => 'general_event',
                'name' => $normalized['name'],
                'starts_at' => $normalized['starts_at'],
                'ends_at' => $normalized['ends_at'],
                'student_id' => $normalized['student_id'],
                'instructor_id' => $normalized['instructor_id'],
                'vehicle_id' => $normalized['vehicle_id'],
                'location_id' => $normalized['location_id'],
                'custom_meeting_place' => $normalized['custom_meeting_place'],
                'status' => 'scheduled',
                'created_by_user_id' => $actor['user_id'],
                'version' => 1,
                'completed_at' => null,
                'completed_by_user_id' => null,
                'cancelled_at' => null,
                'cancelled_by_user_id' => null,
                'cancellation_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->claims->replaceForCalendarEvent(
                $actor['organization_id'],
                $id,
                $normalized['student_id'],
                $normalized['instructor_id'],
                $normalized['vehicle_id'],
                $normalized['location_id'],
                $normalized['starts_at'],
                $normalized['ends_at'],
            );
            $this->appendLifecycle(
                $actor['organization_id'],
                $id,
                'created',
                null,
                'scheduled',
                null,
                1,
                $actor['user_id'],
                null,
                array_keys($normalized),
                $now,
            );
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'calendar.event.created',
                'calendar_event',
                $id,
                $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => array_keys($normalized), 'state' => 'scheduled'],
            );

            return $this->present(DB::table('calendar_events')->where('id', $id)->firstOrFail());
        });
    }

    /**
     * @param  array{event_type?:string,name?:?string,starts_at?:string,ends_at?:string,student_id?:?string,instructor_id?:?string,vehicle_id?:?string,location_id?:?string,custom_meeting_place?:?string}  $input
     * @return array<string,mixed>
     */
    public function update(string $sessionId, string $eventId, array $input, string $requestId, ?string $expectedTag): array
    {
        $membership = $this->scopeAuthorizer->visibility($sessionId)['membership'];

        return DB::transaction(function () use ($sessionId, $membership, $eventId, $input, $requestId, $expectedTag): array {
            $row = $this->lockedEvent($membership['organization_id'], $eventId);
            $this->assertManualEvent($row);
            $this->assertScheduled($row);
            $this->assertVersion($row, $expectedTag);
            $normalized = $this->normalizeInput($input, $row);
            $actor = $this->scopeAuthorizer->requireManageTarget($sessionId, $row, $normalized['instructor_id']);
            $this->assertResources($actor['organization_id'], $normalized);

            $changed = $this->changedFields($row, $normalized);
            if ($changed === []) {
                return $this->present($row);
            }

            $claimFields = ['starts_at', 'ends_at', 'student_id', 'instructor_id', 'vehicle_id', 'location_id'];
            if (array_intersect($claimFields, $changed) !== []) {
                $this->claims->replaceForCalendarEvent(
                    $actor['organization_id'],
                    $eventId,
                    $normalized['student_id'],
                    $normalized['instructor_id'],
                    $normalized['vehicle_id'],
                    $normalized['location_id'],
                    $normalized['starts_at'],
                    $normalized['ends_at'],
                );
            }

            $now = now();
            $nextVersion = (int) $row->version + 1;
            DB::table('calendar_events')->where('id', $eventId)->update([
                'name' => $normalized['name'],
                'starts_at' => $normalized['starts_at'],
                'ends_at' => $normalized['ends_at'],
                'student_id' => $normalized['student_id'],
                'instructor_id' => $normalized['instructor_id'],
                'vehicle_id' => $normalized['vehicle_id'],
                'location_id' => $normalized['location_id'],
                'custom_meeting_place' => $normalized['custom_meeting_place'],
                'version' => $nextVersion,
                'updated_at' => $now,
            ]);
            $this->appendLifecycle(
                $actor['organization_id'],
                $eventId,
                'updated',
                'scheduled',
                'scheduled',
                (int) $row->version,
                $nextVersion,
                $actor['user_id'],
                null,
                $changed,
                $now,
            );
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'calendar.event.updated',
                'calendar_event',
                $eventId,
                $requestId,
                ['fields' => $changed, 'state' => 'scheduled'],
                ['fields' => $changed, 'state' => 'scheduled'],
            );

            return $this->present(DB::table('calendar_events')->where('id', $eventId)->firstOrFail());
        });
    }

    /** @return array<string,mixed> */
    public function cancel(
        string $sessionId,
        string $eventId,
        ?string $reason,
        string $requestId,
        ?string $expectedTag,
    ): array {
        return $this->terminal($sessionId, $eventId, 'cancelled', $reason, $requestId, $expectedTag);
    }

    /** @return array<string,mixed> */
    public function complete(string $sessionId, string $eventId, string $requestId, ?string $expectedTag): array
    {
        return $this->terminal($sessionId, $eventId, 'completed', null, $requestId, $expectedTag);
    }

    /** @param array<string,mixed> $event */
    public function etag(array $event): string
    {
        return '"v'.(int) $event['version'].'"';
    }

    /**
     * @return array<string,mixed>
     */
    private function terminal(
        string $sessionId,
        string $eventId,
        string $targetStatus,
        ?string $reason,
        string $requestId,
        ?string $expectedTag,
    ): array {
        $membership = $this->scopeAuthorizer->visibility($sessionId)['membership'];

        return DB::transaction(function () use ($sessionId, $membership, $eventId, $targetStatus, $reason, $requestId, $expectedTag): array {
            $row = $this->lockedEvent($membership['organization_id'], $eventId);
            $this->assertManualEvent($row);
            $this->assertScheduled($row);
            $this->assertVersion($row, $expectedTag);
            $actor = $this->scopeAuthorizer->requireManageTarget(
                $sessionId,
                $row,
                $this->nullableUuid($row->instructor_id),
            );

            $this->claims->releaseCalendarEvent($actor['organization_id'], $eventId);
            $now = now();
            $nextVersion = (int) $row->version + 1;
            $values = [
                'status' => $targetStatus,
                'version' => $nextVersion,
                'updated_at' => $now,
                'completed_at' => null,
                'completed_by_user_id' => null,
                'cancelled_at' => null,
                'cancelled_by_user_id' => null,
                'cancellation_reason' => null,
            ];
            if ($targetStatus === 'completed') {
                $values['completed_at'] = $now;
                $values['completed_by_user_id'] = $actor['user_id'];
            } else {
                $values['cancelled_at'] = $now;
                $values['cancelled_by_user_id'] = $actor['user_id'];
                $values['cancellation_reason'] = $this->nullableText($reason);
            }
            DB::table('calendar_events')->where('id', $eventId)->update($values);

            $eventType = $targetStatus === 'completed' ? 'completed' : 'cancelled';
            $this->appendLifecycle(
                $actor['organization_id'],
                $eventId,
                $eventType,
                'scheduled',
                $targetStatus,
                (int) $row->version,
                $nextVersion,
                $actor['user_id'],
                $targetStatus === 'cancelled' ? $this->nullableText($reason) : null,
                ['status'],
                $now,
            );
            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'calendar.event.'.$eventType,
                'calendar_event',
                $eventId,
                $requestId,
                ['fields' => ['status'], 'state' => 'scheduled'],
                ['fields' => ['status'], 'state' => $targetStatus],
                $targetStatus === 'cancelled' ? $this->nullableText($reason) : null,
            );

            return $this->present(DB::table('calendar_events')->where('id', $eventId)->firstOrFail());
        });
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{name:?string,starts_at:string,ends_at:string,student_id:?string,instructor_id:?string,vehicle_id:?string,location_id:?string,custom_meeting_place:?string}
     */
    private function normalizeInput(array $input, ?\stdClass $current): array
    {
        $eventType = array_key_exists('event_type', $input)
            ? (string) $input['event_type']
            : ($current === null ? '' : (string) $current->event_type);
        if ($eventType !== 'general_event') {
            throw ResourceDomainException::rule('Formal driving lessons are owned by TrainingSession and cannot be persisted as CalendarEvent rows.');
        }

        $startsAt = array_key_exists('starts_at', $input) ? (string) $input['starts_at'] : (string) $current?->starts_at;
        $endsAt = array_key_exists('ends_at', $input) ? (string) $input['ends_at'] : (string) $current?->ends_at;
        [$startsAt, $endsAt] = $this->interval($startsAt, $endsAt);

        $locationId = array_key_exists('location_id', $input)
            ? $this->nullableUuid($input['location_id'])
            : $this->nullableUuid($current?->location_id);
        $customPlace = array_key_exists('custom_meeting_place', $input)
            ? $this->nullableText($input['custom_meeting_place'])
            : $this->nullableText($current?->custom_meeting_place);
        if ($locationId !== null && $customPlace !== null) {
            throw ResourceDomainException::rule('Saved location and custom meeting place are mutually exclusive.');
        }

        return [
            'name' => array_key_exists('name', $input) ? $this->nullableText($input['name']) : $this->nullableText($current?->name),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'student_id' => array_key_exists('student_id', $input) ? $this->nullableUuid($input['student_id']) : $this->nullableUuid($current?->student_id),
            'instructor_id' => array_key_exists('instructor_id', $input) ? $this->nullableUuid($input['instructor_id']) : $this->nullableUuid($current?->instructor_id),
            'vehicle_id' => array_key_exists('vehicle_id', $input) ? $this->nullableUuid($input['vehicle_id']) : $this->nullableUuid($current?->vehicle_id),
            'location_id' => $locationId,
            'custom_meeting_place' => $customPlace,
        ];
    }

    /** @return array{0:string,1:string} */
    private function interval(string $rawStart, string $rawEnd): array
    {
        try {
            $start = CarbonImmutable::parse($rawStart);
            $end = CarbonImmutable::parse($rawEnd);
        } catch (\Throwable) {
            throw ResourceDomainException::rule('Calendar event times must be valid date-time values.');
        }
        if (! $end->greaterThan($start)) {
            throw ResourceDomainException::rule('Calendar event end must be after start.');
        }

        return [$start->toIso8601String(), $end->toIso8601String()];
    }

    /**
     * @param  array{name:?string,starts_at:string,ends_at:string,student_id:?string,instructor_id:?string,vehicle_id:?string,location_id:?string,custom_meeting_place:?string}  $normalized
     */
    private function assertResources(string $organizationId, array $normalized): void
    {
        foreach ([
            'student_id' => ['students', 'Student'],
            'instructor_id' => ['staff_profiles', 'Instructor'],
            'vehicle_id' => ['vehicles', 'Vehicle'],
            'location_id' => ['locations', 'Location'],
        ] as $field => [$table, $label]) {
            $id = $normalized[$field];
            if ($id === null) {
                continue;
            }
            if (! DB::table($table)->where('organization_id', $organizationId)->where('id', $id)->exists()) {
                throw ResourceDomainException::rule($label.' must belong to the active organization.');
            }
        }
    }

    private function lockedEvent(string $organizationId, string $eventId): \stdClass
    {
        $row = DB::table('calendar_events')
            ->where('organization_id', $organizationId)
            ->where('id', $eventId)
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $row;
    }

    private function assertManualEvent(\stdClass $row): void
    {
        if ((string) $row->event_type !== 'general_event') {
            throw ResourceDomainException::notFound();
        }
    }

    private function assertScheduled(\stdClass $row): void
    {
        if ((string) $row->status !== 'scheduled') {
            throw ResourceDomainException::conflict('Only a scheduled CalendarEvent may be mutated.');
        }
    }

    private function assertVersion(\stdClass $row, ?string $expectedTag): void
    {
        if ($expectedTag === null || trim($expectedTag) === '') {
            throw new ResourceDomainException('PRECONDITION_REQUIRED', 428, 'If-Match with current CalendarEvent version is required.');
        }
        $normalized = trim(trim($expectedTag), '"');
        $normalized = str_starts_with($normalized, 'v') ? substr($normalized, 1) : $normalized;
        if (! ctype_digit($normalized) || (int) $normalized !== (int) $row->version) {
            throw ResourceDomainException::conflict('CalendarEvent changed since it was loaded.');
        }
    }

    /**
     * @param  array{name:?string,starts_at:string,ends_at:string,student_id:?string,instructor_id:?string,vehicle_id:?string,location_id:?string,custom_meeting_place:?string}  $normalized
     * @return list<string>
     */
    private function changedFields(\stdClass $row, array $normalized): array
    {
        $changed = [];
        foreach (['starts_at', 'ends_at'] as $field) {
            try {
                if (! CarbonImmutable::parse((string) $row->{$field})->equalTo(CarbonImmutable::parse($normalized[$field]))) {
                    $changed[] = $field;
                }
            } catch (\Throwable) {
                $changed[] = $field;
            }
        }
        foreach (['name', 'student_id', 'instructor_id', 'vehicle_id', 'location_id', 'custom_meeting_place'] as $field) {
            $before = $field === 'name' || $field === 'custom_meeting_place'
                ? $this->nullableText($row->{$field})
                : $this->nullableUuid($row->{$field});
            if ($before !== $normalized[$field]) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * @param  array{from?:?string,to?:?string,student_id?:?string,staff_id?:?string,vehicle_id?:?string,location_id?:?string,event_type?:list<string>}  $filters
     */
    private function applyRangeAndResourceFilters(Builder $query, array $filters): void
    {
        if (($filters['from'] ?? null) !== null) {
            $query->where('ends_at', '>', CarbonImmutable::parse((string) $filters['from'])->toIso8601String());
        }
        if (($filters['to'] ?? null) !== null) {
            $query->where('starts_at', '<', CarbonImmutable::parse((string) $filters['to'])->toIso8601String());
        }
        foreach ([
            'student_id' => 'student_id',
            'staff_id' => 'instructor_id',
            'vehicle_id' => 'vehicle_id',
            'location_id' => 'location_id',
        ] as $filter => $column) {
            if (($filters[$filter] ?? null) !== null) {
                $query->where($column, $filters[$filter]);
            }
        }
    }

    /**
     * @param  list<string>  $changedFields
     */
    private function appendLifecycle(
        string $organizationId,
        string $eventId,
        string $eventType,
        ?string $fromStatus,
        string $toStatus,
        ?int $versionBefore,
        int $versionAfter,
        string $actorUserId,
        ?string $reason,
        array $changedFields,
        mixed $occurredAt,
    ): void {
        DB::table('calendar_event_lifecycle_events')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organizationId,
            'calendar_event_id' => $eventId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'event_version_before' => $versionBefore,
            'event_version_after' => $versionAfter,
            'actor_user_id' => $actorUserId,
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
            'source_kind' => 'calendar_event',
            'source_id' => (string) $row->id,
            'course_enrollment_id' => null,
            'event_type' => (string) $row->event_type,
            'name' => $this->nullableText($row->name),
            'starts_at' => (string) $row->starts_at,
            'ends_at' => (string) $row->ends_at,
            'student_id' => $this->nullableUuid($row->student_id),
            'instructor_id' => $this->nullableUuid($row->instructor_id),
            'vehicle_id' => $this->nullableUuid($row->vehicle_id),
            'location_id' => $this->nullableUuid($row->location_id),
            'custom_meeting_place' => $this->nullableText($row->custom_meeting_place),
            'status' => (string) $row->status,
            'version' => (int) $row->version,
        ];
    }
}
