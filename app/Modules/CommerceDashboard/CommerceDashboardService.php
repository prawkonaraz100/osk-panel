<?php

namespace App\Modules\CommerceDashboard;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommerceDashboardService
{
    /** @var list<string> */
    public const PURCHASE_STATUSES = [
        'unpaid',
        'payment_pending',
        'paid_processing',
        'completed',
        'requires_reconciliation',
    ];

    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /**
     * @param  list<string>  $statuses
     * @return array{data:list<array<string,mixed>>,meta:array{page:int,per_page:int,total:int,last_page:int}}
     */
    public function listOrders(string $sessionId, int $page, int $perPage, array $statuses = []): array
    {
        $membership = $this->authorizedOrganization($sessionId, 'purchases.view');

        return $this->paginateOrders($membership['organization_id'], $page, $perPage, $statuses);
    }

    /** @return array<string,mixed> */
    public function getOrder(string $sessionId, string $orderId): array
    {
        $membership = $this->authorizedOrganization($sessionId, 'purchases.view');
        $row = DB::table('orders')
            ->where('orders.organization_id', $membership['organization_id'])
            ->where('orders.id', $orderId)
            ->select('orders.*')
            ->selectRaw($this->purchaseStatusSql().' AS projected_status')
            ->first();

        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $this->presentOrder($row);
    }

    /**
     * @param  list<string>  $statuses
     * @return array{data:list<array<string,mixed>>,meta:array{page:int,per_page:int,total:int,last_page:int}}
     */
    public function purchaseHistory(string $sessionId, int $page, int $perPage, array $statuses = []): array
    {
        return $this->listOrders($sessionId, $page, $perPage, $statuses);
    }

    /** @return array{data:list<array<string,mixed>>,meta:array{page:int,per_page:int,total:int,last_page:int}} */
    public function listPayments(string $sessionId, int $page, int $perPage): array
    {
        $membership = $this->authorizedOrganization($sessionId, 'purchases.view');
        $query = DB::table('payments')
            ->where('organization_id', $membership['organization_id']);

        $total = (int) (clone $query)->count();
        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return [
            'data' => array_values($rows->map(fn (object $row): array => $this->presentPayment($row))->all()),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ];
    }

