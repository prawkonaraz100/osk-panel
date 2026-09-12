<?php

namespace App\Modules\CommerceDashboard;

use App\Modules\AuditNotification\ActivityNotificationService;
use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use Illuminate\Support\Facades\DB;

final class DashboardService
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly ActivityNotificationService $activityNotifications,
    ) {}

    /** @return array<string,mixed> */
    public function get(string $sessionId): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $organizationId = $membership['organization_id'];

        return [
            'licenses' => $this->licenseSummary($organizationId),
            'internal_exams' => $this->internalExamSummary($organizationId),
            'activity' => $this->activityNotifications->listActivity($sessionId, 1, 8, [])['data'],
            'calendar' => $this->calendarSummary($organizationId),
        ];
    }

    /** @return array{active_count:int,available_count:int} */
    private function licenseSummary(string $organizationId): array
    {
        $effectiveAt = now();

        $available = DB::table('license_inventory_entries')
            ->where('organization_id', $organizationId)
            ->where('status', 'available')
            ->count();

        $active = DB::table('license_inventory_entries as li')
            ->join('license_assignments as la', function ($join): void {
                $join->on('la.organization_id', '=', 'li.organization_id')
                    ->on('la.license_inventory_entry_id', '=', 'li.id');
            })
            ->join('license_activations as ac', function ($join): void {
                $join->on('ac.organization_id', '=', 'la.organization_id')
                    ->on('ac.license_assignment_id', '=', 'la.id');
            })
            ->where('li.organization_id', $organizationId)
            ->where('la.status', 'activated')
            ->where('ac.effective_to', '>', $effectiveAt)
            ->count();

        return [
            'active_count' => $active,
            'available_count' => $available,
        ];
    }

    /** @return array{available_count:int} */
    private function internalExamSummary(string $organizationId): array
    {
        $ledgerAvailable = (int) DB::table('internal_exam_inventory_ledger_entries')
            ->where('organization_id', $organizationId)
            ->sum('available_delta');

        $operationalAvailable = DB::table('internal_exam_inventory_entries')
            ->where('organization_id', $organizationId)
            ->where('current_state', 'available')
            ->count();

        if ($ledgerAvailable < 0 || $ledgerAvailable !== $operationalAvailable) {
            throw ResourceDomainException::conflict(
                'Internal exam inventory ledger and operational availability projection are inconsistent.',
            );
        }

        return ['available_count' => $ledgerAvailable];
    }

    /** @return array{period_start:string,period_end:string,events:list<array<string,mixed>>} */
    private function calendarSummary(string $organizationId): array
    {
        $periodStart = now()->copy()->startOfMonth();
        $periodEnd = $periodStart->copy()->addMonth();

        $events = DB::table('calendar_events')
            ->where('organization_id', $organizationId)
            ->where('status', 'scheduled')
            ->where('starts_at', '<', $periodEnd)
            ->where('ends_at', '>=', $periodStart)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(static function (object $row): array {
                /** @var object{id:mixed,event_type:mixed,name:mixed,starts_at:mixed,ends_at:mixed,status:mixed} $row */
                return [
                    'id' => (string) $row->id,
                    'event_type' => (string) $row->event_type,
                    'name' => $row->name === null ? null : (string) $row->name,
                    'starts_at' => (string) $row->starts_at,
                    'ends_at' => (string) $row->ends_at,
                    'status' => (string) $row->status,
                ];
            })
            ->values()
            ->all();

        return [
            'period_start' => $periodStart->format(DATE_ATOM),
            'period_end' => $periodEnd->format(DATE_ATOM),
            'events' => array_values($events),
        ];
    }
}
