<?php

namespace App\Modules\AuditNotification;

use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceIdempotency;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ActivityNotificationController
{
    public function __construct(
        private readonly ActivityNotificationService $service,
        private readonly ResourceIdempotency $idempotency,
        private readonly TenantAuthorizer $tenantAuthorizer,
    ) {}

    public function activityList(Request $request): JsonResponse
    {
        [$page, $perPage] = $this->pagination($request);

        return response()->json($this->service->listActivity(
            $this->sessionId($request),
            $page,
            $perPage,
            $this->eventTypes($request),
        ));
    }

    public function notificationsList(Request $request): JsonResponse
    {
        [$page, $perPage] = $this->pagination($request);

        return response()->json($this->service->listNotifications(
            $this->sessionId($request),
            $page,
            $perPage,
            $this->unreadOnly($request),
        ));
    }

    public function notificationsMarkRead(Request $request, string $notificationId): JsonResponse
    {
        $sessionId = $this->sessionId($request);
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $result = $this->idempotency->execute(
            $membership['organization_id'],
            'notifications.mark_read',
            $this->idempotencyKey($request),
            [
                'notification_id' => $notificationId,
                'organization_membership_id' => $membership['id'],
                'user_id' => $membership['user_id'],
            ],
            function () use ($sessionId, $notificationId): array {
                $body = $this->service->markRead($sessionId, $notificationId);

                return [
                    'status' => 200,
                    'resource_type' => 'notification',
                    'resource_id' => $notificationId,
                    'body' => $body,
                ];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    /** @return array{0:int,1:int} */
    private function pagination(Request $request): array
    {
        $pageRaw = $request->query('page', 1);
        $perPageRaw = $request->query('per_page', 25);
        if (is_numeric($pageRaw) === false || (int) $pageRaw < 1) {
            throw ValidationException::withMessages(['page' => ['page must be an integer greater than or equal to 1.']]);
        }
        if (is_numeric($perPageRaw) === false || (int) $perPageRaw < 1 || (int) $perPageRaw > 100) {
            throw ValidationException::withMessages(['per_page' => ['per_page must be between 1 and 100.']]);
        }

        return [(int) $pageRaw, (int) $perPageRaw];
    }

    /** @return list<string> */
    private function eventTypes(Request $request): array
    {
        $raw = $request->query('event_type');
        if ($raw === null) {
            return [];
        }
        if (is_string($raw)) {
            $raw = [$raw];
        }

        $eventTypes = [];
        foreach ($raw as $value) {
            if (is_string($value) === false || $value === '' || strlen($value) > 128) {
                throw ValidationException::withMessages(['event_type' => ['event_type must contain non-empty strings up to 128 characters.']]);
            }
            if (in_array($value, $eventTypes, true) === false) {
                $eventTypes[] = $value;
            }
        }

        return $eventTypes;
    }

    private function unreadOnly(Request $request): bool
    {
        $raw = $request->query('unread_only');
        if ($raw === null) {
            return false;
        }
        if (is_array($raw)) {
            throw ValidationException::withMessages(['unread_only' => ['unread_only must be a boolean query value.']]);
        }

        return match (strtolower($raw)) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw ValidationException::withMessages(['unread_only' => ['unread_only must be true or false.']]),
        };
    }

    private function sessionId(Request $request): string
    {
        $sessionId = $request->session()->get('auth_session_id');
        if (is_string($sessionId) === false || $sessionId === '') {
            throw new AuthenticationException('Authenticated application session required.');
        }

        return $sessionId;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || Str::isUuid($key) === false) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['A UUID Idempotency-Key header is required.'],
            ]);
        }

        return $key;
    }
}