    /** @return array<string,mixed> */
    public function startPayment(string $sessionId, string $orderId, string $method, string $requestId): array
    {
        $actor = $this->authorizedOrganization($sessionId, 'purchases.pay');
        $method = strtolower(trim($method));
        if ($method === '' || preg_match('/^[a-z0-9_.-]{2,64}$/', $method) !== 1) {
            throw ResourceDomainException::rule('Unsupported payment method code.');
        }

        return DB::transaction(function () use ($actor, $orderId, $method, $requestId): array {
            $order = DB::table('orders')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();
            if ($order === null) {
                throw ResourceDomainException::notFound();
            }

            if ((int) $order->total_amount_minor <= 0 || $order->zero_total_settled_at !== null) {
                throw ResourceDomainException::conflict('This order does not accept an external payment attempt.');
            }

            $settled = DB::table('order_payment_settlements')
                ->where('organization_id', $actor['organization_id'])
                ->where('order_id', $orderId)
                ->exists();
            if ($settled) {
                throw ResourceDomainException::conflict('This order is already paid.');
            }

            $paymentId = (string) Str::uuid7();
            $publicReference = $this->newPublicPaymentReference();
            DB::table('payments')->insert([
                'id' => $paymentId,
                'organization_id' => $actor['organization_id'],
                'order_id' => $orderId,
                'provider' => $method,
                'provider_payment_id' => null,
                'public_payment_reference' => $publicReference,
                'status' => 'pending',
                'amount_minor' => (int) $order->total_amount_minor,
                'currency' => (string) $order->currency,
                'confirmed_at' => null,
                'failed_at' => null,
                'created_at' => now(),
            ]);

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'commerce.payment.started',
                'platform_payment',
                $paymentId,
                $requestId,
                ['fields' => [], 'state' => 'absent'],
                ['fields' => ['order_id', 'amount_minor', 'currency', 'method'], 'state' => 'pending'],
            );

            return $this->presentPayment(
                DB::table('payments')
                    ->where('organization_id', $actor['organization_id'])
                    ->where('id', $paymentId)
                    ->firstOrFail(),
            );
        });
    }

    /**
     * @param  list<string>  $statuses
     * @return array{data:list<array<string,mixed>>,meta:array{page:int,per_page:int,total:int,last_page:int}}
     */
    private function paginateOrders(string $organizationId, int $page, int $perPage, array $statuses): array
    {
        $base = DB::table('orders')
            ->where('orders.organization_id', $organizationId)
            ->select('orders.*')
            ->selectRaw($this->purchaseStatusSql().' AS projected_status');

        $query = DB::query()->fromSub($base, 'purchase_orders');
        if ($statuses !== []) {
            $query->whereIn('projected_status', $statuses);
        }

        $total = (int) (clone $query)->count();
        $rows = $query
            ->orderByDesc('ordered_at')
            ->orderByDesc('order_sequence')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return [
            'data' => array_values($rows->map(fn (object $row): array => $this->presentOrder($row))->all()),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ];
    }

    /** @return array<string,mixed> */
    private function presentOrder(object $row): array
    {
        $items = DB::table('order_items')
            ->where('organization_id', (string) $row->organization_id)
            ->where('order_id', (string) $row->id)
            ->orderBy('id')
            ->get()
            ->map(function (object $item): array {
                $product = $this->jsonObject($item->product_snapshot);
                $pricing = $this->jsonObject($item->pricing_snapshot);

                return [
                    'id' => (string) $item->id,
                    'product_kind' => (string) $item->product_kind,
                    'display_name_snapshot' => isset($product['display_name_at_order_time'])
                        ? (string) $product['display_name_at_order_time']
                        : null,
                    'quantity' => (int) $item->quantity,
                    'currency' => (string) $item->currency,
                    'list_unit_amount_minor' => (int) $item->list_unit_amount_minor,
                    'unit_amount_minor' => (int) $item->unit_amount_minor,
                    'unit_discount_amount_minor' => (int) $item->unit_discount_amount_minor,
                    'vat_rate_basis_points' => (int) $item->vat_rate_basis_points,
                    'line_total_minor' => (int) $item->total_amount_minor,
                    'product_snapshot' => $product,
                    'pricing_snapshot' => $pricing,
                ];
            })
            ->all();

        return [
            'id' => (string) $row->id,
            'order_sequence' => (int) $row->order_sequence,
            'display_number' => (string) $row->order_sequence,
            'status' => (string) $row->projected_status,
            'total' => [
                'amount_minor' => (int) $row->total_amount_minor,
                'currency' => (string) $row->currency,
            ],
            'items' => array_values($items),
            'created_at' => (string) $row->ordered_at,
            'ordered_at' => (string) $row->ordered_at,
            'booked_at' => $row->booked_at === null ? null : (string) $row->booked_at,
        ];
    }

    /** @return array<string,mixed> */
    private function presentPayment(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'order_id' => (string) $row->order_id,
            'status' => (string) $row->status,
            'amount' => [
                'amount_minor' => (int) $row->amount_minor,
                'currency' => (string) $row->currency,
            ],
            'provider' => (string) $row->provider,
            'public_payment_reference' => (string) $row->public_payment_reference,
            'confirmed_at' => $row->confirmed_at === null ? null : (string) $row->confirmed_at,
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

    private function purchaseStatusSql(): string
    {
        return <<<'SQL'
CASE
    WHEN EXISTS (
        SELECT 1
        FROM order_payment_settlements settlements
        WHERE settlements.organization_id = orders.organization_id
          AND settlements.order_id = orders.id
    ) OR orders.zero_total_settled_at IS NOT NULL
    THEN CASE
        WHEN EXISTS (
            SELECT 1 FROM order_fulfillments fulfillment
            WHERE fulfillment.organization_id = orders.organization_id
              AND fulfillment.order_id = orders.id
              AND fulfillment.state = 'requires_reconciliation'
        ) THEN 'requires_reconciliation'
        WHEN EXISTS (
            SELECT 1 FROM order_fulfillments fulfillment
            WHERE fulfillment.organization_id = orders.organization_id
              AND fulfillment.order_id = orders.id
              AND fulfillment.state = 'fulfilled'
        ) THEN 'completed'
        WHEN EXISTS (
            SELECT 1 FROM order_fulfillments fulfillment
            WHERE fulfillment.organization_id = orders.organization_id
              AND fulfillment.order_id = orders.id
              AND fulfillment.state = 'pending'
        ) THEN 'paid_processing'
        ELSE 'requires_reconciliation'
    END
    WHEN EXISTS (
        SELECT 1
        FROM payments pending_payments
        WHERE pending_payments.organization_id = orders.organization_id
          AND pending_payments.order_id = orders.id
          AND pending_payments.status = 'pending'
    ) THEN 'payment_pending'
    ELSE 'unpaid'
END
SQL;
    }

    /** @return array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int} */
    private function authorizedOrganization(string $sessionId, string $permission): array
    {
        $membership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);

        return $this->tenantAuthorizer->requireOrganizationPermission(
            $sessionId,
            $membership['organization_id'],
            $permission,
        );
    }

    private function newPublicPaymentReference(): string
    {
        do {
            $candidate = bin2hex(random_bytes(32));
        } while (DB::table('payments')->where('public_payment_reference', $candidate)->exists());

        return $candidate;
    }
}
