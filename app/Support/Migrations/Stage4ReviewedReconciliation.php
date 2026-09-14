<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use LogicException;

final class Stage4ReviewedReconciliation
{
    public static function assertResolved(string $nodeId): void
    {
        self::assertPostgres();

        $unresolved = match ($nodeId) {
            'MIG-FK-PURCHASE_DOWNSTREAM' => self::purchaseDownstreamUnresolved(),
            'MIG-FK-EVENTS' => self::eventRelationsUnresolved(),
            'MIG-TRG-EVENTS' => self::eventCasesUnresolved(),
            'MIG-PRJ-CALENDAR-RESOURCE-CLAIMS' => self::calendarClaimsUnresolved(),
            'MIG-PRJ-PURCHASE-HISTORY' => self::purchaseHistoryUnresolved(),
            'MIG-PRJ-ORGANIZATION-ACTIVITY' => self::activityUnresolved(),
            'MIG-PRJ-NOTIFICATIONS' => self::notificationsUnresolved(),
            default => throw new LogicException('Unsupported Stage-4 reconcile node '.$nodeId.'.'),
        };

        if ($unresolved !== 0) {
            throw new LogicException(
                "Stage-4 reconcile node {$nodeId} still has {$unresolved} unresolved case(s); reviewed exact remediation is required.",
            );
        }
    }

    private static function purchaseDownstreamUnresolved(): int
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
)::int AS unresolved_count
SQL);

        return (int) ($row->unresolved_count ?? 0);
    }

    private static function eventRelationsUnresolved(): int
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
)::int AS unresolved_count
SQL);

        return (int) ($row->unresolved_count ?? 0);
    }

    private static function eventCasesUnresolved(): int
    {
        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*)::int AS unresolved_count
  FROM event_projection_migration_cases migration_case
 WHERE migration_case.resolution_state <> 'resolved'
    OR NULLIF(BTRIM(COALESCE(migration_case.resolution_kind, '')), '') IS NULL
    OR NULLIF(BTRIM(COALESCE(migration_case.resolution_reason, '')), '') IS NULL
    OR migration_case.reviewed_by_user_id IS NULL
    OR migration_case.reviewed_at IS NULL
SQL);

        return (int) ($row->unresolved_count ?? 0);
    }

    private static function calendarClaimsUnresolved(): int
    {
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
),
missing AS (
    SELECT c.*
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
),
extra AS (
    SELECT claim.id
      FROM calendar_resource_claims claim
     WHERE NOT EXISTS (
         SELECT 1
           FROM candidates c
          WHERE c.organization_id = claim.organization_id
            AND c.owner_kind = claim.claim_owner_kind
            AND c.owner_id = claim.claim_owner_id
            AND c.starts_at = claim.starts_at
            AND c.ends_at = claim.ends_at
            AND ((c.resource_kind = 'student' AND claim.student_id = c.resource_id)
              OR (c.resource_kind = 'instructor' AND claim.instructor_id = c.resource_id)
              OR (c.resource_kind = 'vehicle' AND claim.vehicle_id = c.resource_id)
              OR (c.resource_kind = 'location' AND claim.location_id = c.resource_id))
     )
)
SELECT ((SELECT COUNT(*) FROM missing) + (SELECT COUNT(*) FROM extra))::int AS unresolved_count
SQL);

        return (int) ($row->unresolved_count ?? 0);
    }

    private static function purchaseHistoryUnresolved(): int
    {
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
)::int AS unresolved_count
SQL);

        return (int) ($row->unresolved_count ?? 0);
    }

    private static function activityUnresolved(): int
    {
        $row = DB::selectOne(<<<'SQL'
SELECT (
    (SELECT COUNT(*)
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
         OR (activity.safe_payload IS NOT NULL AND jsonb_typeof(activity.safe_payload) <> 'object'))
  + (SELECT COUNT(*)
       FROM event_projection_migration_cases migration_case
      WHERE migration_case.source_table = 'organization_activity_events'
        AND (
            migration_case.resolution_state <> 'resolved'
            OR NULLIF(BTRIM(COALESCE(migration_case.resolution_kind, '')), '') IS NULL
            OR NULLIF(BTRIM(COALESCE(migration_case.resolution_reason, '')), '') IS NULL
            OR migration_case.reviewed_by_user_id IS NULL
            OR migration_case.reviewed_at IS NULL
        ))
)::int AS unresolved_count
SQL);

        return (int) ($row->unresolved_count ?? 0);
    }

    private static function notificationsUnresolved(): int
    {
        $row = DB::selectOne(<<<'SQL'
SELECT (
    (SELECT COUNT(*)
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
         OR notification.audience_kind NOT IN ('direct_membership', 'organization_broadcast'))
  + (SELECT COUNT(*)
       FROM event_projection_migration_cases migration_case
      WHERE migration_case.source_table = 'notifications'
        AND (
            migration_case.resolution_state <> 'resolved'
            OR NULLIF(BTRIM(COALESCE(migration_case.resolution_kind, '')), '') IS NULL
            OR NULLIF(BTRIM(COALESCE(migration_case.resolution_reason, '')), '') IS NULL
            OR migration_case.reviewed_by_user_id IS NULL
            OR migration_case.reviewed_at IS NULL
        ))
)::int AS unresolved_count
SQL);

        return (int) ($row->unresolved_count ?? 0);
    }

    private static function assertPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Stage-4 reviewed reconciliation requires PostgreSQL.');
        }
    }
}
