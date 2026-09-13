<?php

namespace App\Support\Operations;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CoreReconciliationScanner
{
    /**
     * @return array{
     *   policy_version:string,
     *   as_of:string,
     *   status:string,
     *   findings_total:int,
     *   findings_by_scope:array<string,int>,
     *   mutations_performed:int,
     *   remote_provider_truth_lookup_performed:bool,
     *   pkk_in_scope:bool,
     *   findings:list<array<string,mixed>>
     * }
     */
    public function scan(?CarbonInterface $asOf = null): array
    {
        $version = config('reconciliation.policy_version');
        if (! is_string($version) || trim($version) === '') {
            throw new LogicException('Reconciliation policy version is unavailable.');
        }

        if ((bool) config('reconciliation.automatic_repair_allowed', true)) {
            throw new LogicException('Core reconciliation scanner must remain read-only.');
        }

        if ((bool) config('reconciliation.PKK.in_scope', true)) {
            throw new LogicException('PKK is frozen and must remain outside provider-neutral reconciliation.');
        }

        $effectiveAt = $asOf === null
            ? CarbonImmutable::now()
            : CarbonImmutable::instance($asOf);

        $findings = [
            ...$this->commerceFindings(),
            ...$this->licenseInventoryFindings(),
            ...$this->internalExamFindings(),
            ...$this->outboxFindings($effectiveAt),
        ];

        usort(
            $findings,
            static fn (array $left, array $right): int => [
                $left['scope'],
                $left['code'],
                $left['organization_id'] ?? '',
                $left['entity_id'] ?? '',
            ] <=> [
                $right['scope'],
                $right['code'],
                $right['organization_id'] ?? '',
                $right['entity_id'] ?? '',
            ],
        );

        $byScope = [];
        foreach ($findings as $finding) {
            $scope = (string) $finding['scope'];
            $byScope[$scope] = ($byScope[$scope] ?? 0) + 1;
        }
        ksort($byScope);

        return [
            'policy_version' => $version,
            'as_of' => $effectiveAt->toIso8601String(),
            'status' => $findings === [] ? 'PASS' : 'FINDINGS',
            'findings_total' => count($findings),
            'findings_by_scope' => $byScope,
            'mutations_performed' => 0,
            'remote_provider_truth_lookup_performed' => false,
            'pkk_in_scope' => false,
            'findings' => $findings,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function commerceFindings(): array
    {
        $findings = [];

        $rows = DB::select(<<<'SQL'
SELECT
    p.organization_id::text AS organization_id,
    p.id::text AS entity_id,
    p.order_id::text AS order_id
FROM payments p
LEFT JOIN order_payment_settlements s
    ON s.organization_id = p.organization_id
   AND s.order_id = p.order_id
   AND s.payment_id = p.id
WHERE p.status = 'confirmed'
  AND s.payment_id IS NULL
ORDER BY p.organization_id, p.id
SQL);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'commerce_payment_settlement',
                'confirmed_payment_without_settlement',
                $row->organization_id,
                $row->entity_id,
                ['order_id' => $row->order_id],
            );
        }

        $rows = DB::select(<<<'SQL'
SELECT
    s.organization_id::text AS organization_id,
    s.payment_id::text AS entity_id,
    s.order_id::text AS settlement_order_id,
    p.order_id::text AS payment_order_id,
    p.status::text AS payment_status
FROM order_payment_settlements s
LEFT JOIN payments p ON p.id = s.payment_id
WHERE p.id IS NULL
   OR p.organization_id <> s.organization_id
   OR p.order_id <> s.order_id
   OR p.status <> 'confirmed'
ORDER BY s.organization_id, s.payment_id
SQL);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'commerce_payment_settlement',
                'settlement_payment_mismatch',
                $row->organization_id,
                $row->entity_id,
                [
                    'settlement_order_id' => $row->settlement_order_id,
                    'payment_order_id' => $row->payment_order_id,
                    'payment_status' => $row->payment_status,
                ],
            );
        }

        $rows = DB::select(<<<'SQL'
SELECT
    organization_id::text AS organization_id,
    order_id::text AS entity_id,
    reconciliation_reason
FROM order_fulfillments
WHERE state = 'requires_reconciliation'
ORDER BY organization_id, order_id
SQL);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'purchase_fulfillment',
                'fulfillment_requires_reconciliation',
                $row->organization_id,
                $row->entity_id,
                ['reason_present' => $row->reconciliation_reason !== null && trim((string) $row->reconciliation_reason) !== ''],
            );
        }

        $rows = DB::select(<<<'SQL'
SELECT
    o.organization_id::text AS organization_id,
    o.id::text AS entity_id
FROM orders o
LEFT JOIN order_fulfillments f
    ON f.organization_id = o.organization_id
   AND f.order_id = o.id
WHERE f.order_id IS NULL
  AND (
      o.zero_total_settled_at IS NOT NULL
      OR EXISTS (
          SELECT 1
          FROM order_payment_settlements s
          WHERE s.organization_id = o.organization_id
            AND s.order_id = o.id
      )
  )
ORDER BY o.organization_id, o.id
SQL);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'purchase_fulfillment',
                'settled_order_without_fulfillment',
                $row->organization_id,
                $row->entity_id,
            );
        }

        $rows = DB::select(<<<'SQL'
SELECT
    oi.organization_id::text AS organization_id,
    oi.id::text AS entity_id,
    oi.quantity,
    COUNT(li.id)::int AS granted_count
FROM order_items oi
JOIN orders o
  ON o.organization_id = oi.organization_id
 AND o.id = oi.order_id
LEFT JOIN license_inventory_entries li
  ON li.organization_id = oi.organization_id
 AND li.source_order_item_id = oi.id
WHERE oi.product_kind = 'license'
  AND (
      o.zero_total_settled_at IS NOT NULL
      OR EXISTS (
          SELECT 1
          FROM order_payment_settlements s
          WHERE s.organization_id = o.organization_id
            AND s.order_id = o.id
      )
  )
GROUP BY oi.organization_id, oi.id, oi.quantity
HAVING COUNT(li.id) <> oi.quantity
ORDER BY oi.organization_id, oi.id
SQL);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'purchase_fulfillment',
                'license_grant_cardinality_mismatch',
                $row->organization_id,
                $row->entity_id,
                ['expected_quantity' => (int) $row->quantity, 'granted_count' => (int) $row->granted_count],
            );
        }

        $rows = DB::select(<<<'SQL'
SELECT
    oi.organization_id::text AS organization_id,
    oi.id::text AS entity_id,
    oi.quantity,
    COUNT(ii.id)::int AS granted_count
FROM order_items oi
JOIN orders o
  ON o.organization_id = oi.organization_id
 AND o.id = oi.order_id
LEFT JOIN internal_exam_inventory_entries ii
  ON ii.organization_id = oi.organization_id
 AND ii.source_order_item_id = oi.id
 AND ii.source_type = 'paid'
WHERE oi.product_kind = 'internal_exam'
  AND (
      o.zero_total_settled_at IS NOT NULL
      OR EXISTS (
          SELECT 1
          FROM order_payment_settlements s
          WHERE s.organization_id = o.organization_id
            AND s.order_id = o.id
      )
  )
GROUP BY oi.organization_id, oi.id, oi.quantity
HAVING COUNT(ii.id) <> oi.quantity
ORDER BY oi.organization_id, oi.id
SQL);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'purchase_fulfillment',
                'internal_exam_grant_cardinality_mismatch',
                $row->organization_id,
                $row->entity_id,
                ['expected_quantity' => (int) $row->quantity, 'granted_count' => (int) $row->granted_count],
            );
        }

        $rows = DB::select(<<<'SQL'
SELECT
    li.organization_id::text AS organization_id,
    li.id::text AS entity_id,
    li.source_order_item_id::text AS source_order_item_id
FROM license_inventory_entries li
JOIN order_items oi
  ON oi.organization_id = li.organization_id
 AND oi.id = li.source_order_item_id
JOIN orders o
  ON o.organization_id = oi.organization_id
 AND o.id = oi.order_id
WHERE li.source_order_item_id IS NOT NULL
  AND o.zero_total_settled_at IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM order_payment_settlements s
      WHERE s.organization_id = o.organization_id
        AND s.order_id = o.id
  )
