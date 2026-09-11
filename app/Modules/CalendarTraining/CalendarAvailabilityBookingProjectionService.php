<?php

namespace App\Modules\CalendarTraining;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CalendarAvailabilityBookingProjectionService
{
    public function __construct(private readonly CalendarEventScopeAuthorizer $scopeAuthorizer) {}

    /**
     * @param  array{from?:?string,to?:?string,student_id?:?string,staff_id?:?string,vehicle_id?:?string,location_id?:?string,event_type?:list<string>}  $filters
     * @return list<array<string,mixed>>
     */
    public function list(string $sessionId, array $filters): array
    {
        if (isset($filters['event_type']) && ! in_array('driving_lesson', $filters['event_type'], true)) {
            return [];
        }

        $visibility = $this->scopeAuthorizer->visibility($sessionId);
        $query = DB::table('availability_slots')
            ->where('organization_id', $visibility['membership']['organization_id'])
            ->where('status', 'booked')
            ->whereNull('training_session_id')
            ->whereNotNull('booked_student_id');

        $this->applyFilters($query, $filters);

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
     * @param  array{from?:?string,to?:?string,student_id?:?string,staff_id?:?string,vehicle_id?:?string,location_id?:?string,event_type?:list<string>}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (($filters['from'] ?? null) !== null) {
            $query->where('ends_at', '>', CarbonImmutable::parse((string) $filters['from'])->toIso8601String());
        }
        if (($filters['to'] ?? null) !== null) {
            $query->where('starts_at', '<', CarbonImmutable::parse((string) $filters['to'])->toIso8601String());
        }
        foreach ([
            'student_id' => 'booked_student_id',
            'staff_id' => 'instructor_id',
            'vehicle_id' => 'vehicle_id',
            'location_id' => 'location_id',
        ] as $filter => $column) {
            if (($filters[$filter] ?? null) !== null) {
                $query->where($column, $filters[$filter]);
            }
        }
    }

    /** @return array<string,mixed> */
    private function present(\stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'source_kind' => 'availability_slot_booking',
            'source_id' => (string) $row->id,
            'course_enrollment_id' => null,
            'event_type' => 'driving_lesson',
            'name' => null,
            'starts_at' => (string) $row->starts_at,
            'ends_at' => (string) $row->ends_at,
            'student_id' => (string) $row->booked_student_id,
            'instructor_id' => $this->nullableUuid($row->instructor_id),
            'vehicle_id' => $this->nullableUuid($row->vehicle_id),
            'location_id' => $this->nullableUuid($row->location_id),
            'custom_meeting_place' => null,
            'status' => 'booked',
            'version' => (int) $row->version,
        ];
    }

    private function nullableUuid(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
