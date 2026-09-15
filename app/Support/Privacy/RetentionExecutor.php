<?php

namespace App\Support\Privacy;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class RetentionExecutor
{
    public const EXECUTION_CONFIRMATION = 'DELETE-ELIGIBLE-IDEMPOTENCY-RECORDS';

    private const LOCK_KEY = 78136415920426;

    public function __construct(
        private readonly RetentionPolicy $policy,
    ) {}

    /**
     * @return array{
     *   mode:string,
     *   result:string,
     *   policy_version:string,
     *   data_class:string,
     *   cutoff_at:string,
     *   organization_id:?string,
     *   candidate_count:int,
     *   deleted_or_redacted_count:int,
     *   skipped_hold_count:int,
     *   max_rows_per_execution:int
     * }
     */
    public function preview(
        string $dataClass,
        string $policyVersion,
        string $reason,
        ?string $organizationId = null,
        ?CarbonImmutable $now = null,
    ): array {
        $now ??= CarbonImmutable::now();
        $cutoff = $this->assertAuthorityAndCutoff($dataClass, $policyVersion, $reason, $organizationId, $now);
        $candidateCount = $this->candidateQuery($dataClass, $cutoff, $organizationId)->count();

        return [
            'mode' => 'dry_run',
            'result' => 'completed',
            'policy_version' => $this->policy->version(),
            'data_class' => $dataClass,
            'cutoff_at' => $cutoff->toIso8601String(),
            'organization_id' => $organizationId,
            'candidate_count' => $candidateCount,
            'deleted_or_redacted_count' => 0,
            'skipped_hold_count' => 0,
            'max_rows_per_execution' => $this->maxRows(),
        ];
    }

    /**
     * @return array{
     *   mode:string,
     *   result:string,
     *   policy_version:string,
     *   data_class:string,
     *   cutoff_at:string,
     *   organization_id:?string,
     *   candidate_count:int,
     *   deleted_or_redacted_count:int,
     *   skipped_hold_count:int,
     *   max_rows_per_execution:int
     * }
     */
    public function execute(
        string $dataClass,
        string $policyVersion,
        string $reason,
        string $confirmation,
        ?string $organizationId = null,
        ?CarbonImmutable $now = null,
    ): array {
        if (! (bool) config('retention.executor.enabled', false)) {
            throw new LogicException('Privileged retention executor is disabled.');
        }
        if (! hash_equals(self::EXECUTION_CONFIRMATION, $confirmation)) {
            throw new LogicException('Retention execution confirmation token mismatch.');
        }

        $now ??= CarbonImmutable::now();
        $cutoff = $this->assertAuthorityAndCutoff($dataClass, $policyVersion, $reason, $organizationId, $now);
        $startedAt = $now;
        $candidateCount = $this->candidateQuery($dataClass, $cutoff, $organizationId)->count();

        try {
            return DB::transaction(function () use (
                $dataClass,
                $policyVersion,
                $reason,
                $organizationId,
                $cutoff,
                $startedAt,
                $candidateCount,
            ): array {
                $this->acquireTransactionLock();

                $lockedCandidateCount = $this->candidateQuery($dataClass, $cutoff, $organizationId)->count();
                $maxRows = $this->maxRows();

                if ($lockedCandidateCount > $maxRows) {
                    $this->insertEvidence(
                        $policyVersion,
                        $dataClass,
                        $cutoff,
                        $organizationId,
                        $reason,
                        $lockedCandidateCount,
                        0,
                        0,
                        $startedAt,
                        CarbonImmutable::now(),
                        'partial_requires_review',
                    );

                    return $this->result(
                        'execute',
                        'partial_requires_review',
                        $dataClass,
                        $cutoff,
                        $organizationId,
                        $lockedCandidateCount,
                        0,
                    );
                }

                $rows = $this->candidateQuery($dataClass, $cutoff, $organizationId)
                    ->orderBy('completed_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);

                if ($rows->count() > $maxRows) {
                    throw new LogicException('Retention candidate set exceeded the server-side execution fence after lock.');
                }

                $ids = $rows->pluck('id')->map(static fn ($id): string => (string) $id)->all();
                $deleted = 0;

                if ($ids !== []) {
                    $deleted = DB::table('idempotency_records')
                        ->whereIn('id', $ids)
                        ->where('status', 'completed')
                        ->whereNotNull('completed_at')
                        ->where('completed_at', '<=', $cutoff)
                        ->delete();
                }

                if ($deleted !== count($ids)) {
                    throw new LogicException('Retention delete count diverged from the locked candidate set.');
                }

                $this->insertEvidence(
                    $policyVersion,
                    $dataClass,
                    $cutoff,
                    $organizationId,
                    $reason,
                    count($ids),
                    $deleted,
                    0,
                    $startedAt,
                    CarbonImmutable::now(),
                    'completed',
                );

                return $this->result(
                    'execute',
                    'completed',
                    $dataClass,
                    $cutoff,
                    $organizationId,
                    count($ids),
                    $deleted,
                );
            }, 1);
        } catch (Throwable $exception) {
            try {
                $this->insertEvidence(
                    $policyVersion,
                    $dataClass,
                    $cutoff,
                    $organizationId,
                    $reason,
                    $candidateCount,
                    0,
                    0,
                    $startedAt,
                    CarbonImmutable::now(),
                    'failed',
                );
            } catch (Throwable) {
            }

            throw $exception;
        }
    }

    private function assertAuthorityAndCutoff(
        string $dataClass,
        string $policyVersion,
        string $reason,
        ?string $organizationId,
        CarbonImmutable $now,
    ): CarbonImmutable {
        if (! hash_equals($this->policy->version(), $policyVersion)) {
            throw new LogicException('Retention policy version mismatch.');
        }
        if (trim($reason) === '') {
            throw new LogicException('Retention execution reason must be nonblank.');
        }
        if ($organizationId !== null && ! Str::isUuid($organizationId)) {
            throw new LogicException('Retention organization scope must be an exact UUID.');
        }

        $allowed = config('retention.executor.allowed_data_classes', []);
        if (! is_array($allowed) || ! in_array($dataClass, $allowed, true)) {
            throw new LogicException("Retention data class {$dataClass} is not executable by this privileged gate.");
        }
        if ($dataClass !== 'idempotency_records') {
            throw new LogicException('This retention gate is restricted to idempotency_records.');
        }

        $definition = $this->policy->definition($dataClass);
        if (($definition['legal_hold'] ?? true) !== false) {
            throw new LogicException('This executor gate may not process a legal-hold-aware data class.');
        }

        $cutoff = $this->policy->cutoffAt($dataClass, $now);
        if ($cutoff === null) {
            throw new LogicException('Retention cutoff cannot be derived safely for this data class.');
        }

        return $cutoff;
    }

    private function candidateQuery(
        string $dataClass,
        CarbonImmutable $cutoff,
        ?string $organizationId,
    ): Builder {
        if ($dataClass !== 'idempotency_records') {
            throw new LogicException('Unsupported retention query.');
        }

        $query = DB::table('idempotency_records')
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $cutoff);

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        return $query;
    }

