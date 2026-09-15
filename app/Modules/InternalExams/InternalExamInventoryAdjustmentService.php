<?php

namespace App\Modules\InternalExams;

use App\Modules\AuditNotification\AtomicAuditOutbox;
use App\Modules\IdentityTenant\TenantAuthorizer;
use App\Modules\ResourcesCore\ResourceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @phpstan-type InventoryUnitRow object{id:mixed,source_type:mixed,current_state:mixed}
 */
final class InternalExamInventoryAdjustmentService
{
    private const SOURCE_TYPES = ['free', 'paid', 'adjustment'];

    public function __construct(
        private readonly TenantAuthorizer $tenantAuthorizer,
        private readonly AtomicAuditOutbox $auditOutbox,
    ) {}

    /**
     * @return array{
     *   id:string,
     *   delta:int,
     *   reason:string,
     *   related_attempt_id:?string,
     *   applied_units:int,
     *   created_at:string
     * }
     */
    public function adjust(
        string $sessionId,
        int $delta,
        string $reason,
        ?string $relatedAttemptId,
        string $requestId,
    ): array {
        $reason = trim($reason);
        if ($delta === 0) {
            throw ResourceDomainException::rule('Internal exam inventory adjustment delta must be non-zero.');
        }
        if ($reason === '') {
            throw ResourceDomainException::rule('Internal exam inventory adjustment reason is required.');
        }

        $activeMembership = $this->tenantAuthorizer->activeMembershipForSession($sessionId);
        $membership = $this->tenantAuthorizer->requireOrganizationPermission(
            $sessionId,
            $activeMembership['organization_id'],
            'exams.inventory.adjust',
        );

        return DB::transaction(function () use ($membership, $delta, $reason, $relatedAttemptId, $requestId): array {
            $organizationId = $membership['organization_id'];
            $this->assertProjectionEquivalent($organizationId);

            if ($relatedAttemptId !== null) {
                $attempt = DB::table('internal_exam_attempts')
                    ->where('organization_id', $organizationId)
                    ->where('id', $relatedAttemptId)
                    ->lockForUpdate()
                    ->first(['id']);
                if ($attempt === null) {
                    throw ResourceDomainException::notFound('Related internal exam attempt not found.');
                }

                if ($delta > 0) {
                    $hasConsumedReservation = DB::table('internal_exam_reservations')
                        ->where('organization_id', $organizationId)
                        ->where('internal_exam_attempt_id', $relatedAttemptId)
                        ->where('status', 'consumed')
                        ->exists();
                    if (! $hasConsumedReservation) {
                        throw ResourceDomainException::conflict(
                            'A positive adjustment related to an attempt requires already-consumed inventory.',
                        );
                    }
                }
            }

            $adjustmentId = (string) Str::uuid7();
            $now = CarbonImmutable::now();
            DB::table('internal_exam_inventory_adjustments')->insert([
                'id' => $adjustmentId,
                'organization_id' => $organizationId,
                'delta' => $delta,
                'reason' => $reason,
                'related_attempt_id' => $relatedAttemptId,
                'created_by_user_id' => $membership['user_id'],
                'created_at' => $now,
            ]);

            $appliedUnits = $delta > 0
                ? $this->grantAdjustmentUnits(
                    $organizationId,
                    $adjustmentId,
                    $membership['user_id'],
                    $delta,
                    $reason,
                    $now,
                )
                : $this->withdrawAvailableUnits(
                    $organizationId,
                    $adjustmentId,
                    $membership['user_id'],
                    abs($delta),
                    $reason,
                    $now,
                );

            if ($appliedUnits !== abs($delta)) {
                throw ResourceDomainException::conflict('Internal exam inventory adjustment effect count is inconsistent.');
            }

            $this->assertProjectionEquivalent($organizationId);

            $this->auditOutbox->recordOrganizationEvent(
                $organizationId,
                $membership['id'],
                $membership['user_id'],
                'internal_exam.inventory.adjusted',
                'internal_exam_inventory_adjustment',
                $adjustmentId,
                $requestId,
                ['fields' => ['available_inventory'], 'state' => 'before_adjustment'],
                ['fields' => ['available_inventory'], 'state' => 'adjusted'],
                $reason,
            );

            return [
                'id' => $adjustmentId,
                'delta' => $delta,
                'reason' => $reason,
                'related_attempt_id' => $relatedAttemptId,
                'applied_units' => $appliedUnits,
                'created_at' => $now->toIso8601String(),
            ];
        });
    }

