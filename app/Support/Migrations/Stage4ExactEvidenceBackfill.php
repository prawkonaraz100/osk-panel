<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use LogicException;

final class Stage4ExactEvidenceBackfill
{
    /** @return array{mutated:int,deferred_to_reconcile:int} */
    public static function run(string $nodeId): array
    {
        self::assertPostgres();

        return match ($nodeId) {
            'MIG-FK-PURCHASE_DOWNSTREAM' => self::purchaseDownstream(),
            'MIG-FK-EVENTS' => self::eventRelations(),
            'MIG-TRG-EVENTS' => self::eventRuntime(),
            'MIG-PRJ-CALENDAR-RESOURCE-CLAIMS' => self::calendarResourceClaims(),
            'MIG-PRJ-PURCHASE-HISTORY' => self::purchaseHistory(),
            'MIG-PRJ-ORGANIZATION-ACTIVITY' => self::organizationActivity(),
            'MIG-PRJ-NOTIFICATIONS' => self::notifications(),
            default => throw new LogicException('Unsupported Stage-4 exact-evidence backfill node '.$nodeId.'.'),
        };
    }

    /** @return array{mutated:int,deferred_to_reconcile:int} */
    private static function purchaseDownstream(): array
    {
        $row = DB::selectOne(<<<'SQL'
SELECT (
    (SELECT COUNT(*)
       FROM license_inventory_entries li
       JOIN order_items oi
         ON oi.organization_id = li.organization_id
        AND oi.id = li.source_order_item_id
      WHERE li.source_order_item_id IS NOT NULL
        AND li.source_order_item_grant_ordinal IS NULL
        AND oi.product_kind = 'license'
        AND li.license_product_id = oi.license_product_id)
  + (SELECT COUNT(*)
       FROM internal_exam_inventory_entries ii
       JOIN order_items oi
         ON oi.organization_id = ii.organization_id
        AND oi.id = ii.source_order_item_id
      WHERE ii.source_type = 'paid'
        AND ii.source_order_item_id IS NOT NULL
        AND ii.source_order_item_grant_ordinal IS NULL
        AND oi.product_kind = 'internal_exam')
  + (SELECT COUNT(*)
       FROM service_entitlements se
       JOIN order_items oi
         ON oi.organization_id = se.organization_id
        AND oi.id = se.source_order_item_id
      WHERE se.source_order_item_id IS NOT NULL
        AND se.source_order_item_grant_ordinal IS NULL
        AND oi.product_kind = 'generic_service')
)::int AS deferred_count
SQL);

        return [
            'mutated' => 0,
            'deferred_to_reconcile' => (int) ($row->deferred_count ?? 0),
        ];
    }

    /** @return array{mutated:int,deferred_to_reconcile:int} */
    private static function eventRelations(): array
    {
        $row = DB::selectOne(<<<'SQL'
SELECT (
    (SELECT COUNT(*)
       FROM outbox_messages outbox
       LEFT JOIN domain_events event ON event.id = outbox.domain_event_id
      WHERE event.id IS NULL
         OR event.event_scope IS DISTINCT FROM outbox.event_scope
         OR event.organization_id IS DISTINCT FROM outbox.organization_id)
  + (SELECT COUNT(*)
       FROM organization_activity_events activity
       LEFT JOIN domain_events event
         ON event.id = activity.source_event_id
        AND event.organization_id = activity.organization_id
      WHERE event.id IS NULL)
  + (SELECT COUNT(*)
       FROM notifications notification
       LEFT JOIN domain_events event
         ON event.id = notification.source_event_id
        AND event.organization_id = notification.organization_id
       LEFT JOIN organization_memberships membership
         ON membership.organization_id = notification.organization_id
        AND membership.id = notification.organization_membership_id
        AND membership.user_id = notification.user_id
      WHERE event.id IS NULL OR membership.id IS NULL)
)::int AS deferred_count
SQL);

        return [
            'mutated' => 0,
            'deferred_to_reconcile' => (int) ($row->deferred_count ?? 0),
        ];
    }

