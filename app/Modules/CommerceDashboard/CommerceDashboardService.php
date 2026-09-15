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
        private readonly CommercePricingCatalog $pricingCatalog,
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
     * @param  list<array{product_id:string,quantity:int}>  $items
     * @return array<string,mixed>
     */
    public function createLicenseOrder(string $sessionId, array $items, string $paymentMethod, string $requestId): array
    {
        $actor = $this->authorizedOrganization($sessionId, 'licenses.purchase');
        if ($items === []) {
            throw ResourceDomainException::rule('At least one license product is required.');
        }

        return $this->placeOrder(
            $actor,
            fn (bool $lock): array => $this->resolveLicenseOrderLines($items, $lock),
            $paymentMethod,
            $requestId,
            'commerce.license_order.created',
        );
    }

    /** @return array<string,mixed> */
    public function createInternalExamOrder(string $sessionId, int $quantity, string $paymentMethod, string $requestId): array
    {
        $actor = $this->authorizedOrganization($sessionId, 'exams.purchase');
        if ($quantity < 1) {
            throw ResourceDomainException::rule('Internal exam quantity must be at least 1.');
        }

        return $this->placeOrder(
            $actor,
            fn (bool $lock): array => $this->resolveInternalExamOrderLines($quantity, $lock),
            $paymentMethod,
            $requestId,
            'commerce.internal_exam_order.created',
        );
    }

    /** @return array<string,mixed> */
    public function internalExamPurchaseOffer(string $sessionId): array
    {
        $this->authorizedOrganization($sessionId, 'exams.purchase');

        $catalog = $this->resolveInternalExamCatalog(false);
        $pricing = $this->pricingCatalog->require((string) $catalog->code);

        return [
            'display_name' => $pricing['display_name'],
            'unit_price' => [
                'amount_minor' => $pricing['charged_unit_amount_minor'],
                'currency' => $pricing['currency'],
            ],
            'list_unit_price' => [
                'amount_minor' => $pricing['list_unit_amount_minor'],
                'currency' => $pricing['currency'],
            ],
            'pricing_revision' => $pricing['pricing_revision'],
            'sample_data' => (bool) $pricing['sample_data'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listServiceEntitlements(string $sessionId): array
    {
        $actor = $this->authorizedOrganization($sessionId, 'purchases.view');

        return array_values(DB::table('service_entitlements as entitlement')
            ->leftJoin('service_activations as activation', function ($join): void {
                $join->on('activation.organization_id', '=', 'entitlement.organization_id')
                    ->on('activation.service_entitlement_id', '=', 'entitlement.id');
            })
            ->where('entitlement.organization_id', $actor['organization_id'])
            ->orderByDesc('entitlement.granted_at')
            ->orderByDesc('entitlement.id')
            ->get([
                'entitlement.id',
                'entitlement.service_type',
                'entitlement.status',
                'entitlement.activation_mode',
                'entitlement.granted_at',
                'activation.activated_at',
                'activation.effective_from',
                'activation.effective_to',
            ])
            ->map(fn (object $row): array => $this->presentServiceEntitlement($row))
            ->all());
    }

    /** @return array<string,mixed> */
    public function activateServiceEntitlement(string $sessionId, string $entitlementId, string $requestId): array
    {
        $actor = $this->authorizedOrganization($sessionId, 'purchases.create');

        return DB::transaction(function () use ($actor, $entitlementId, $requestId): array {
            $entitlement = DB::table('service_entitlements')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $entitlementId)
                ->lockForUpdate()
                ->first();

            if ($entitlement === null) {
                throw ResourceDomainException::notFound();
            }

            if ((string) $entitlement->activation_mode !== 'explicit') {
                throw ResourceDomainException::conflict('Only explicit service entitlements can be activated by this operation.');
            }

            if (in_array((string) $entitlement->status, ['expired', 'revoked'], true)) {
                throw ResourceDomainException::conflict('Terminal service entitlement cannot be activated.');
            }

            $existingActivation = DB::table('service_activations')
                ->where('organization_id', $actor['organization_id'])
                ->where('service_entitlement_id', $entitlementId)
                ->first();

            if ($existingActivation !== null) {
                if ((string) $entitlement->status !== 'activated') {
                    throw ResourceDomainException::conflict('Service entitlement activation evidence and status disagree.');
                }

                return $this->getServiceEntitlement($actor['organization_id'], $entitlementId);
            }

            if ((string) $entitlement->status !== 'available') {
                throw ResourceDomainException::conflict('Service entitlement is not available for explicit activation.');
            }

            $now = now();
            DB::table('service_activations')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $actor['organization_id'],
                'service_entitlement_id' => $entitlementId,
                'activated_by_user_id' => $actor['user_id'],
                'activated_at' => $now,
                'effective_from' => $now,
                'effective_to' => null,
                'created_at' => $now,
            ]);

            $updated = DB::table('service_entitlements')
                ->where('organization_id', $actor['organization_id'])
                ->where('id', $entitlementId)
                ->where('status', 'available')
                ->update(['status' => 'activated']);

            if ($updated !== 1) {
                throw ResourceDomainException::conflict('Service entitlement activation lost its available-state race.');
            }

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                'commerce.service_entitlement.activated',
                'service_entitlement',
                $entitlementId,
                $requestId,
                ['state' => 'available'],
                ['state' => 'activated'],
            );

            return $this->getServiceEntitlement($actor['organization_id'], $entitlementId);
        });
    }

    /** @return array<string,mixed> */
    private function getServiceEntitlement(string $organizationId, string $entitlementId): array
    {
        $row = DB::table('service_entitlements as entitlement')
            ->leftJoin('service_activations as activation', function ($join): void {
                $join->on('activation.organization_id', '=', 'entitlement.organization_id')
                    ->on('activation.service_entitlement_id', '=', 'entitlement.id');
            })
            ->where('entitlement.organization_id', $organizationId)
            ->where('entitlement.id', $entitlementId)
            ->first([
                'entitlement.id',
                'entitlement.service_type',
                'entitlement.status',
                'entitlement.activation_mode',
                'entitlement.granted_at',
                'activation.activated_at',
                'activation.effective_from',
                'activation.effective_to',
            ]);

        if ($row === null) {
            throw ResourceDomainException::notFound();
        }

        return $this->presentServiceEntitlement($row);
    }

    /** @return array<string,mixed> */
    private function presentServiceEntitlement(object $row): array
    {
        /** @var object{id:mixed,service_type:mixed,status:mixed,activation_mode:mixed,granted_at:mixed,activated_at:mixed,effective_from:mixed,effective_to:mixed} $row */
        return [
            'id' => (string) $row->id,
            'service_type' => (string) $row->service_type,
            'status' => (string) $row->status,
            'activation_mode' => (string) $row->activation_mode,
            'granted_at' => $row->granted_at === null ? null : (string) $row->granted_at,
            'activated_at' => $row->activated_at === null ? null : (string) $row->activated_at,
            'effective_from' => $row->effective_from === null ? null : (string) $row->effective_from,
            'effective_to' => $row->effective_to === null ? null : (string) $row->effective_to,
        ];
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
        /** @var object{organization_id:mixed,id:mixed,order_sequence:mixed,projected_status:mixed,total_amount_minor:mixed,currency:mixed,ordered_at:mixed,booked_at:mixed} $row */
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
        /** @var object{id:mixed,order_id:mixed,status:mixed,amount_minor:mixed,currency:mixed,provider:mixed,public_payment_reference:mixed,confirmed_at:mixed,created_at:mixed} $row */
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

    /**
     * @param  array{id:string,organization_id:string,user_id:string,status:string,is_owner:bool,version:int,authorization_version:int}  $actor
     * @param  callable(bool):list<array<string,mixed>>  $resolver
     * @return array<string,mixed>
     */
    private function placeOrder(
        array $actor,
        callable $resolver,
        string $paymentMethod,
        string $requestId,
        string $auditAction,
    ): array {
        $paymentMethod = strtolower(trim($paymentMethod));
        if (strlen($paymentMethod) < 2
            || strlen($paymentMethod) > 64
            || preg_match('/^[a-z0-9_.-]+$/', $paymentMethod) !== 1) {
            throw ResourceDomainException::rule('Unsupported payment method code.');
        }

        return DB::transaction(function () use ($actor, $resolver, $paymentMethod, $requestId, $auditAction): array {
            $initialLines = $resolver(false);
            $this->orderTotals($initialLines);

            $sequence = $this->reserveOrderSequence($actor['organization_id']);

            $lines = $resolver(true);
            if (! hash_equals(
                $this->orderLineAuthorityFingerprint($initialLines),
                $this->orderLineAuthorityFingerprint($lines),
            )) {
                throw ResourceDomainException::conflict('Commerce catalog or pricing authority changed during order placement.');
            }

            [$currency, $total] = $this->orderTotals($lines);
            usort($lines, static function (array $left, array $right): int {
                $catalog = strcmp((string) $left['catalog_item_id'], (string) $right['catalog_item_id']);

                return $catalog !== 0
                    ? $catalog
                    : ((int) $left['request_index'] <=> (int) $right['request_index']);
            });

            $now = now();
            $orderId = (string) Str::uuid7();
            $zeroTotal = $total === 0;

            DB::table('orders')->insert([
                'id' => $orderId,
                'organization_id' => $actor['organization_id'],
                'order_sequence' => $sequence,
                'ordered_at' => $now,
                'booked_at' => $zeroTotal ? $now : null,
                'zero_total_settled_at' => $zeroTotal ? $now : null,
                'total_amount_minor' => $total,
                'currency' => $currency,
                'created_by_user_id' => $actor['user_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($lines as $line) {
                DB::table('order_items')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $actor['organization_id'],
                    'order_id' => $orderId,
                    'commerce_catalog_item_id' => $line['catalog_item_id'],
                    'product_kind' => $line['product_kind'],
                    'license_product_id' => $line['license_product_id'],
                    'quantity' => $line['quantity'],
                    'currency' => $line['currency'],
                    'list_unit_amount_minor' => $line['list_unit_amount_minor'],
                    'unit_amount_minor' => $line['unit_amount_minor'],
                    'unit_discount_amount_minor' => $line['unit_discount_amount_minor'],
                    'vat_rate_basis_points' => $line['vat_rate_basis_points'],
                    'total_amount_minor' => $line['total_amount_minor'],
                    'product_snapshot' => $this->canonicalJson($line['product_snapshot']),
                    'pricing_snapshot' => $this->canonicalJson($line['pricing_snapshot']),
                    'snapshot_hash' => $line['snapshot_hash'],
                    'created_at' => $now,
                ]);
            }

            if ($zeroTotal) {
                DB::table('order_fulfillments')->insert([
                    'organization_id' => $actor['organization_id'],
                    'order_id' => $orderId,
                    'source_kind' => 'zero_total',
                    'settlement_payment_id' => null,
                    'state' => 'pending',
                    'created_at' => $now,
                    'fulfilled_at' => null,
                    'requires_reconciliation_at' => null,
                    'reconciliation_reason' => null,
                ]);
            } else {
                DB::table('payments')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $actor['organization_id'],
                    'order_id' => $orderId,
                    'provider' => $paymentMethod,
                    'provider_payment_id' => null,
                    'public_payment_reference' => $this->newPublicPaymentReference(),
                    'status' => 'pending',
                    'amount_minor' => $total,
                    'currency' => $currency,
                    'confirmed_at' => null,
                    'failed_at' => null,
                    'created_at' => $now,
                ]);
            }

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            $this->auditOutbox->recordOrganizationEvent(
                $actor['organization_id'],
                $actor['id'],
                $actor['user_id'],
                $auditAction,
                'commerce_order',
                $orderId,
                $requestId,
                ['fields' => [], 'state' => 'absent'],
                [
                    'fields' => ['order_sequence', 'total_amount_minor', 'currency', 'product_kinds'],
                    'state' => $zeroTotal ? 'zero_total_settled' : 'payment_pending',
                ],
            );

            $row = DB::table('orders')
                ->where('orders.organization_id', $actor['organization_id'])
                ->where('orders.id', $orderId)
                ->select('orders.*')
                ->selectRaw($this->purchaseStatusSql().' AS projected_status')
                ->firstOrFail();

            return $this->presentOrder($row);
        });
    }

    /**
     * @param  list<array{product_id:string,quantity:int}>  $items
     * @return list<array<string,mixed>>
     */
    private function resolveLicenseOrderLines(array $items, bool $lock): array
    {
        $normalized = [];
        foreach ($items as $index => $item) {
            $productId = (string) $item['product_id'];
            $quantity = (int) $item['quantity'];
            if ($productId === '' || $quantity < 1 || $quantity > 2147483647) {
                throw ResourceDomainException::rule('License order item is invalid.');
            }
            $normalized[] = [
                'request_index' => $index,
                'product_id' => $productId,
                'quantity' => $quantity,
            ];
        }

        $productIds = array_values(array_unique(array_column($normalized, 'product_id')));
        sort($productIds, SORT_STRING);
        $resolved = [];

        foreach ($productIds as $productId) {
            $productQuery = DB::table('license_products')
                ->where('id', $productId)
                ->where('active', true);
            if ($lock) {
                $productQuery->lockForUpdate();
            }
            $product = $productQuery->first();
            if ($product === null) {
                throw ResourceDomainException::rule('Requested license product is unavailable.');
            }

            $catalogQuery = DB::table('commerce_catalog_items')
                ->where('product_kind', 'license')
                ->where('license_product_id', $productId)
                ->where('active', true)
                ->orderBy('id');
            if ($lock) {
                $catalogQuery->lockForUpdate();
            }
            $catalogRows = $catalogQuery->get();
            if ($catalogRows->count() !== 1) {
                throw ResourceDomainException::rule('Requested license product does not have exactly one active commerce catalog mapping.');
            }

            $catalog = $catalogRows->first();
            if ($catalog === null) {
                throw ResourceDomainException::rule('Requested license catalog mapping is unavailable.');
            }
            $resolved[$productId] = [$product, $catalog, $this->pricingForCatalogCode((string) $catalog->code)];
        }

        $lines = [];
        foreach ($normalized as $item) {
            [$product, $catalog, $pricing] = $resolved[$item['product_id']];
            $productSnapshot = [
                'catalog_item_id' => (string) $catalog->id,
                'stable_catalog_code' => (string) $catalog->code,
                'product_kind' => 'license',
                'display_name_at_order_time' => $pricing['display_name'],
                'license_product_id' => (string) $product->id,
                'license_product_code' => (string) $product->code,
                'duration_days' => (int) $product->duration_days,
                'activation_mode' => (string) $product->activation_mode,
            ];
            $lines[] = $this->buildOrderLine(
                (int) $item['request_index'],
                (string) $catalog->id,
                'license',
                (string) $product->id,
                (int) $item['quantity'],
                $pricing,
                $productSnapshot,
            );
        }

        return $lines;
    }

    /** @return list<array<string,mixed>> */
    private function resolveInternalExamOrderLines(int $quantity, bool $lock): array
    {
        if ($quantity < 1 || $quantity > 2147483647) {
            throw ResourceDomainException::rule('Internal exam quantity is invalid.');
        }

        $catalog = $this->resolveInternalExamCatalog($lock);
        $pricing = $this->pricingForCatalogCode((string) $catalog->code);

        return [
            $this->buildOrderLine(
                0,
                (string) $catalog->id,
                'internal_exam',
                null,
                $quantity,
                $pricing,
                [
                    'catalog_item_id' => (string) $catalog->id,
                    'stable_catalog_code' => (string) $catalog->code,
                    'product_kind' => 'internal_exam',
                    'display_name_at_order_time' => $pricing['display_name'],
                ],
            ),
        ];
    }

    private function resolveInternalExamCatalog(bool $lock): object
    {
        $catalogCode = $this->internalExamCatalogCode();

        $query = DB::table('commerce_catalog_items')
            ->where('code', $catalogCode)
            ->where('product_kind', 'internal_exam')
            ->whereNull('license_product_id')
            ->where('active', true)
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $catalogRows = $query->get();
        if ($catalogRows->count() !== 1) {
            throw ResourceDomainException::rule(
                'Configured internal exam catalog selector does not resolve exactly one active internal exam product.',
            );
        }

        $catalog = $catalogRows->first();
        if ($catalog === null) {
            throw ResourceDomainException::rule('Configured internal exam catalog product is unavailable.');
        }

        return $catalog;
    }

    private function internalExamCatalogCode(): string
    {
        $catalogCode = config('commerce.order_create.internal_exam_catalog_code');
        if (is_string($catalogCode) && trim($catalogCode) !== '') {
            return trim($catalogCode);
        }

        if ((bool) config('sample_data.enabled', false)) {
            $sampleCatalogCode = config('sample_data.internal_exam.catalog_code');
            if (is_string($sampleCatalogCode) && trim($sampleCatalogCode) !== '') {
                return trim($sampleCatalogCode);
            }
        }

        throw ResourceDomainException::conflict('Internal exam commerce catalog selector is not configured.');
    }

    /**
     * @param  array{currency:string,list_unit_amount_minor:int,charged_unit_amount_minor:int,vat_rate_basis_points:int,display_name:string,pricing_revision:string}  $pricing
     * @param  array<string,mixed>  $productSnapshot
     * @return array<string,mixed>
     */
    private function buildOrderLine(
        int $requestIndex,
        string $catalogItemId,
        string $productKind,
        ?string $licenseProductId,
        int $quantity,
        array $pricing,
        array $productSnapshot,
    ): array {
        $lineTotal = $this->checkedMultiply($quantity, $pricing['charged_unit_amount_minor']);
        $pricingSnapshot = [
            'list_unit_amount_minor' => $pricing['list_unit_amount_minor'],
            'charged_unit_amount_minor' => $pricing['charged_unit_amount_minor'],
            'unit_discount_amount_minor' => $pricing['list_unit_amount_minor'] - $pricing['charged_unit_amount_minor'],
            'vat_rate_basis_points' => $pricing['vat_rate_basis_points'],
            'pricing_revision' => $pricing['pricing_revision'],
            'pricing_resolution_time' => now()->toIso8601String(),
        ];
        $snapshotHash = hash('sha256', $this->canonicalJson([
            'product_snapshot' => $productSnapshot,
            'pricing_snapshot' => $pricingSnapshot,
        ]));

        return [
            'request_index' => $requestIndex,
            'catalog_item_id' => $catalogItemId,
            'product_kind' => $productKind,
            'license_product_id' => $licenseProductId,
            'quantity' => $quantity,
            'currency' => $pricing['currency'],
            'list_unit_amount_minor' => $pricing['list_unit_amount_minor'],
            'unit_amount_minor' => $pricing['charged_unit_amount_minor'],
            'unit_discount_amount_minor' => $pricing['list_unit_amount_minor'] - $pricing['charged_unit_amount_minor'],
            'vat_rate_basis_points' => $pricing['vat_rate_basis_points'],
            'total_amount_minor' => $lineTotal,
            'product_snapshot' => $productSnapshot,
            'pricing_snapshot' => $pricingSnapshot,
            'snapshot_hash' => $snapshotHash,
        ];
    }

    /**
     * @return array{currency:string,list_unit_amount_minor:int,charged_unit_amount_minor:int,vat_rate_basis_points:int,display_name:string,pricing_revision:string}
     */
    private function pricingForCatalogCode(string $catalogCode): array
    {
        $pricing = $this->pricingCatalog->require($catalogCode);
        unset($pricing['sample_data']);

        return $pricing;
    }

    /**
     * @param  list<array<string,mixed>>  $lines
     * @return array{0:string,1:int}
     */
    private function orderTotals(array $lines): array
    {
        if ($lines === []) {
            throw ResourceDomainException::rule('Order must contain at least one item.');
        }

        $currency = null;
        $total = 0;
        foreach ($lines as $line) {
            $lineCurrency = (string) ($line['currency'] ?? '');
            $lineTotal = $line['total_amount_minor'] ?? null;
            if ($lineCurrency === '' || ! is_int($lineTotal) || $lineTotal < 0) {
                throw ResourceDomainException::conflict('Resolved order pricing is invalid.');
            }
            if ($currency === null) {
                $currency = $lineCurrency;
            } elseif ($currency !== $lineCurrency) {
                throw ResourceDomainException::rule('One order cannot mix pricing currencies.');
            }
            if ($lineTotal > PHP_INT_MAX - $total) {
                throw ResourceDomainException::rule('Order total exceeds the supported money range.');
            }
            $total += $lineTotal;
        }

        return [$currency, $total];
    }

    /** @param  list<array<string,mixed>>  $lines */
    private function orderLineAuthorityFingerprint(array $lines): string
    {
        $material = array_map(static fn (array $line): array => [
            'request_index' => $line['request_index'],
            'catalog_item_id' => $line['catalog_item_id'],
            'product_kind' => $line['product_kind'],
            'license_product_id' => $line['license_product_id'],
            'quantity' => $line['quantity'],
            'currency' => $line['currency'],
            'list_unit_amount_minor' => $line['list_unit_amount_minor'],
            'unit_amount_minor' => $line['unit_amount_minor'],
            'unit_discount_amount_minor' => $line['unit_discount_amount_minor'],
            'vat_rate_basis_points' => $line['vat_rate_basis_points'],
            'product_snapshot' => $line['product_snapshot'],
            'pricing_revision' => $line['pricing_snapshot']['pricing_revision'] ?? null,
        ], $lines);

        return hash('sha256', $this->canonicalJson($material));
    }

    private function reserveOrderSequence(string $organizationId): int
    {
        $row = DB::table('organization_commerce_order_sequences')
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            if (DB::table('orders')->where('organization_id', $organizationId)->exists()) {
                throw ResourceDomainException::conflict('Commerce order sequence allocator is missing for an organization with existing orders.');
            }

            DB::table('organization_commerce_order_sequences')->insertOrIgnore([
                'organization_id' => $organizationId,
                'next_order_sequence' => 1,
            ]);
            $row = DB::table('organization_commerce_order_sequences')
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();
        }

        if ($row === null) {
            throw ResourceDomainException::conflict('Commerce order sequence allocator could not be established.');
        }

        $sequence = (int) $row->next_order_sequence;
        if ($sequence < 1 || $sequence >= PHP_INT_MAX) {
            throw ResourceDomainException::conflict('Commerce order sequence allocator is outside the supported range.');
        }

        $updated = DB::table('organization_commerce_order_sequences')
            ->where('organization_id', $organizationId)
            ->where('next_order_sequence', $sequence)
            ->update(['next_order_sequence' => $sequence + 1]);
        if ($updated !== 1) {
            throw ResourceDomainException::conflict('Commerce order sequence allocation lost its serialized update.');
        }

        return $sequence;
    }

    private function checkedMultiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left))) {
            throw ResourceDomainException::rule('Order line total exceeds the supported money range.');
        }

        return $left * $right;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalizeSnapshot($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function canonicalizeSnapshot(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeSnapshot($item);
        }

        return $value;
    }

    /** @return literal-string */
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
