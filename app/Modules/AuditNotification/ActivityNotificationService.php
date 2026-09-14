<?php

namespace App\Modules\AuditNotification;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use Illuminate\Support\Facades\DB;

final class ActivityNotificationService
{
    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
    ) {}

    /**
     * @param  list<string>  $eventTypes
     * @return array{data:list<array<string,mixed>>,meta:array{page:int,per_page:int,total:int,last_page:int}}
     */
    public function listActivity(string $sessionId, int $page, int $perPage, array $eventTypes = []): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $query = DB::table('organization_activity_events')
            ->where('organization_id', $membership['organization_id']);

        if ($eventTypes !== []) {
            $query->whereIn('event_type', $eventTypes);
        }

        $total = (int) (clone $query)->count();
        $rows = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return [
            'data' => array_values($rows->map(fn (object $row): array => $this->presentActivity($row))->all()),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ];
    }

    /** @return array{data:list<array<string,mixed>>,meta:array{page:int,per_page:int,total:int,last_page:int}} */
    public function listNotifications(string $sessionId, int $page, int $perPage, bool $unreadOnly): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $query = DB::table('notifications')
            ->where('organization_id', $membership['organization_id'])
            ->where('organization_membership_id', $membership['id'])
            ->where('user_id', $membership['user_id']);

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $total = (int) (clone $query)->count();
        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return [
            'data' => array_values($rows->map(fn (object $row): array => $this->presentNotification($row))->all()),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ];
    }

    /** @return array{data:list<array<string,mixed>>,meta:array{page:int,per_page:int,total:int,last_page:int}} */
    public function listAuditLogs(
        string $sessionId,
        int $page,
        int $perPage,
        ?string $entityType,
        ?string $entityId,
        ?string $requestId,
    ): array {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $this->tenantAuthorizer->requireOrganizationPermission(
            $sessionId,
            $membership['organization_id'],
            'organization.audit.view',
        );

        $query = DB::table('audit_logs')
            ->where('audit_scope', 'organization')
            ->where('organization_id', $membership['organization_id'])
            ->whereNotNull('entity_type');

        if ($entityType !== null) {
            $query->where('entity_type', $entityType);
        }
        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }
        if ($requestId !== null) {
            $query->where('request_id', $requestId);
        }

        $total = (int) (clone $query)->count();
        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get([
                'id',
                'action',
                'entity_type',
                'entity_id',
                'actor_user_id',
                'request_id',
                'reason',
                'created_at',
            ]);

        return [
            'data' => array_values($rows->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'action' => (string) $row->action,
                'entity_type' => (string) $row->entity_type,
                'entity_id' => $row->entity_id === null ? null : (string) $row->entity_id,
                'actor_user_id' => $row->actor_user_id === null ? null : (string) $row->actor_user_id,
                'request_id' => (string) $row->request_id,
                'reason' => $row->reason === null ? null : (string) $row->reason,
                'created_at' => (string) $row->created_at,
            ])->all()),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ];
    }

    /** @return array<string,mixed> */
    public function markRead(string $sessionId, string $notificationId): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return DB::transaction(function () use ($membership, $notificationId): array {
            $row = DB::table('notifications')
                ->where('id', $notificationId)
                ->where('organization_id', $membership['organization_id'])
                ->where('organization_membership_id', $membership['id'])
                ->where('user_id', $membership['user_id'])
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw ResourceDomainException::notFound();
            }

            /** @var object{read_at:mixed} $row */
            if ($row->read_at === null) {
                DB::table('notifications')
                    ->where('id', $notificationId)
                    ->where('organization_id', $membership['organization_id'])
                    ->where('organization_membership_id', $membership['id'])
                    ->where('user_id', $membership['user_id'])
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            $current = DB::table('notifications')
                ->where('id', $notificationId)
                ->where('organization_id', $membership['organization_id'])
                ->where('organization_membership_id', $membership['id'])
                ->where('user_id', $membership['user_id'])
                ->first();

            if ($current === null) {
                throw ResourceDomainException::notFound();
            }

            return $this->presentNotification($current);
        });
    }

    /** @return array<string,mixed> */
    private function presentActivity(object $row): array
    {
        /** @var object{id:mixed,event_type:mixed,occurred_at:mixed,actor_display_name_snapshot:mixed,subject_type:mixed,subject_id:mixed,description_snapshot:mixed,safe_payload:mixed} $row */
        return [
            'id' => (string) $row->id,
            'event_type' => (string) $row->event_type,
            'timestamp' => (string) $row->occurred_at,
            'actor_display_name' => $row->actor_display_name_snapshot === null ? null : (string) $row->actor_display_name_snapshot,
            'related_entity_type' => $row->subject_type === null ? null : (string) $row->subject_type,
            'related_entity_id' => $row->subject_id === null ? null : (string) $row->subject_id,
            'description' => (string) $row->description_snapshot,
            'safe_details' => $row->safe_payload === null ? null : $this->jsonObject($row->safe_payload),
        ];
    }

    /** @return array<string,mixed> */
    private function presentNotification(object $row): array
    {
        /** @var object{id:mixed,type:mixed,payload:mixed,read_at:mixed,created_at:mixed} $row */
        return [
            'id' => (string) $row->id,
            'type' => (string) $row->type,
            'payload' => $this->jsonObject($row->payload),
            'read_at' => $row->read_at === null ? null : (string) $row->read_at,
            'created_at' => (string) $row->created_at,
        ];
    }

    /** @return array<string,mixed> */
    private function jsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{page:int,per_page:int,total:int,last_page:int} */
    private function paginationMeta(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }
}