    /** @return array{mutated:int,deferred_to_reconcile:int} */
    private static function eventRuntime(): array
    {
        self::inventoryEventProjectionCases();

        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*)::int AS deferred_count
  FROM event_projection_migration_cases
 WHERE resolution_state <> 'resolved'
SQL);

        return [
            'mutated' => 0,
            'deferred_to_reconcile' => (int) ($row->deferred_count ?? 0),
        ];
    }

    /** @return array{mutated:int,deferred_to_reconcile:int} */
    private static function calendarResourceClaims(): array
    {
        $mutated = DB::transaction(function (): int {
            $mutated = 0;
            foreach ([
                'student' => 'student_id',
                'instructor' => 'instructor_id',
                'vehicle' => 'vehicle_id',
                'location' => 'location_id',
            ] as $resourceKind => $resourceColumn) {
                $mutated += DB::affectingStatement(self::calendarClaimInsertSql($resourceKind, $resourceColumn));
            }

            return $mutated;
        });

        $row = DB::selectOne(<<<'SQL'
WITH candidates AS (
    SELECT e.organization_id, 'calendar_event'::text AS owner_kind, e.id AS owner_id,
           'student'::text AS resource_kind, e.student_id AS resource_id, e.starts_at, e.ends_at
      FROM calendar_events e
     WHERE e.status = 'scheduled' AND e.student_id IS NOT NULL
    UNION ALL
    SELECT e.organization_id, 'calendar_event', e.id, 'instructor', e.instructor_id, e.starts_at, e.ends_at
      FROM calendar_events e
     WHERE e.status = 'scheduled' AND e.instructor_id IS NOT NULL
    UNION ALL
    SELECT e.organization_id, 'calendar_event', e.id, 'vehicle', e.vehicle_id, e.starts_at, e.ends_at
      FROM calendar_events e
     WHERE e.status = 'scheduled' AND e.vehicle_id IS NOT NULL
    UNION ALL
    SELECT e.organization_id, 'calendar_event', e.id, 'location', e.location_id, e.starts_at, e.ends_at
      FROM calendar_events e
     WHERE e.status = 'scheduled' AND e.location_id IS NOT NULL
    UNION ALL
    SELECT s.organization_id, 'availability_slot_booking', s.id, 'student', s.booked_student_id, s.starts_at, s.ends_at
      FROM availability_slots s
     WHERE s.status = 'booked' AND s.training_session_id IS NULL AND s.booked_student_id IS NOT NULL
    UNION ALL
    SELECT s.organization_id, 'availability_slot_booking', s.id, 'instructor', s.instructor_id, s.starts_at, s.ends_at
      FROM availability_slots s
     WHERE s.status = 'booked' AND s.training_session_id IS NULL AND s.instructor_id IS NOT NULL
    UNION ALL
    SELECT s.organization_id, 'availability_slot_booking', s.id, 'vehicle', s.vehicle_id, s.starts_at, s.ends_at
      FROM availability_slots s
     WHERE s.status = 'booked' AND s.training_session_id IS NULL AND s.vehicle_id IS NOT NULL
    UNION ALL
    SELECT s.organization_id, 'availability_slot_booking', s.id, 'location', s.location_id, s.starts_at, s.ends_at
      FROM availability_slots s
     WHERE s.status = 'booked' AND s.training_session_id IS NULL AND s.location_id IS NOT NULL
    UNION ALL
    SELECT t.organization_id, 'training_session', t.id, 'student', c.student_id, t.starts_at, t.ends_at
      FROM training_sessions t
      JOIN course_enrollments c
        ON c.organization_id = t.organization_id
       AND c.id = t.course_enrollment_id
     WHERE t.status = 'planned'
    UNION ALL
    SELECT t.organization_id, 'training_session', t.id, 'instructor', t.instructor_id, t.starts_at, t.ends_at
      FROM training_sessions t
     WHERE t.status = 'planned' AND t.instructor_id IS NOT NULL
    UNION ALL
    SELECT t.organization_id, 'training_session', t.id, 'vehicle', t.vehicle_id, t.starts_at, t.ends_at
      FROM training_sessions t
     WHERE t.status = 'planned' AND t.vehicle_id IS NOT NULL
    UNION ALL
    SELECT t.organization_id, 'training_session', t.id, 'location', t.location_id, t.starts_at, t.ends_at
      FROM training_sessions t
     WHERE t.status = 'planned' AND t.location_id IS NOT NULL
)
SELECT COUNT(*)::int AS deferred_count
  FROM candidates c
 WHERE NOT EXISTS (
       SELECT 1
         FROM calendar_resource_claims claim
        WHERE claim.organization_id = c.organization_id
          AND claim.claim_owner_kind = c.owner_kind
          AND claim.claim_owner_id = c.owner_id
          AND claim.starts_at = c.starts_at
          AND claim.ends_at = c.ends_at
          AND ((c.resource_kind = 'student' AND claim.student_id = c.resource_id)
            OR (c.resource_kind = 'instructor' AND claim.instructor_id = c.resource_id)
            OR (c.resource_kind = 'vehicle' AND claim.vehicle_id = c.resource_id)
            OR (c.resource_kind = 'location' AND claim.location_id = c.resource_id))
 )
SQL);

        return [
            'mutated' => $mutated,
            'deferred_to_reconcile' => (int) ($row->deferred_count ?? 0),
        ];
    }

    private static function calendarClaimInsertSql(string $resourceKind, string $resourceColumn): string
    {
        $availabilityColumn = $resourceKind === 'student' ? 'booked_student_id' : $resourceColumn;
        $trainingExpression = $resourceKind === 'student' ? 'course.student_id' : 'session.'.$resourceColumn;

        return <<<SQL
WITH candidates AS (
    SELECT event.organization_id, 'calendar_event'::text AS owner_kind, event.id AS owner_id,
           '{$resourceKind}'::text AS resource_kind, event.{$resourceColumn} AS resource_id,
           event.starts_at, event.ends_at
      FROM calendar_events event
     WHERE event.status = 'scheduled' AND event.{$resourceColumn} IS NOT NULL
    UNION ALL
    SELECT slot.organization_id, 'availability_slot_booking', slot.id,
           '{$resourceKind}', slot.{$availabilityColumn}, slot.starts_at, slot.ends_at
      FROM availability_slots slot
     WHERE slot.status = 'booked'
       AND slot.training_session_id IS NULL
       AND slot.{$availabilityColumn} IS NOT NULL
    UNION ALL
    SELECT session.organization_id, 'training_session', session.id,
           '{$resourceKind}', {$trainingExpression}, session.starts_at, session.ends_at
      FROM training_sessions session
      JOIN course_enrollments course
        ON course.organization_id = session.organization_id
       AND course.id = session.course_enrollment_id
     WHERE session.status = 'planned'
       AND {$trainingExpression} IS NOT NULL
),
all_candidates AS (
    SELECT e.organization_id, 'calendar_event'::text AS owner_kind, e.id AS owner_id,
           'student'::text AS resource_kind, e.student_id AS resource_id, e.starts_at, e.ends_at
      FROM calendar_events e WHERE e.status = 'scheduled' AND e.student_id IS NOT NULL
    UNION ALL SELECT e.organization_id, 'calendar_event', e.id, 'instructor', e.instructor_id, e.starts_at, e.ends_at FROM calendar_events e WHERE e.status = 'scheduled' AND e.instructor_id IS NOT NULL
    UNION ALL SELECT e.organization_id, 'calendar_event', e.id, 'vehicle', e.vehicle_id, e.starts_at, e.ends_at FROM calendar_events e WHERE e.status = 'scheduled' AND e.vehicle_id IS NOT NULL
    UNION ALL SELECT e.organization_id, 'calendar_event', e.id, 'location', e.location_id, e.starts_at, e.ends_at FROM calendar_events e WHERE e.status = 'scheduled' AND e.location_id IS NOT NULL
    UNION ALL SELECT s.organization_id, 'availability_slot_booking', s.id, 'student', s.booked_student_id, s.starts_at, s.ends_at FROM availability_slots s WHERE s.status = 'booked' AND s.training_session_id IS NULL AND s.booked_student_id IS NOT NULL
    UNION ALL SELECT s.organization_id, 'availability_slot_booking', s.id, 'instructor', s.instructor_id, s.starts_at, s.ends_at FROM availability_slots s WHERE s.status = 'booked' AND s.training_session_id IS NULL AND s.instructor_id IS NOT NULL
    UNION ALL SELECT s.organization_id, 'availability_slot_booking', s.id, 'vehicle', s.vehicle_id, s.starts_at, s.ends_at FROM availability_slots s WHERE s.status = 'booked' AND s.training_session_id IS NULL AND s.vehicle_id IS NOT NULL
    UNION ALL SELECT s.organization_id, 'availability_slot_booking', s.id, 'location', s.location_id, s.starts_at, s.ends_at FROM availability_slots s WHERE s.status = 'booked' AND s.training_session_id IS NULL AND s.location_id IS NOT NULL
    UNION ALL SELECT t.organization_id, 'training_session', t.id, 'student', c.student_id, t.starts_at, t.ends_at FROM training_sessions t JOIN course_enrollments c ON c.organization_id = t.organization_id AND c.id = t.course_enrollment_id WHERE t.status = 'planned'
    UNION ALL SELECT t.organization_id, 'training_session', t.id, 'instructor', t.instructor_id, t.starts_at, t.ends_at FROM training_sessions t WHERE t.status = 'planned' AND t.instructor_id IS NOT NULL
    UNION ALL SELECT t.organization_id, 'training_session', t.id, 'vehicle', t.vehicle_id, t.starts_at, t.ends_at FROM training_sessions t WHERE t.status = 'planned' AND t.vehicle_id IS NOT NULL
    UNION ALL SELECT t.organization_id, 'training_session', t.id, 'location', t.location_id, t.starts_at, t.ends_at FROM training_sessions t WHERE t.status = 'planned' AND t.location_id IS NOT NULL
),
unsafe_owners AS (
    SELECT DISTINCT c.owner_kind, c.owner_id, c.organization_id
      FROM all_candidates c
     WHERE EXISTS (
         SELECT 1
           FROM all_candidates other
          WHERE other.organization_id = c.organization_id
            AND other.resource_kind = c.resource_kind
            AND other.resource_id = c.resource_id
            AND (other.owner_kind, other.owner_id) <> (c.owner_kind, c.owner_id)
            AND tstzrange(other.starts_at, other.ends_at, '[)') && tstzrange(c.starts_at, c.ends_at, '[)')
     )
        OR EXISTS (
         SELECT 1
           FROM calendar_resource_claims existing
          WHERE existing.organization_id = c.organization_id
            AND ((c.resource_kind = 'student' AND existing.student_id = c.resource_id)
              OR (c.resource_kind = 'instructor' AND existing.instructor_id = c.resource_id)
              OR (c.resource_kind = 'vehicle' AND existing.vehicle_id = c.resource_id)
              OR (c.resource_kind = 'location' AND existing.location_id = c.resource_id))
            AND existing.occupied_during && tstzrange(c.starts_at, c.ends_at, '[)')
            AND NOT (
                existing.claim_owner_kind = c.owner_kind
                AND existing.claim_owner_id = c.owner_id
                AND existing.starts_at = c.starts_at
                AND existing.ends_at = c.ends_at
            )
     )
        OR EXISTS (
         SELECT 1
           FROM calendar_resource_claims owned
          WHERE owned.organization_id = c.organization_id
            AND owned.claim_owner_kind = c.owner_kind
            AND owned.claim_owner_id = c.owner_id
            AND NOT EXISTS (
                SELECT 1
                  FROM all_candidates expected
                 WHERE expected.organization_id = owned.organization_id
                   AND expected.owner_kind = owned.claim_owner_kind
                   AND expected.owner_id = owned.claim_owner_id
                   AND expected.starts_at = owned.starts_at
                   AND expected.ends_at = owned.ends_at
                   AND (
                       (expected.resource_kind = 'student' AND owned.student_id = expected.resource_id)
                    OR (expected.resource_kind = 'instructor' AND owned.instructor_id = expected.resource_id)
                    OR (expected.resource_kind = 'vehicle' AND owned.vehicle_id = expected.resource_id)
                    OR (expected.resource_kind = 'location' AND owned.location_id = expected.resource_id)
                   )
            )
     )
)
INSERT INTO calendar_resource_claims (
    id, organization_id, claim_owner_kind, claim_owner_id, {$resourceColumn},
    starts_at, ends_at, created_at
)
SELECT
    md5('prawkonaraz|calendar-claim|' || c.organization_id::text || '|' || c.owner_kind || '|' || c.owner_id::text || '|{$resourceKind}|' || c.resource_id::text)::uuid,
    c.organization_id, c.owner_kind, c.owner_id, c.resource_id,
    c.starts_at, c.ends_at, CURRENT_TIMESTAMP
  FROM candidates c
 WHERE NOT EXISTS (
       SELECT 1
         FROM unsafe_owners unsafe
        WHERE unsafe.organization_id = c.organization_id
          AND unsafe.owner_kind = c.owner_kind
          AND unsafe.owner_id = c.owner_id
 )
   AND NOT EXISTS (
       SELECT 1
         FROM calendar_resource_claims exact
        WHERE exact.organization_id = c.organization_id
          AND exact.claim_owner_kind = c.owner_kind
          AND exact.claim_owner_id = c.owner_id
          AND exact.{$resourceColumn} = c.resource_id
          AND exact.starts_at = c.starts_at
          AND exact.ends_at = c.ends_at
 )
SQL;
    }

    /** @return array{mutated:int,deferred_to_reconcile:int} */
    private static function purchaseHistory(): array
    {
        $mutated = DB::transaction(function (): int {
            $nonzero = DB::affectingStatement(<<<'SQL'
UPDATE orders o
   SET booked_at = settlement.settled_at
  FROM order_payment_settlements settlement
  JOIN payments payment
    ON payment.organization_id = settlement.organization_id
   AND payment.id = settlement.payment_id
   AND payment.order_id = settlement.order_id
  JOIN order_fulfillments fulfillment
    ON fulfillment.organization_id = settlement.organization_id
   AND fulfillment.order_id = settlement.order_id
   AND fulfillment.source_kind = 'payment_settlement'
   AND fulfillment.settlement_payment_id = settlement.payment_id
   AND fulfillment.state IN ('pending', 'fulfilled', 'requires_reconciliation')
 WHERE o.organization_id = settlement.organization_id
   AND o.id = settlement.order_id
   AND o.total_amount_minor > 0
   AND o.booked_at IS NULL
   AND o.zero_total_settled_at IS NULL
   AND payment.status = 'confirmed'
   AND payment.confirmed_at IS NOT NULL
   AND payment.amount_minor = o.total_amount_minor
   AND payment.currency = o.currency
   AND (
       (
           settlement.confirmation_source = 'reconciliation'
           AND settlement.source_payment_event_id IS NULL
           AND settlement.reconciled_by_user_id IS NOT NULL
           AND NULLIF(BTRIM(settlement.reconciliation_reason), '') IS NOT NULL
       )
       OR (
           settlement.confirmation_source = 'provider_event'
           AND settlement.source_payment_event_id IS NOT NULL
           AND settlement.reconciled_by_user_id IS NULL
           AND settlement.reconciliation_reason IS NULL
           AND EXISTS (
               SELECT 1
                 FROM payment_events event
                WHERE event.id = settlement.source_payment_event_id
                  AND event.organization_id = settlement.organization_id
                  AND event.payment_id = settlement.payment_id
                  AND event.normalized_outcome = 'confirmed'
           )
       )
   )
SQL);

            $zero = DB::affectingStatement(<<<'SQL'
UPDATE orders o
   SET booked_at = o.zero_total_settled_at
  FROM order_fulfillments fulfillment
 WHERE fulfillment.organization_id = o.organization_id
   AND fulfillment.order_id = o.id
   AND fulfillment.source_kind = 'zero_total'
   AND fulfillment.settlement_payment_id IS NULL
   AND fulfillment.state IN ('pending', 'fulfilled', 'requires_reconciliation')
   AND o.total_amount_minor = 0
   AND o.zero_total_settled_at IS NOT NULL
   AND o.booked_at IS NULL
   AND NOT EXISTS (
       SELECT 1
         FROM order_payment_settlements settlement
        WHERE settlement.organization_id = o.organization_id
          AND settlement.order_id = o.id
   )
SQL);

            return $nonzero + $zero;
        });

        $row = DB::selectOne(<<<'SQL'
WITH paid_source AS (
    SELECT o.organization_id, o.id AS order_id, settlement.settled_at AS expected_booked_at,
           CASE WHEN fulfillment.order_id IS NULL THEN false ELSE true END AS fulfillment_valid
      FROM orders o
      JOIN order_payment_settlements settlement
        ON settlement.organization_id = o.organization_id
       AND settlement.order_id = o.id
      LEFT JOIN order_fulfillments fulfillment
        ON fulfillment.organization_id = settlement.organization_id
       AND fulfillment.order_id = settlement.order_id
       AND fulfillment.source_kind = 'payment_settlement'
       AND fulfillment.settlement_payment_id = settlement.payment_id
       AND fulfillment.state IN ('pending', 'fulfilled', 'requires_reconciliation')
     WHERE o.total_amount_minor > 0
    UNION ALL
    SELECT o.organization_id, o.id, o.zero_total_settled_at,
           CASE WHEN fulfillment.order_id IS NULL THEN false ELSE true END
      FROM orders o
      LEFT JOIN order_fulfillments fulfillment
        ON fulfillment.organization_id = o.organization_id
       AND fulfillment.order_id = o.id
       AND fulfillment.source_kind = 'zero_total'
       AND fulfillment.settlement_payment_id IS NULL
       AND fulfillment.state IN ('pending', 'fulfilled', 'requires_reconciliation')
     WHERE o.total_amount_minor = 0
       AND o.zero_total_settled_at IS NOT NULL
       AND NOT EXISTS (
           SELECT 1 FROM order_payment_settlements settlement
            WHERE settlement.organization_id = o.organization_id
              AND settlement.order_id = o.id
       )
)
SELECT (
    (SELECT COUNT(*)
       FROM paid_source source
       JOIN orders o
         ON o.organization_id = source.organization_id
        AND o.id = source.order_id
      WHERE NOT source.fulfillment_valid
         OR o.booked_at IS DISTINCT FROM source.expected_booked_at)
  + (SELECT COUNT(*)
       FROM orders o
      WHERE o.booked_at IS NOT NULL
        AND o.zero_total_settled_at IS NULL
        AND NOT EXISTS (
            SELECT 1 FROM order_payment_settlements settlement
             WHERE settlement.organization_id = o.organization_id
               AND settlement.order_id = o.id
        ))
)::int AS deferred_count
SQL);

        return [
            'mutated' => $mutated,
            'deferred_to_reconcile' => (int) ($row->deferred_count ?? 0),
        ];
    }

    /** @return array{mutated:int,deferred_to_reconcile:int} */
    private static function organizationActivity(): array
    {
        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*)::int AS deferred_count
  FROM organization_activity_events activity
  LEFT JOIN domain_events event
    ON event.id = activity.source_event_id
   AND event.organization_id = activity.organization_id
  LEFT JOIN activity_projection_policy_revisions revision
    ON revision.event_type = activity.event_type
   AND revision.policy_version = activity.projection_policy_version
 WHERE event.id IS NULL
    OR event.event_scope <> 'organization'
    OR activity.event_type IS DISTINCT FROM event.event_type
    OR activity.occurred_at IS DISTINCT FROM event.occurred_at
    OR revision.event_type IS NULL
    OR NULLIF(BTRIM(activity.description_snapshot), '') IS NULL
    OR (activity.safe_payload IS NOT NULL AND jsonb_typeof(activity.safe_payload) <> 'object')