ORDER BY li.organization_id, li.id
SQL);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'purchase_fulfillment',
                'license_grant_from_unsettled_order',
                $row->organization_id,
                $row->entity_id,
                ['source_order_item_id' => $row->source_order_item_id],
            );
        }

        $rows = DB::select(<<<'SQL'
SELECT
    ii.organization_id::text AS organization_id,
    ii.id::text AS entity_id,
    ii.source_order_item_id::text AS source_order_item_id
FROM internal_exam_inventory_entries ii
JOIN order_items oi
  ON oi.organization_id = ii.organization_id
 AND oi.id = ii.source_order_item_id
JOIN orders o
  ON o.organization_id = oi.organization_id
 AND o.id = oi.order_id
WHERE ii.source_type = 'paid'
  AND ii.source_order_item_id IS NOT NULL
  AND o.zero_total_settled_at IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM order_payment_settlements s
      WHERE s.organization_id = o.organization_id
        AND s.order_id = o.id
  )
ORDER BY ii.organization_id, ii.id
SQL);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'purchase_fulfillment',
                'internal_exam_grant_from_unsettled_order',
                $row->organization_id,
                $row->entity_id,
                ['source_order_item_id' => $row->source_order_item_id],
            );
        }

        return $findings;
    }

    /** @return list<array<string,mixed>> */
    private function licenseInventoryFindings(): array
    {
        $findings = [];

        $rows = DB::select(<<<'SQL'
SELECT
    i.organization_id::text AS organization_id,
    i.id::text AS entity_id,
    i.status,
    COUNT(DISTINCT a.id) FILTER (WHERE a.status = 'assigned')::int AS assigned_count,
    COUNT(DISTINCT a.id) FILTER (WHERE a.status = 'activated')::int AS activated_count,
    COUNT(DISTINCT ac.id)::int AS activation_count
FROM license_inventory_entries i
LEFT JOIN license_assignments a
  ON a.organization_id = i.organization_id
 AND a.license_inventory_entry_id = i.id
LEFT JOIN license_activations ac
  ON ac.organization_id = i.organization_id
 AND ac.license_assignment_id = a.id
GROUP BY i.organization_id, i.id, i.status
ORDER BY i.organization_id, i.id
SQL);

        foreach ($rows as $row) {
            $assigned = (int) $row->assigned_count;
            $activated = (int) $row->activated_count;
            $activations = (int) $row->activation_count;
            $valid = match ((string) $row->status) {
                'available' => $assigned === 0 && $activated === 0 && $activations === 0,
                'assigned' => $assigned === 1 && $activated === 0 && $activations === 0,
                'consumed' => $assigned === 0 && $activated === 1 && $activations === 1,
                'expired', 'adjusted' => true,
                default => false,
            };

            if (! $valid) {
                $findings[] = $this->finding(
                    'license_inventory',
                    'license_inventory_assignment_state_mismatch',
                    $row->organization_id,
                    $row->entity_id,
                    [
                        'inventory_status' => (string) $row->status,
                        'assigned_count' => $assigned,
                        'activated_count' => $activated,
                        'activation_count' => $activations,
                    ],
                );
            }
        }

        return $findings;
    }

    /** @return list<array<string,mixed>> */
    private function internalExamFindings(): array
    {
        $findings = [];

        $rows = DB::select(<<<'SQL'
WITH unit_projection AS (
    SELECT
        organization_id,
        source_type,
        COUNT(*) FILTER (WHERE current_state = 'available')::bigint AS available_units
    FROM internal_exam_inventory_entries
    GROUP BY organization_id, source_type
),
ledger_projection AS (
    SELECT
        i.organization_id,
        i.source_type,
        COALESCE(SUM(l.available_delta), 0)::bigint AS ledger_available
    FROM internal_exam_inventory_entries i
    LEFT JOIN internal_exam_inventory_ledger_entries l
      ON l.organization_id = i.organization_id
     AND l.internal_exam_inventory_entry_id = i.id
    GROUP BY i.organization_id, i.source_type
)
SELECT
    COALESCE(u.organization_id, l.organization_id)::text AS organization_id,
    COALESCE(u.source_type, l.source_type)::text AS source_type,
    COALESCE(u.available_units, 0)::bigint AS available_units,
    COALESCE(l.ledger_available, 0)::bigint AS ledger_available
FROM unit_projection u
FULL OUTER JOIN ledger_projection l
  ON l.organization_id = u.organization_id
 AND l.source_type = u.source_type
WHERE COALESCE(u.available_units, 0) <> COALESCE(l.ledger_available, 0)
ORDER BY 1, 2
SQL);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'internal_exam_inventory',
                'exam_available_projection_ledger_mismatch',
                $row->organization_id,
                $row->source_type,
                [
                    'source_type' => $row->source_type,
                    'available_units' => (int) $row->available_units,
                    'ledger_available' => (int) $row->ledger_available,
                ],
            );
        }

        $rows = DB::select(<<<'SQL'
SELECT
    i.organization_id::text AS organization_id,
    i.id::text AS entity_id,
    COUNT(r.id)::int AS reserved_count
FROM internal_exam_inventory_entries i
LEFT JOIN internal_exam_reservations r
  ON r.organization_id = i.organization_id
 AND r.internal_exam_inventory_entry_id = i.id
 AND r.status = 'reserved'
WHERE i.current_state = 'reserved'
GROUP BY i.organization_id, i.id
HAVING COUNT(r.id) <> 1
ORDER BY i.organization_id, i.id
SQL);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'internal_exam_inventory',
                'reserved_exam_unit_without_exact_reserved_reservation',
                $row->organization_id,
                $row->entity_id,
                ['reserved_count' => (int) $row->reserved_count],
            );
        }

        $rows = DB::select(<<<'SQL'
SELECT
    i.organization_id::text AS organization_id,
    i.id::text AS entity_id,
    COUNT(r.id)::int AS consumed_count
FROM internal_exam_inventory_entries i
LEFT JOIN internal_exam_reservations r
  ON r.organization_id = i.organization_id
 AND r.internal_exam_inventory_entry_id = i.id
 AND r.status = 'consumed'
WHERE i.current_state = 'consumed'
GROUP BY i.organization_id, i.id
HAVING COUNT(r.id) <> 1
ORDER BY i.organization_id, i.id
SQL);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'internal_exam_inventory',
                'consumed_exam_unit_without_exact_consumed_reservation',
                $row->organization_id,
                $row->entity_id,
                ['consumed_count' => (int) $row->consumed_count],
            );
        }

        $rows = DB::select(<<<'SQL'
SELECT
    r.organization_id::text AS organization_id,
    r.id::text AS entity_id,
    r.status AS reservation_status,
    i.current_state AS inventory_state
FROM internal_exam_reservations r
JOIN internal_exam_inventory_entries i
  ON i.organization_id = r.organization_id
 AND i.id = r.internal_exam_inventory_entry_id
WHERE (r.status = 'reserved' AND i.current_state <> 'reserved')
   OR (r.status = 'consumed' AND i.current_state <> 'consumed')
ORDER BY r.organization_id, r.id
SQL);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'internal_exam_inventory',
                'exam_reservation_inventory_state_mismatch',
                $row->organization_id,
                $row->entity_id,
                [
                    'reservation_status' => $row->reservation_status,
                    'inventory_state' => $row->inventory_state,
                ],
            );
        }

        return $findings;
    }

    /** @return list<array<string,mixed>> */
    private function outboxFindings(CarbonImmutable $asOf): array
    {
        $findings = [];

        $rows = DB::select(<<<'SQL'
SELECT
    COALESCE(organization_id::text, 'platform') AS organization_id,
    id::text AS entity_id,
    last_error_code
FROM outbox_messages
WHERE publication_state = 'requires_reconciliation'
ORDER BY organization_id NULLS FIRST, id
SQL);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'outbox_publication',
                'outbox_requires_reconciliation',
                $row->organization_id,
                $row->entity_id,
                ['last_error_code' => $row->last_error_code],
            );
        }

        $rows = DB::select(<<<'SQL'
SELECT
    COALESCE(organization_id::text, 'platform') AS organization_id,
    id::text AS entity_id,
    lease_expires_at
FROM outbox_messages
WHERE publication_state = 'leased'
  AND lease_expires_at < ?
ORDER BY organization_id NULLS FIRST, id
SQL, [$asOf->toDateTimeString()]);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'outbox_publication',
                'outbox_stale_expired_lease',
                $row->organization_id,
                $row->entity_id,
                ['lease_expires_at' => (string) $row->lease_expires_at],
            );
        }

        $rows = DB::select(<<<'SQL'
SELECT
    COALESCE(organization_id::text, 'platform') AS organization_id,
    id::text AS entity_id,
    publication_state
FROM outbox_messages
WHERE publication_state NOT IN ('pending', 'leased', 'published', 'requires_reconciliation')
   OR (
        publication_state = 'pending'
        AND (
            published_at IS NOT NULL
            OR lease_token IS NOT NULL
            OR leased_by IS NOT NULL
            OR lease_expires_at IS NOT NULL
            OR next_attempt_at IS NULL
        )
   )
   OR (
        publication_state = 'leased'
        AND (
            published_at IS NOT NULL
            OR lease_token IS NULL
            OR leased_by IS NULL
            OR lease_expires_at IS NULL
        )
   )
   OR (
        publication_state = 'published'
        AND (
            published_at IS NULL
            OR lease_token IS NOT NULL
            OR leased_by IS NOT NULL
            OR lease_expires_at IS NOT NULL
        )
   )
   OR (
        publication_state = 'requires_reconciliation'
        AND (
            published_at IS NOT NULL
            OR lease_token IS NOT NULL
            OR leased_by IS NOT NULL
            OR lease_expires_at IS NOT NULL
            OR next_attempt_at IS NOT NULL
        )
   )
ORDER BY organization_id NULLS FIRST, id
SQL);
        foreach ($rows as $row) {
            $findings[] = $this->finding(
                'outbox_publication',
                'outbox_state_matrix_invalid',
                $row->organization_id,
                $row->entity_id,
                ['publication_state' => $row->publication_state],
            );
        }

        return $findings;
    }

    /**
     * @param  array<string,mixed>  $details
     * @return array<string,mixed>
     */
    private function finding(
        string $scope,
        string $code,
        ?string $organizationId,
        ?string $entityId,
        array $details = [],
    ): array {
        return [
            'scope' => $scope,
            'code' => $code,
            'organization_id' => $organizationId,
            'entity_id' => $entityId,
            'details' => $details,
        ];
    }
}
