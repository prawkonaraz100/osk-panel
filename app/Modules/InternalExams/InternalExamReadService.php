<?php

namespace App\Modules\InternalExams;

use App\Modules\ResourcesCore\ResourceDomainException;
use App\Modules\StudentsCourses\StudentCourseScopeAuthorizer;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type LedgerAvailableRow object{source_type:mixed,available_count:mixed}
 * @phpstan-type StateCountRow object{source_type:mixed,current_state:mixed,state_count:mixed}
 * @phpstan-type InventoryEntryRow object{id:mixed,source_type:mixed,current_state:mixed,created_at:mixed}
 * @phpstan-type CategoryRow object{id:mixed,code:mixed}
 */
final class InternalExamReadService
{
    private const SOURCE_TYPES = ['free', 'paid', 'adjustment'];

    private const STATES = ['available', 'reserved', 'consumed', 'adjusted_out'];

    public function __construct(private readonly StudentCourseScopeAuthorizer $scope) {}

    /** @return array<string,mixed> */
    public function inventory(string $sessionId): array
    {
        $visibility = $this->scope->visibility($sessionId, 'exams.view');
        $organizationId = $visibility['membership']['organization_id'];

        $ledgerAvailable = array_fill_keys(self::SOURCE_TYPES, 0);
        DB::table('internal_exam_inventory_ledger_entries as l')
            ->join('internal_exam_inventory_entries as e', function ($join): void {
                $join->on('e.id', '=', 'l.internal_exam_inventory_entry_id')
                    ->on('e.organization_id', '=', 'l.organization_id');
            })
            ->where('l.organization_id', $organizationId)
            ->groupBy('e.source_type')
            ->select(['e.source_type'])
            ->selectRaw('COALESCE(SUM(l.available_delta), 0) AS available_count')
            ->get()
            ->each(function (object $row) use (&$ledgerAvailable): void {
                /** @var LedgerAvailableRow $projection */
                $projection = $row;
                $sourceType = (string) $projection->source_type;
                if (! array_key_exists($sourceType, $ledgerAvailable)) {
                    throw ResourceDomainException::conflict('Internal exam inventory has an unsupported source type.');
                }

                $ledgerAvailable[$sourceType] = (int) $projection->available_count;
            });

        $stateCounts = [];
        foreach (self::SOURCE_TYPES as $sourceType) {
            $stateCounts[$sourceType] = array_fill_keys(self::STATES, 0);
        }

        DB::table('internal_exam_inventory_entries')
            ->where('organization_id', $organizationId)
            ->groupBy('source_type', 'current_state')
            ->select(['source_type', 'current_state'])
            ->selectRaw('COUNT(*) AS state_count')
            ->get()
            ->each(function (object $row) use (&$stateCounts): void {
                /** @var StateCountRow $projection */
                $projection = $row;
                $sourceType = (string) $projection->source_type;
                $state = (string) $projection->current_state;
                if (! isset($stateCounts[$sourceType]) || ! array_key_exists($state, $stateCounts[$sourceType])) {
                    throw ResourceDomainException::conflict('Internal exam inventory has an unsupported state projection.');
                }

                $stateCounts[$sourceType][$state] = (int) $projection->state_count;
            });

        foreach (self::SOURCE_TYPES as $sourceType) {
            if ($ledgerAvailable[$sourceType] < 0
                || $ledgerAvailable[$sourceType] !== $stateCounts[$sourceType]['available']) {
                throw ResourceDomainException::conflict(
                    'Internal exam inventory ledger and operational availability projection are inconsistent.',
                );
            }
        }

        $entries = DB::table('internal_exam_inventory_entries')
            ->where('organization_id', $organizationId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'source_type', 'current_state', 'created_at'])
            ->map(static function (object $row): array {
                /** @var InventoryEntryRow $projection */
                $projection = $row;

                return [
                    'id' => (string) $projection->id,
                    'source_type' => (string) $projection->source_type,
                    'status' => (string) $projection->current_state,
                    'created_at' => (string) $projection->created_at,
                ];
            })
            ->values()
            ->all();

        $reservedTotal = 0;
        $consumedTotal = 0;
        $adjustedOutTotal = 0;
        foreach (self::SOURCE_TYPES as $sourceType) {
            $reservedTotal += $stateCounts[$sourceType]['reserved'];
            $consumedTotal += $stateCounts[$sourceType]['consumed'];
            $adjustedOutTotal += $stateCounts[$sourceType]['adjusted_out'];
        }

        return [
            'entries' => array_values($entries),
            'summary' => [
                'available_total' => array_sum($ledgerAvailable),
                'available_by_source_type' => $ledgerAvailable,
                'reserved_total' => $reservedTotal,
                'consumed_total' => $consumedTotal,
                'adjusted_out_total' => $adjustedOutTotal,
            ],
        ];
    }

    /** @return array{category_code:string,exam_part:string,languages:list<string>} */
    public function capability(string $sessionId, string $categoryCode, string $examPart): array
    {
        $this->scope->visibility($sessionId, 'exams.view');

        $categoryCode = mb_strtoupper(trim($categoryCode));
        if ($categoryCode === '') {
            throw ResourceDomainException::rule('Internal exam category is required.');
        }
        if (! in_array($examPart, ['theory', 'practical'], true)) {
            throw ResourceDomainException::rule('Unsupported internal exam part.');
        }

        /** @var CategoryRow|null $category */
        $category = DB::table('driving_categories')
            ->where('code', $categoryCode)
            ->first(['id', 'code']);
        if ($category === null) {
            throw ResourceDomainException::notFound('Driving category not found.');
        }

        $languages = DB::table('internal_exam_capabilities')
            ->where('driving_category_id', $category->id)
            ->where('exam_part', $examPart)
            ->whereNull('disabled_at')
            ->orderBy('language_code')
            ->pluck('language_code')
            ->map(static fn ($language): string => (string) $language)
            ->values()
            ->all();

        return [
            'category_code' => (string) $category->code,
            'exam_part' => $examPart,
            'languages' => array_values($languages),
        ];
    }
}
