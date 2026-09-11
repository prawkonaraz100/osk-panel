<?php

namespace App\Modules\InternalExams;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * @phpstan-type StationRow object{id:mixed,organization_id:mixed,administrative_status:mixed,last_authenticated_heartbeat_at:mixed,created_at:mixed,updated_at:mixed}
 */
final class ExamStationService
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ExamStationCredentialService $credentials,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(string $sessionId): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $actor = $this->tenantAuthorizer->requireOrganizationPermission(
            $sessionId,
            $snapshot['organization_id'],
            'exams.stations.view',
        );
        $now = CarbonImmutable::now();

        return array_values(
            DB::table('exam_stations')
                ->where('organization_id', $actor['organization_id'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => $this->present($actor['organization_id'], $row, $now))
                ->all(),
        );
    }

    /** @return array<string,mixed> */
    public function register(string $sessionId, string $requestId): array
    {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $requestId): array {
            DB::table('organizations')
                ->where('id', $snapshot['organization_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission(
                $sessionId,
                $snapshot['organization_id'],
                'exams.stations.manage',
            );

            $stationId = (string) Str::uuid7();
            $now = CarbonImmutable::now();
            DB::table('exam_stations')->insert([
                'id' => $stationId,
                'organization_id' => $actor['organization_id'],
                'administrative_status' => 'enabled',
                'last_authenticated_heartbeat_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $issued = $this->credentials->issue(
                $actor['organization_id'],
                $stationId,
                $actor['user_id'],
                $now,
            );

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'internal_exam.station.registered',
                'exam_station',
                $stationId,
                $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['administrative_status', 'station_authentication'], 'state' => 'enabled'],
            );

            $row = DB::table('exam_stations')->where('id', $stationId)->firstOrFail();

            return [
                ...$this->present($actor['organization_id'], $row, $now),
                'credential_sequence' => $issued['credential_sequence'],
                'one_time_station_credential' => $issued['raw_credential'],
            ];
        });
    }

    /** @return array<string,mixed> */
    public function provisionCredential(
        string $sessionId,
        string $stationId,
        string $requestId,
    ): array {
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $stationId, $requestId): array {
            DB::table('organizations')
                ->where('id', $snapshot['organization_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission(
                $sessionId,
                $snapshot['organization_id'],
                'exams.stations.manage',
            );
            $this->requireStation($actor['organization_id'], $stationId);

            $now = CarbonImmutable::now();
            $issued = $this->credentials->issue(
                $actor['organization_id'],
                $stationId,
                $actor['user_id'],
                $now,
            );

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'internal_exam.station_credential.provisioned',
                'exam_station',
                $stationId,
                $requestId,
                ['fields' => ['station_authentication'], 'state' => 'unprovisioned'],
                ['fields' => ['station_authentication'], 'state' => 'provisioned'],
            );

            $row = DB::table('exam_stations')->where('id', $stationId)->firstOrFail();

            return [
                ...$this->present($actor['organization_id'], $row, $now),
                'credential_sequence' => $issued['credential_sequence'],
                'one_time_station_credential' => $issued['raw_credential'],
            ];
        });
    }

    /** @return array<string,mixed> */
    public function rotateCredential(
        string $sessionId,
        string $stationId,
        string $reason,
        string $requestId,
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw ResourceDomainException::rule('Station credential rotation reason is required.');
        }
        $snapshot = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($sessionId, $snapshot, $stationId, $reason, $requestId): array {
            DB::table('organizations')
                ->where('id', $snapshot['organization_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $actor = $this->tenantAuthorizer->requireOrganizationPermission(
                $sessionId,
                $snapshot['organization_id'],
                'exams.stations.manage',
            );
            $this->requireStation($actor['organization_id'], $stationId);

            $now = CarbonImmutable::now();
            $issued = $this->credentials->rotate(
                $actor['organization_id'],
                $stationId,
                $actor['user_id'],
                'operator_rotation',
                $now,
            );

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'internal_exam.station_credential.rotated',
                'exam_station',
                $stationId,
                $requestId,
                ['fields' => ['station_authentication'], 'state' => 'provisioned'],
                ['fields' => ['station_authentication'], 'state' => 'rotated'],
                $reason,
            );

            $row = DB::table('exam_stations')->where('id', $stationId)->firstOrFail();

            return [
                ...$this->present($actor['organization_id'], $row, $now),
                'credential_sequence' => $issued['credential_sequence'],
                'one_time_station_credential' => $issued['raw_credential'],
            ];
        });
    }

    /** @return StationRow */
    private function requireStation(string $organizationId, string $stationId): object
    {
        /** @var StationRow|null $row */
        $row = DB::table('exam_stations')
            ->where('organization_id', $organizationId)
            ->where('id', $stationId)
            ->first();
        if ($row === null) {
            throw ResourceDomainException::notFound('Exam station not found.');
        }

        return $row;
    }

    /**
     * @param  StationRow  $row
     * @return array<string,mixed>
     */
    private function present(string $organizationId, object $row, CarbonImmutable $now): array
    {
        $lastHeartbeat = $row->last_authenticated_heartbeat_at === null
            ? null
            : CarbonImmutable::parse((string) $row->last_authenticated_heartbeat_at);
        $online = $lastHeartbeat !== null
            && $lastHeartbeat->addSeconds($this->heartbeatFreshSeconds())->gt($now);
        $occupied = DB::table('internal_exam_station_sessions')
            ->where('organization_id', $organizationId)
            ->where('exam_station_id', $row->id)
            ->whereNull('ended_at')
            ->exists();
        $hasCurrentCredential = DB::table('exam_station_credentials')
            ->where('organization_id', $organizationId)
            ->where('exam_station_id', $row->id)
            ->whereNull('revoked_at')
            ->exists();
        $enabled = (string) $row->administrative_status === 'enabled';

        return [
            'id' => (string) $row->id,
            'administrative_status' => (string) $row->administrative_status,
            'connectivity' => $online ? 'online' : 'offline',
            'occupancy' => $occupied ? 'occupied' : 'free',
            'has_current_credential' => $hasCurrentCredential,
            'available_for_new_execution' => $enabled && $online && ! $occupied && $hasCurrentCredential,
            'last_authenticated_heartbeat_at' => $lastHeartbeat?->toIso8601String(),
            'created_at' => (string) $row->created_at,
        ];
    }

    private function heartbeatFreshSeconds(): int
    {
        $seconds = config('internal_exams.station_heartbeat_fresh_seconds');
        if (! is_numeric($seconds) || (int) $seconds < 1) {
            throw new LogicException('INTERNAL_EXAM_STATION_HEARTBEAT_FRESH_SECONDS must be a positive integer.');
        }

        return (int) $seconds;
    }
}