    private function acquireTransactionLock(): void
    {
        $row = DB::selectOne('select pg_try_advisory_xact_lock(?) as acquired', [self::LOCK_KEY]);
        $value = $row?->acquired;

        if (! in_array($value, [true, 1, '1', 't', 'true'], true)) {
            throw new LogicException('Another privileged retention executor holds the PostgreSQL advisory lock.');
        }
    }

    private function maxRows(): int
    {
        $value = config('retention.executor.max_rows_per_execution', 1000);
        if (! is_int($value) || $value < 1) {
            throw new LogicException('Retention executor max_rows_per_execution must be a positive integer.');
        }

        return $value;
    }

    private function insertEvidence(
        string $policyVersion,
        string $dataClass,
        CarbonImmutable $cutoff,
        ?string $organizationId,
        string $reason,
        int $candidateCount,
        int $deletedCount,
        int $skippedHoldCount,
        CarbonImmutable $startedAt,
        CarbonImmutable $completedAt,
        string $result,
    ): void {
        DB::table('data_retention_execution_runs')->insert([
            'id' => (string) Str::uuid7(),
            'policy_version_reference' => $policyVersion,
            'data_class' => $dataClass,
            'cutoff_at' => $cutoff,
            'organization_id' => $organizationId,
            'initiated_by_user_id' => null,
            'reason' => trim($reason),
            'candidate_count' => $candidateCount,
            'deleted_or_redacted_count' => $deletedCount,
            'skipped_hold_count' => $skippedHoldCount,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'result' => $result,
        ]);
    }

    /**
     * @return array{
     *   mode:string,
     *   result:string,
     *   policy_version:string,
     *   data_class:string,
     *   cutoff_at:string,
     *   organization_id:?string,
     *   candidate_count:int,
     *   deleted_or_redacted_count:int,
     *   skipped_hold_count:int,
     *   max_rows_per_execution:int
     * }
     */
    private function result(
        string $mode,
        string $result,
        string $dataClass,
        CarbonImmutable $cutoff,
        ?string $organizationId,
        int $candidateCount,
        int $deletedCount,
    ): array {
        return [
            'mode' => $mode,
            'result' => $result,
            'policy_version' => $this->policy->version(),
            'data_class' => $dataClass,
            'cutoff_at' => $cutoff->toIso8601String(),
            'organization_id' => $organizationId,
            'candidate_count' => $candidateCount,
            'deleted_or_redacted_count' => $deletedCount,
            'skipped_hold_count' => 0,
            'max_rows_per_execution' => $this->maxRows(),
        ];
    }
}
