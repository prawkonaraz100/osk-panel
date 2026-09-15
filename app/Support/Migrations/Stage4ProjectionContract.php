<?php

namespace App\Support\Migrations;

use App\Support\Migrations\ProjectionGuards\CalendarResourceClaimsProjectionGuards;
use App\Support\Migrations\ProjectionGuards\NotificationsProjectionGuards;
use App\Support\Migrations\ProjectionGuards\OrganizationActivityProjectionGuards;
use App\Support\Migrations\ProjectionGuards\PurchaseHistoryProjectionGuards;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final class Stage4ProjectionContract
{
    /**
     * @return array{removed_objects:int,deauthorized_paths:int,no_op:bool}
     */
    public static function assertNoDestructiveScope(string $nodeId): array
    {
        self::assertPostgres();
        self::assertGlobalEntryPostconditions();

        match ($nodeId) {
            'MIG-PRJ-CALENDAR-RESOURCE-CLAIMS' => self::assertCalendar(),
            'MIG-PRJ-PURCHASE-HISTORY' => self::assertPurchaseHistory(),
            'MIG-PRJ-ORGANIZATION-ACTIVITY' => self::assertOrganizationActivity(),
            'MIG-PRJ-NOTIFICATIONS' => self::assertNotifications(),
            default => throw new LogicException('Unsupported Stage-4 contract node '.$nodeId.'.'),
        };

        return [
            'removed_objects' => 0,
            'deauthorized_paths' => 0,
            'no_op' => true,
        ];
    }

    private static function assertGlobalEntryPostconditions(): void
    {
        $constraintRow = DB::selectOne(<<<'SQL'
SELECT COUNT(*)::int AS invalid_count
  FROM pg_constraint con
 WHERE (
       COALESCE(obj_description(con.oid, 'pg_constraint'), '') LIKE 'prawkonaraz:foreign-key-write-fence:v1:%'
       OR COALESCE(obj_description(con.oid, 'pg_constraint'), '') LIKE 'prawkonaraz:constraint-write-fence:v1:%'
   )
   AND NOT con.convalidated
SQL);

        if ((int) ($constraintRow->invalid_count ?? 0) !== 0) {
            throw new LogicException('Stage-4 contract requires every signed FK/CHECK validation to remain valid.');
        }

        $caseRow = DB::selectOne(<<<'SQL'
SELECT COUNT(*)::int AS unresolved_count
  FROM event_projection_migration_cases migration_case
 WHERE migration_case.resolution_state <> 'resolved'
    OR NULLIF(BTRIM(COALESCE(migration_case.resolution_kind, '')), '') IS NULL
    OR NULLIF(BTRIM(COALESCE(migration_case.resolution_reason, '')), '') IS NULL
    OR migration_case.reviewed_by_user_id IS NULL
    OR migration_case.reviewed_at IS NULL
SQL);

        if ((int) ($caseRow->unresolved_count ?? 0) !== 0) {
            throw new LogicException('Stage-4 contract is blocked by unresolved migration review evidence.');
        }
    }

    private static function assertCalendar(): void
    {
        TriggerWriteFence::assertInstalled(
            'MIG-PRJ-CALENDAR-RESOURCE-CLAIMS',
            CalendarResourceClaimsProjectionGuards::definitions(),
        );
        Stage4ReviewedReconciliation::assertResolved('MIG-PRJ-CALENDAR-RESOURCE-CLAIMS');

        $legacyRows = (int) DB::table('calendar_events')
            ->where('event_type', '!=', 'general_event')
            ->count();

        if ($legacyRows !== 0) {
            throw new LogicException('Stage-4 contract found a superseded calendar event storage path; reviewed cleanup is required.');
        }
    }

    private static function assertPurchaseHistory(): void
    {
        TriggerWriteFence::assertInstalled(
            'MIG-PRJ-PURCHASE-HISTORY',
            PurchaseHistoryProjectionGuards::definitions(),
        );
        Stage4ReviewedReconciliation::assertResolved('MIG-PRJ-PURCHASE-HISTORY');

        if (Schema::hasColumn('orders', 'status')) {
            throw new LogicException('Stage-4 contract found legacy orders.status authority; separate authorized cleanup is required.');
        }
    }

    private static function assertOrganizationActivity(): void
    {
        TriggerWriteFence::assertInstalled(
            'MIG-PRJ-ORGANIZATION-ACTIVITY',
            OrganizationActivityProjectionGuards::definitions(),
        );
        Stage4ReviewedReconciliation::assertResolved('MIG-PRJ-ORGANIZATION-ACTIVITY');

        foreach (['source_event_id', 'projection_policy_version', 'description_snapshot'] as $column) {
            if (! Schema::hasColumn('organization_activity_events', $column)) {
                throw new LogicException('Stage-4 contract activity projection is missing canonical column '.$column.'.');
            }
        }
    }

    private static function assertNotifications(): void
    {
        TriggerWriteFence::assertInstalled(
            'MIG-PRJ-NOTIFICATIONS',
            NotificationsProjectionGuards::definitions(),
        );
        Stage4ReviewedReconciliation::assertResolved('MIG-PRJ-NOTIFICATIONS');

        foreach (['source_event_id', 'organization_membership_id', 'user_id', 'read_at'] as $column) {
            if (! Schema::hasColumn('notifications', $column)) {
                throw new LogicException('Stage-4 contract notification projection is missing canonical column '.$column.'.');
            }
        }
    }

    private static function assertPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Stage-4 projection contract requires PostgreSQL.');
        }
    }
}
