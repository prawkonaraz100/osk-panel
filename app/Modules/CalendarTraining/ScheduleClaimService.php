<?php

namespace App\Modules\CalendarTraining;

use App\Modules\ResourcesCore\ResourceDomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class ScheduleClaimService
{
    private const RESOURCE_COLUMNS = [
        'student' => 'student_id',
        'instructor' => 'instructor_id',
        'vehicle' => 'vehicle_id',
        'location' => 'location_id',
    ];

    private const RESOURCE_ORDER = [
        'student' => 0,
        'instructor' => 1,
        'vehicle' => 2,
        'location' => 3,
    ];

    public function replaceForTrainingSession(
        string $organizationId,
        string $trainingSessionId,
        string $studentId,
        string $instructorId,
        ?string $vehicleId,
        ?string $locationId,
        string $startsAt,
        string $endsAt,
    ): void {
        $this->replace(
            $organizationId,
            'training_session',
            $trainingSessionId,
            $this->desiredResources($studentId, $instructorId, $vehicleId, $locationId),
            $startsAt,
            $endsAt,
        );
    }

    public function releaseTrainingSession(string $organizationId, string $trainingSessionId): void
    {
        $this->release($organizationId, 'training_session', $trainingSessionId);
    }

    public function replaceForCalendarEvent(
        string $organizationId,
        string $eventId,
        ?string $studentId,
        ?string $instructorId,
        ?string $vehicleId,
        ?string $locationId,
        string $startsAt,
        string $endsAt,
    ): void {
        $this->replace(
            $organizationId,
            'calendar_event',
            $eventId,
            $this->desiredResources($studentId, $instructorId, $vehicleId, $locationId),
            $startsAt,
            $endsAt,
        );
    }

    public function releaseCalendarEvent(string $organizationId, string $eventId): void
    {
        $this->release($organizationId, 'calendar_event', $eventId);
    }

    public function replaceForAvailabilityBooking(
        string $organizationId,
        string $slotId,
        string $studentId,
        ?string $instructorId,
        ?string $vehicleId,
        ?string $locationId,
        string $startsAt,
        string $endsAt,
    ): void {
        $this->replace(
            $organizationId,
            'availability_slot_booking',
            $slotId,
            $this->desiredResources($studentId, $instructorId, $vehicleId, $locationId),
            $startsAt,
            $endsAt,
        );
    }

    public function releaseAvailabilityBooking(string $organizationId, string $slotId): void
    {
        $this->release($organizationId, 'availability_slot_booking', $slotId);
    }

    public function transferAvailabilityBookingToTrainingSession(
        string $organizationId,
        string $slotId,
        string $trainingSessionId,
        string $studentId,
        string $instructorId,
        ?string $vehicleId,
        ?string $locationId,
        string $startsAt,
        string $endsAt,
    ): void {
        $this->assertTransaction();

        $desired = $this->desiredResources($studentId, $instructorId, $vehicleId, $locationId);
        $existingRows = DB::table('calendar_resource_claims')
            ->where('organization_id', $organizationId)
            ->where('claim_owner_kind', 'availability_slot_booking')
            ->where('claim_owner_id', $slotId)
            ->get();
        $existing = $this->resourcesFromClaims($existingRows->all());
        $matchingIntervalCount = DB::table('calendar_resource_claims')
            ->where('organization_id', $organizationId)
            ->where('claim_owner_kind', 'availability_slot_booking')
            ->where('claim_owner_id', $slotId)
            ->whereRaw(
                'starts_at = CAST(? AS timestamptz) AND ends_at = CAST(? AS timestamptz)',
                [$startsAt, $endsAt],
            )
            ->count();

        if (count($existing) !== count($desired)
            || $matchingIntervalCount !== count($desired)
            || $this->resourceKeys($existing) !== $this->resourceKeys($desired)) {
            throw ResourceDomainException::conflict(
                'AvailabilitySlot booking claims do not match the reservation being formalized.',
            );
        }

        $this->release($organizationId, 'availability_slot_booking', $slotId);
        $this->replace(
            $organizationId,
            'training_session',
            $trainingSessionId,
            $desired,
            $startsAt,
            $endsAt,
        );
    }

    /**
     * @param  list<array{kind:string,id:string}>  $desired
     */
    private function replace(
        string $organizationId,
        string $ownerKind,
        string $ownerId,
        array $desired,
        string $startsAt,
        string $endsAt,
    ): void {
        $this->assertTransaction();

        $existingRows = DB::table('calendar_resource_claims')
            ->where('organization_id', $organizationId)
            ->where('claim_owner_kind', $ownerKind)
            ->where('claim_owner_id', $ownerId)
            ->get();
        $existing = $this->resourcesFromClaims($existingRows->all());

        $this->lockResources($organizationId, [...$existing, ...$desired]);

        foreach ($desired as $resource) {
            $column = self::RESOURCE_COLUMNS[$resource['kind']];
            $conflict = DB::table('calendar_resource_claims')
                ->where('organization_id', $organizationId)
                ->where($column, $resource['id'])
                ->where(function ($query) use ($ownerKind, $ownerId): void {
                    $query->where('claim_owner_kind', '!=', $ownerKind)
                        ->orWhere('claim_owner_id', '!=', $ownerId);
                })
                ->whereRaw(
                    "tstzrange(starts_at, ends_at, '[)') && tstzrange(CAST(? AS timestamptz), CAST(? AS timestamptz), '[)')",
                    [$startsAt, $endsAt],
                )
                ->exists();

            if ($conflict) {
                throw new ResourceDomainException(
                    'CALENDAR_RESOURCE_CONFLICT',
                    409,
                    ucfirst($resource['kind']).' is already occupied in the requested time range.',
                );
            }
        }

        DB::table('calendar_resource_claims')
            ->where('organization_id', $organizationId)
            ->where('claim_owner_kind', $ownerKind)
            ->where('claim_owner_id', $ownerId)
            ->delete();

        $now = now();
        foreach ($desired as $resource) {
            $row = [
                'id' => (string) Str::uuid7(),
                'organization_id' => $organizationId,
                'claim_owner_kind' => $ownerKind,
                'claim_owner_id' => $ownerId,
                'student_id' => null,
                'instructor_id' => null,
                'vehicle_id' => null,
                'location_id' => null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'created_at' => $now,
            ];
            $row[self::RESOURCE_COLUMNS[$resource['kind']]] = $resource['id'];
            DB::table('calendar_resource_claims')->insert($row);
        }
    }

    private function release(string $organizationId, string $ownerKind, string $ownerId): void
    {
        $this->assertTransaction();

        $rows = DB::table('calendar_resource_claims')
            ->where('organization_id', $organizationId)
            ->where('claim_owner_kind', $ownerKind)
            ->where('claim_owner_id', $ownerId)
            ->get();

        $this->lockResources($organizationId, $this->resourcesFromClaims($rows->all()));

        DB::table('calendar_resource_claims')
            ->where('organization_id', $organizationId)
            ->where('claim_owner_kind', $ownerKind)
            ->where('claim_owner_id', $ownerId)
            ->delete();
    }

    /**
     * @return list<array{kind:string,id:string}>
     */
    private function desiredResources(
        ?string $studentId,
        ?string $instructorId,
        ?string $vehicleId,
        ?string $locationId,
    ): array {
        $resources = [];
        if ($studentId !== null) {
            $resources[] = ['kind' => 'student', 'id' => $studentId];
        }
        if ($instructorId !== null) {
            $resources[] = ['kind' => 'instructor', 'id' => $instructorId];
        }
        if ($vehicleId !== null) {
            $resources[] = ['kind' => 'vehicle', 'id' => $vehicleId];
        }
        if ($locationId !== null) {
            $resources[] = ['kind' => 'location', 'id' => $locationId];
        }

        return $resources;
    }

    /**
     * @param  array<int, \stdClass>  $rows
     * @return list<array{kind:string,id:string}>
     */
    private function resourcesFromClaims(array $rows): array
    {
        $resources = [];
        foreach ($rows as $row) {
            foreach (self::RESOURCE_COLUMNS as $kind => $column) {
                $value = $row->{$column} ?? null;
                if (is_string($value) && $value !== '') {
                    $resources[] = ['kind' => $kind, 'id' => $value];
                }
            }
        }

        return $resources;
    }

    /**
     * @param  list<array{kind:string,id:string}>  $resources
     * @return list<string>
     */
    private function resourceKeys(array $resources): array
    {
        $keys = array_map(
            static fn (array $resource): string => $resource['kind'].'|'.$resource['id'],
            $resources,
        );
        sort($keys);

        return array_values(array_unique($keys));
    }

    /**
     * @param  list<array{kind:string,id:string}>  $resources
     */
    private function lockResources(string $organizationId, array $resources): void
    {
        $unique = [];
        foreach ($resources as $resource) {
            $unique[$resource['kind'].'|'.$resource['id']] = $resource;
        }
        $resources = array_values($unique);

        usort($resources, static function (array $left, array $right): int {
            $rank = self::RESOURCE_ORDER[$left['kind']] <=> self::RESOURCE_ORDER[$right['kind']];

            return $rank !== 0 ? $rank : strcmp($left['id'], $right['id']);
        });

        foreach ($resources as $resource) {
            $key = 'calendar-resource|'.$organizationId.'|'.$resource['kind'].'|'.$resource['id'];
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        }
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Schedule claims must be changed inside a PostgreSQL business transaction.');
        }
    }
}