SQL);

        return [
            'mutated' => 0,
            'deferred_to_reconcile' => (int) ($row->deferred_count ?? 0),
        ];
    }

    /** @return array{mutated:int,deferred_to_reconcile:int} */
    private static function notifications(): array
    {
        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*)::int AS deferred_count
  FROM notifications notification
  LEFT JOIN domain_events event
    ON event.id = notification.source_event_id
   AND event.organization_id = notification.organization_id
  LEFT JOIN organization_memberships membership
    ON membership.organization_id = notification.organization_id
   AND membership.id = notification.organization_membership_id
   AND membership.user_id = notification.user_id
 WHERE event.id IS NULL
    OR event.event_scope <> 'organization'
    OR membership.id IS NULL
    OR notification.audience_kind NOT IN ('direct_membership', 'organization_broadcast')
SQL);

        return [
            'mutated' => 0,
            'deferred_to_reconcile' => (int) ($row->deferred_count ?? 0),
        ];
    }

    private static function inventoryEventProjectionCases(): void
    {
        self::inventoryCaseRows(
            'audit_logs',
            'audit_contract_unresolved',
            'ambiguous_or_conflicting',
            DB::select(<<<'SQL'
SELECT audit.id::text AS id,
       audit.audit_scope,
       audit.organization_id::text AS organization_id,
       audit.actor_kind,
       audit.actor_organization_membership_id::text AS actor_membership_id,
       audit.actor_user_id::text AS actor_user_id,
       audit.action,
       audit.audit_policy_version,
       audit.entity_reference_mode
  FROM audit_logs audit
  LEFT JOIN audit_action_policy_revisions revision
    ON revision.action = audit.action
   AND revision.policy_version = audit.audit_policy_version
 WHERE audit.audit_policy_version < 1
    OR revision.action IS NULL
    OR (audit.audit_scope = 'organization' AND audit.organization_id IS NULL)
    OR (audit.audit_scope = 'platform_global' AND audit.organization_id IS NOT NULL)
    OR audit.audit_scope NOT IN ('organization', 'platform_global')
    OR audit.actor_kind NOT IN ('organization_membership', 'global_user', 'system')
    OR audit.entity_reference_mode NOT IN ('none', 'tenant_relational', 'global_relational', 'snapshot_only')
    OR (
        audit.actor_kind = 'organization_membership'
        AND (
            audit.audit_scope <> 'organization'
            OR audit.actor_organization_membership_id IS NULL
            OR audit.actor_user_id IS NULL
        )
    )
    OR (
        audit.actor_kind = 'global_user'
        AND (
            audit.audit_scope <> 'platform_global'
            OR audit.actor_organization_membership_id IS NOT NULL
            OR audit.actor_user_id IS NULL
        )
    )
    OR (
        audit.actor_kind = 'system'
        AND (
            audit.actor_organization_membership_id IS NOT NULL
            OR audit.actor_user_id IS NOT NULL
        )
    )
SQL),
        );

        self::inventoryCaseRows(
            'domain_events',
            'domain_event_contract_unresolved',
            'ambiguous_or_conflicting',
            DB::select(<<<'SQL'
SELECT event.id::text AS id,
       event.event_scope,
       event.organization_id::text AS organization_id,
       event.event_type,
       event.aggregate_reference_mode,
       event.required_audit_log_id::text AS required_audit_log_id
  FROM domain_events event
  LEFT JOIN audit_logs audit
    ON audit.id = event.required_audit_log_id
 WHERE event.event_scope NOT IN ('organization', 'platform_global')
    OR event.aggregate_reference_mode NOT IN ('none', 'tenant_relational', 'global_relational', 'snapshot_only')
    OR (event.event_scope = 'organization' AND event.organization_id IS NULL)
    OR (event.event_scope = 'platform_global' AND event.organization_id IS NOT NULL)
    OR (
        event.required_audit_log_id IS NOT NULL
        AND (
            audit.id IS NULL
            OR audit.audit_scope IS DISTINCT FROM event.event_scope
            OR audit.organization_id IS DISTINCT FROM event.organization_id
        )
    )
SQL),
        );

        self::inventoryCaseRows(
            'outbox_messages',
            'outbox_contract_unresolved',
            'ambiguous_or_conflicting',
            DB::select(<<<'SQL'
SELECT outbox.id::text AS id,
       outbox.domain_event_id::text AS domain_event_id,
       outbox.event_scope,
       outbox.organization_id::text AS organization_id,
       outbox.event_type,
       outbox.aggregate_type,
       outbox.aggregate_id,
       outbox.request_id::text AS request_id,
       outbox.publication_state,
       outbox.lease_version,
       outbox.attempts,
       outbox.attempts_in_cycle,
       outbox.replay_count
  FROM outbox_messages outbox
  LEFT JOIN domain_events event ON event.id = outbox.domain_event_id
 WHERE event.id IS NULL
    OR outbox.publication_state = 'requires_reconciliation'
    OR outbox.event_scope IS DISTINCT FROM event.event_scope
    OR outbox.organization_id IS DISTINCT FROM event.organization_id
    OR outbox.event_type IS DISTINCT FROM event.event_type
    OR outbox.aggregate_type IS DISTINCT FROM event.aggregate_type
    OR outbox.aggregate_id IS DISTINCT FROM event.aggregate_id
    OR outbox.request_id IS DISTINCT FROM event.request_id
    OR outbox.publication_state NOT IN ('pending', 'leased', 'published', 'requires_reconciliation')
    OR outbox.lease_version < 0
    OR outbox.attempts < 0
    OR outbox.attempts_in_cycle < 0
    OR outbox.replay_count < 0
    OR (
        outbox.publication_state = 'leased'
        AND (
            outbox.lease_token IS NULL
            OR outbox.leased_by IS NULL
            OR outbox.lease_expires_at IS NULL
            OR outbox.published_at IS NOT NULL
        )
    )
    OR (
        outbox.publication_state = 'published'
        AND (
            outbox.lease_token IS NOT NULL
            OR outbox.leased_by IS NOT NULL
            OR outbox.lease_expires_at IS NOT NULL
            OR outbox.published_at IS NULL
        )
    )
    OR (
        outbox.publication_state IN ('pending', 'requires_reconciliation')
        AND (
            outbox.lease_token IS NOT NULL
            OR outbox.leased_by IS NOT NULL
            OR outbox.lease_expires_at IS NOT NULL
            OR outbox.published_at IS NOT NULL
        )
    )
SQL),
        );

        self::inventoryCaseRows(
            'organization_activity_events',
            'activity_projection_contract_unresolved',
            'partial_but_nonconflicting',
            DB::select(<<<'SQL'
SELECT activity.id::text AS id,
       activity.organization_id::text AS organization_id,
       activity.source_event_id::text AS source_event_id,
       activity.projection_policy_version,
       activity.event_type,
       activity.occurred_at::text AS occurred_at,
       activity.actor_reference_mode,
       activity.actor_organization_membership_id::text AS actor_membership_id,
       activity.actor_user_id::text AS actor_user_id,
       activity.subject_reference_mode,
       activity.subject_type,
       activity.subject_id
  FROM organization_activity_events activity
  LEFT JOIN domain_events event
    ON event.id = activity.source_event_id
   AND event.organization_id = activity.organization_id
  LEFT JOIN activity_projection_policy_revisions revision
    ON revision.event_type = activity.event_type
   AND revision.policy_version = activity.projection_policy_version
 WHERE event.id IS NULL
    OR event.event_scope <> 'organization'
    OR activity.event_type IS DISTINCT FROM event.event_type
    OR activity.occurred_at IS DISTINCT FROM event.occurred_at
    OR revision.event_type IS NULL
    OR NULLIF(BTRIM(activity.description_snapshot), '') IS NULL
    OR (activity.safe_payload IS NOT NULL AND jsonb_typeof(activity.safe_payload) <> 'object')
SQL),
        );

        self::inventoryCaseRows(
            'notifications',
            'notification_projection_contract_unresolved',
            'partial_but_nonconflicting',
            DB::select(<<<'SQL'
SELECT notification.id::text AS id,
       notification.organization_id::text AS organization_id,
       notification.source_event_id::text AS source_event_id,
       notification.organization_membership_id::text AS membership_id,
       notification.user_id::text AS user_id,
       notification.audience_kind,
       notification.type,
       notification.read_at::text AS read_at
  FROM notifications notification
  LEFT JOIN domain_events event
    ON event.id = notification.source_event_id
   AND event.organization_id = notification.organization_id
  LEFT JOIN organization_memberships membership
    ON membership.organization_id = notification.organization_id
   AND membership.id = notification.organization_membership_id
   AND membership.user_id = notification.user_id
 WHERE event.id IS NULL
    OR event.event_scope <> 'organization'
    OR membership.id IS NULL
    OR notification.audience_kind NOT IN ('direct_membership', 'organization_broadcast')
SQL),
        );
    }

    /**
     * @param  array<int, object>  $rows
     */
    private static function inventoryCaseRows(
        string $sourceTable,
        string $issueCode,
        string $evidenceClass,
        array $rows,
    ): void {
        foreach ($rows as $row) {
            $safe = (array) $row;
            $sourceRowId = (string) ($safe['id'] ?? '');
            if ($sourceRowId === '') {
                throw new LogicException("{$sourceTable} reconciliation inventory row has no source id.");
            }

            ksort($safe);
            $fingerprint = hash('sha256', json_encode($safe, JSON_THROW_ON_ERROR));
            $existing = DB::table('event_projection_migration_cases')
                ->where('source_table', $sourceTable)
                ->where('source_row_id', $sourceRowId)
                ->where('issue_code', $issueCode)
                ->first();

            if ($existing !== null) {
                if ((string) $existing->source_row_fingerprint !== $fingerprint) {
                    throw new LogicException(
                        "{$sourceTable}/{$sourceRowId}/{$issueCode} changed after reconciliation inventory; manual review is required.",
                    );
                }

                continue;
            }

            DB::table('event_projection_migration_cases')->insert([
                'id' => self::deterministicUuid("event-projection-case|{$sourceTable}|{$sourceRowId}|{$issueCode}"),
                'source_table' => $sourceTable,
                'source_row_id' => $sourceRowId,
                'source_row_fingerprint' => $fingerprint,
                'issue_code' => $issueCode,
                'evidence_class' => $evidenceClass,
                'resolution_state' => 'open',
                'evidence_reference_json_safe' => json_encode([
                    'source_table' => $sourceTable,
                    'issue_code' => $issueCode,
                ], JSON_THROW_ON_ERROR),
                'resolution_kind' => null,
                'resolution_reason' => null,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
                'created_at' => now(),
            ]);
        }
    }

    private static function deterministicUuid(string $material): string
    {
        $hex = substr(hash('sha256', $material), 0, 32);

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }

    private static function assertPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Stage-4 exact-evidence backfill requires PostgreSQL.');
        }
    }
}
