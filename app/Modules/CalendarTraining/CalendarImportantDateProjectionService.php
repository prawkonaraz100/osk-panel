<?php

namespace App\Modules\CalendarTraining;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CalendarImportantDateProjectionService
{
    private const PRODUCT_TIMEZONE = 'Europe/Warsaw';

    /** @var array<string,array{kind:string,label:string}> */
    private const STAFF_DOCUMENTS = [
        'card_or_authorization' => [
            'kind' => 'staff_document_expiry',
            'label' => 'Ważność legitymacji lub uprawnienia',
        ],
        'medical_exam' => [
            'kind' => 'staff_medical_expiry',
            'label' => 'Ważność badania lekarskiego',
        ],
        'psychological_exam' => [
            'kind' => 'staff_psychological_expiry',
            'label' => 'Ważność badania psychologicznego',
        ],
    ];

    /** @var array<string,array{kind:string,label:string}> */
    private const VEHICLE_DOCUMENTS = [
        'technical_inspection' => [
            'kind' => 'vehicle_inspection_expiry',
            'label' => 'Termin badania technicznego',
        ],
        'oc_insurance' => [
            'kind' => 'vehicle_oc_expiry',
            'label' => 'Ważność polisy OC',
        ],
        'ac_insurance' => [
            'kind' => 'vehicle_ac_expiry',
            'label' => 'Ważność polisy AC',
        ],
    ];

    public function __construct(private readonly CalendarEventScopeAuthorizer $scopeAuthorizer) {}

    /**
     * @param  array{from?:?string,to?:?string,student_id?:?string,staff_id?:?string,vehicle_id?:?string,location_id?:?string,event_type?:list<string>}  $filters
     * @return list<array<string,mixed>>
     */
    public function list(string $sessionId, array $filters): array
    {
        if (isset($filters['event_type']) && ! in_array('important_date', $filters['event_type'], true)) {
            return [];
        }
        if (($filters['student_id'] ?? null) !== null) {
            return [];
        }

        $visibility = $this->scopeAuthorizer->visibility($sessionId);
        $items = [];

        if (($filters['vehicle_id'] ?? null) === null) {
            foreach ($this->staffRows($visibility, $filters) as $row) {
                $item = $this->staffProjection($row);
                if ($this->overlapsRange($item, $filters)) {
                    $items[] = $item;
                }
            }
        }

        if (($filters['staff_id'] ?? null) === null) {
            foreach ($this->vehicleRows($visibility, $filters) as $row) {
                $item = $this->vehicleProjection($row);
                if ($this->overlapsRange($item, $filters)) {
                    $items[] = $item;
                }
            }
        }

        usort($items, static function (array $left, array $right): int {
            $time = strcmp((string) $left['starts_at'], (string) $right['starts_at']);

            return $time !== 0 ? $time : strcmp((string) $left['id'], (string) $right['id']);
        });

        return $items;
    }

    /**
     * @param  array{
     *   membership:array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int},
     *   unrestricted:bool,
     *   own_instructor_id:?string,
     *   assigned_student_ids:list<string>,
     *   assigned_location_ids:list<string>
     * }  $visibility
     * @param  array{from?:?string,to?:?string,student_id?:?string,staff_id?:?string,vehicle_id?:?string,location_id?:?string,event_type?:list<string>} $filters
     * @return list<\stdClass>
     */
    private function staffRows(array $visibility, array $filters): array
    {
        $query = DB::table('staff_documents as d')
            ->join('staff_profiles as s', function ($join): void {
                $join->on('s.id', '=', 'd.staff_profile_id')
                    ->on('s.organization_id', '=', 'd.organization_id');
            })
            ->where('d.organization_id', $visibility['membership']['organization_id'])
            ->whereNull('d.superseded_at')
            ->whereNotNull('d.valid_until')
            ->whereNull('s.archived_at')
            ->whereIn('d.document_type', array_keys(self::STAFF_DOCUMENTS))
            ->select([
                'd.id',
                'd.organization_id',
                'd.staff_profile_id',
                'd.document_type',
                'd.valid_until',
            ]);

        if (($filters['staff_id'] ?? null) !== null) {
            $query->where('d.staff_profile_id', $filters['staff_id']);
        }
        if (($filters['location_id'] ?? null) !== null) {
            $this->whereStaffAssignedToLocation($query, (string) $filters['location_id']);
        }

        if (! $visibility['unrestricted']) {
            $query->where(function (Builder $scope) use ($visibility): void {
                $hasClause = false;
                if ($visibility['own_instructor_id'] !== null) {
                    $scope->where('d.staff_profile_id', $visibility['own_instructor_id']);
                    $hasClause = true;
                }
                if ($visibility['assigned_location_ids'] !== []) {
                    $callback = function ($assignment) use ($visibility): void {
                        $assignment->selectRaw('1')
                            ->from('staff_location_assignments as sla')
                            ->whereColumn('sla.organization_id', 'd.organization_id')
                            ->whereColumn('sla.staff_profile_id', 'd.staff_profile_id')
                            ->whereIn('sla.location_id', $visibility['assigned_location_ids']);
                    };
                    if ($hasClause) {
                        $scope->orWhereExists($callback);
                    } else {
                        $scope->whereExists($callback);
                    }
                }
            });
        }

        return array_values($query->get()->all());
    }