    private function grantAdjustmentUnits(
        string $organizationId,
        string $adjustmentId,
        string $actorUserId,
        int $count,
        string $reason,
        CarbonImmutable $now,
    ): int {
        for ($index = 0; $index < $count; $index++) {
            $inventoryId = (string) Str::uuid7();
            DB::table('internal_exam_inventory_entries')->insert([
                'id' => $inventoryId,
                'organization_id' => $organizationId,
                'source_type' => 'adjustment',
                'source_order_item_id' => null,
                'source_adjustment_id' => $adjustmentId,
                'current_state' => 'available',
                'created_at' => $now,
            ]);
            DB::table('internal_exam_inventory_ledger_entries')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organizationId,
                'internal_exam_inventory_entry_id' => $inventoryId,
                'internal_exam_reservation_id' => null,
                'internal_exam_attempt_id' => null,
                'internal_exam_inventory_adjustment_id' => $adjustmentId,
                'event_sequence' => 1,
                'event_type' => 'unit_adjustment_granted',
                'available_delta' => 1,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);
        }

        return (int) DB::table('internal_exam_inventory_entries')
            ->where('organization_id', $organizationId)
            ->where('source_adjustment_id', $adjustmentId)
            ->where('source_type', 'adjustment')
            ->where('current_state', 'available')
            ->count();
    }

    private function withdrawAvailableUnits(
        string $organizationId,
        string $adjustmentId,
        string $actorUserId,
        int $count,
        string $reason,
        CarbonImmutable $now,
    ): int {
        $units = DB::table('internal_exam_inventory_entries')
            ->where('organization_id', $organizationId)
            ->where('current_state', 'available')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($count)
            ->lockForUpdate()
            ->get(['id', 'source_type', 'current_state']);

        if ($units->count() !== $count) {
            throw ResourceDomainException::conflict(
                'Insufficient available internal exam inventory for the requested negative adjustment.',
            );
        }

        foreach ($units as $unit) {
            /** @var InventoryUnitRow $unit */
            $inventoryId = (string) $unit->id;
            if (! in_array((string) $unit->source_type, self::SOURCE_TYPES, true)
                || (string) $unit->current_state !== 'available') {
                throw ResourceDomainException::conflict('Selected inventory unit is not eligible for adjustment.');
            }

            $ledgerAvailable = (int) DB::table('internal_exam_inventory_ledger_entries')
                ->where('organization_id', $organizationId)
                ->where('internal_exam_inventory_entry_id', $inventoryId)
                ->sum('available_delta');
            $lastSequence = (int) DB::table('internal_exam_inventory_ledger_entries')
                ->where('organization_id', $organizationId)
                ->where('internal_exam_inventory_entry_id', $inventoryId)
                ->max('event_sequence');
            $lastEvent = DB::table('internal_exam_inventory_ledger_entries')
                ->where('organization_id', $organizationId)
                ->where('internal_exam_inventory_entry_id', $inventoryId)
                ->where('event_sequence', $lastSequence)
                ->value('event_type');

            if ($ledgerAvailable !== 1
                || $lastSequence < 1
                || ! in_array((string) $lastEvent, ['unit_granted', 'unit_adjustment_granted', 'unit_released'], true)) {
                throw ResourceDomainException::conflict(
                    'Selected inventory unit ledger does not prove current availability.',
                );
            }

            $updated = DB::table('internal_exam_inventory_entries')
                ->where('organization_id', $organizationId)
                ->where('id', $inventoryId)
                ->where('current_state', 'available')
                ->update(['current_state' => 'adjusted_out']);
            if ($updated !== 1) {
                throw ResourceDomainException::conflict('Inventory unit changed during negative adjustment.');
            }

            DB::table('internal_exam_inventory_ledger_entries')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organizationId,
                'internal_exam_inventory_entry_id' => $inventoryId,
                'internal_exam_reservation_id' => null,
                'internal_exam_attempt_id' => null,
                'internal_exam_inventory_adjustment_id' => $adjustmentId,
                'event_sequence' => $lastSequence + 1,
                'event_type' => 'unit_adjusted_out',
                'available_delta' => -1,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);
        }

        return (int) DB::table('internal_exam_inventory_ledger_entries')
            ->where('organization_id', $organizationId)
            ->where('internal_exam_inventory_adjustment_id', $adjustmentId)
            ->where('event_type', 'unit_adjusted_out')
            ->count();
    }

    private function assertProjectionEquivalent(string $organizationId): void
    {
        foreach (self::SOURCE_TYPES as $sourceType) {
            $ledgerAvailable = (int) DB::table('internal_exam_inventory_ledger_entries as l')
                ->join('internal_exam_inventory_entries as e', function ($join): void {
                    $join->on('e.id', '=', 'l.internal_exam_inventory_entry_id')
                        ->on('e.organization_id', '=', 'l.organization_id');
                })
                ->where('l.organization_id', $organizationId)
                ->where('e.source_type', $sourceType)
                ->sum('l.available_delta');
            $operationalAvailable = (int) DB::table('internal_exam_inventory_entries')
                ->where('organization_id', $organizationId)
                ->where('source_type', $sourceType)
                ->where('current_state', 'available')
                ->count();

            if ($ledgerAvailable < 0 || $ledgerAvailable !== $operationalAvailable) {
                throw ResourceDomainException::conflict(
                    'Internal exam inventory ledger and operational availability projection are inconsistent.',
                );
            }
        }
    }
}