    /**
     * @param  array{
     *   membership:array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int},
     *   unrestricted:bool,
     *   own_instructor_id:?string,
     *   assigned_student_ids:list<string>,
     *   assigned_location_ids:list<string>
     * }  $visibility
     * @param  array{from?:?string,to?:?string,student_id?:?string,staff_id?:?string,vehicle_id?:?string,location_id?:?string,event_type?:list<string>} $filters
     * @return list<\stdClass>
     */
    private function vehicleRows(array $visibility, array $filters): array
    {
        if (! $visibility['unrestricted'] && $visibility['assigned_location_ids'] === []) {
            return [];
        }

        $query = DB::table('vehicle_documents as d')
            ->join('vehicles as v', function ($join): void {
                $join->on('v.id', '=', 'd.vehicle_id')
                    ->on('v.organization_id', '=', 'd.organization_id');
            })
            ->where('d.organization_id', $visibility['membership']['organization_id'])
            ->whereNull('d.superseded_at')
            ->whereNotNull('d.valid_until')
            ->whereNull('v.archived_at')
            ->whereIn('d.document_type', array_keys(self::VEHICLE_DOCUMENTS))
            ->select([
                'd.id',
                'd.organization_id',
                'd.vehicle_id',
                'd.document_type',
                'd.valid_until',
            ]);

        if (($filters['vehicle_id'] ?? null) !== null) {
            $query->where('d.vehicle_id', $filters['vehicle_id']);
        }
        if (($filters['location_id'] ?? null) !== null) {
            $this->whereVehicleAssignedToLocation($query, (string) $filters['location_id']);
        }

        if (! $visibility['unrestricted']) {
            $query->whereExists(function ($assignment) use ($visibility): void {
                $assignment->selectRaw('1')
                    ->from('vehicle_location_assignments as vla')
                    ->whereColumn('vla.organization_id', 'd.organization_id')
                    ->whereColumn('vla.vehicle_id', 'd.vehicle_id')
                    ->whereIn('vla.location_id', $visibility['assigned_location_ids']);
            });
        }

        return array_values($query->get()->all());
    }

    private function whereStaffAssignedToLocation(Builder $query, string $locationId): void
    {
        $query->whereExists(function ($assignment) use ($locationId): void {
            $assignment->selectRaw('1')
                ->from('staff_location_assignments as sla')
                ->whereColumn('sla.organization_id', 'd.organization_id')
                ->whereColumn('sla.staff_profile_id', 'd.staff_profile_id')
                ->where('sla.location_id', $locationId);
        });
    }

    private function whereVehicleAssignedToLocation(Builder $query, string $locationId): void
    {
        $query->whereExists(function ($assignment) use ($locationId): void {
            $assignment->selectRaw('1')
                ->from('vehicle_location_assignments as vla')
                ->whereColumn('vla.organization_id', 'd.organization_id')
                ->whereColumn('vla.vehicle_id', 'd.vehicle_id')
                ->where('vla.location_id', $locationId);
        });
    }

    /** @return array<string,mixed> */
    private function staffProjection(\stdClass $row): array
    {
        $definition = self::STAFF_DOCUMENTS[(string) $row->document_type];
        [$startsAt, $endsAt, $sourceDate] = $this->allDayInterval((string) $row->valid_until);

        return [
            'id' => (string) $row->id,
            'source_kind' => 'staff_document',
            'source_id' => (string) $row->id,
            'course_enrollment_id' => null,
            'event_type' => 'important_date',
            'name' => $definition['label'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'student_id' => null,
            'instructor_id' => (string) $row->staff_profile_id,
            'vehicle_id' => null,
            'location_id' => null,
            'custom_meeting_place' => null,
            'status' => 'scheduled',
            'version' => 1,
            'all_day' => true,
            'source_date' => $sourceDate,
            'important_date_kind' => $definition['kind'],
        ];
    }

    /** @return array<string,mixed> */
    private function vehicleProjection(\stdClass $row): array
    {
        $definition = self::VEHICLE_DOCUMENTS[(string) $row->document_type];
        [$startsAt, $endsAt, $sourceDate] = $this->allDayInterval((string) $row->valid_until);

        return [
            'id' => (string) $row->id,
            'source_kind' => 'vehicle_document',
            'source_id' => (string) $row->id,
            'course_enrollment_id' => null,
            'event_type' => 'important_date',
            'name' => $definition['label'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'student_id' => null,
            'instructor_id' => null,
            'vehicle_id' => (string) $row->vehicle_id,
            'location_id' => null,
            'custom_meeting_place' => null,
            'status' => 'scheduled',
            'version' => 1,
            'all_day' => true,
            'source_date' => $sourceDate,
            'important_date_kind' => $definition['kind'],
        ];
    }

    /** @return array{0:string,1:string,2:string} */
    private function allDayInterval(string $sourceDate): array
    {
        $start = CarbonImmutable::parse($sourceDate, self::PRODUCT_TIMEZONE)->startOfDay();
        $end = $start->addDay();

        return [$start->toIso8601String(), $end->toIso8601String(), $start->toDateString()];
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array{from?:?string,to?:?string,student_id?:?string,staff_id?:?string,vehicle_id?:?string,location_id?:?string,event_type?:list<string>} $filters
     */
    private function overlapsRange(array $item, array $filters): bool
    {
        $start = CarbonImmutable::parse((string) $item['starts_at']);
        $end = CarbonImmutable::parse((string) $item['ends_at']);

        if (($filters['from'] ?? null) !== null
            && $end->lessThanOrEqualTo(CarbonImmutable::parse((string) $filters['from']))) {
            return false;
        }
        if (($filters['to'] ?? null) !== null
            && $start->greaterThanOrEqualTo(CarbonImmutable::parse((string) $filters['to']))) {
            return false;
        }

        return true;
    }
}
