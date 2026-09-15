<?php

namespace Tests\Feature;

use App\Support\Privacy\RetentionExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class RetentionExecutorCoreTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_dry_run_is_non_destructive_and_uses_server_derived_cutoff(): void
    {
        FoundationSchema::ensureMigrated();
        DB::beginTransaction();

        try {
            CarbonImmutable::setTestNow('2026-09-15T12:00:00+02:00');
            $old = $this->record('completed', CarbonImmutable::now()->subDays(31));
            $recent = $this->record('completed', CarbonImmutable::now()->subDays(29));
            $failed = $this->record('delivery_failed', CarbonImmutable::now()->subDays(90));

            $exit = Artisan::call('retention:run', [
                'dataClass' => 'idempotency_records',
                '--policy' => '2026-09-12-v1',
                '--reason' => 'scheduled privacy dry run',
                '--json' => true,
            ]);

            $this->assertSame(Command::SUCCESS, $exit);
            $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('dry_run', $payload['mode']);
            $this->assertSame('completed', $payload['result']);
            $this->assertSame('2026-08-16T12:00:00+02:00', $payload['cutoff_at']);
            $this->assertSame(1, $payload['candidate_count']);
            $this->assertSame(0, $payload['deleted_or_redacted_count']);

            $this->assertTrue(DB::table('idempotency_records')->where('id', $old)->exists());
            $this->assertTrue(DB::table('idempotency_records')->where('id', $recent)->exists());
            $this->assertTrue(DB::table('idempotency_records')->where('id', $failed)->exists());
            $this->assertSame(0, DB::table('data_retention_execution_runs')->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_execute_deletes_only_locked_eligible_completed_rows_and_writes_immutable_evidence_shape(): void
    {
        FoundationSchema::ensureMigrated();
        DB::beginTransaction();

        try {
            CarbonImmutable::setTestNow('2026-09-15T12:00:00+02:00');
            config()->set('retention.executor.enabled', true);
            config()->set('retention.executor.max_rows_per_execution', 100);

            $old = $this->record('completed', CarbonImmutable::now()->subDays(31));
            $recent = $this->record('completed', CarbonImmutable::now()->subDays(29));
            $failed = $this->record('delivery_failed', CarbonImmutable::now()->subDays(90));

            $exit = Artisan::call('retention:run', [
                'dataClass' => 'idempotency_records',
                '--policy' => '2026-09-12-v1',
                '--reason' => 'approved technical TTL cleanup',
                '--execute' => true,
                '--confirm' => RetentionExecutor::EXECUTION_CONFIRMATION,
                '--json' => true,
            ]);

            $this->assertSame(Command::SUCCESS, $exit);
            $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('execute', $payload['mode']);
            $this->assertSame('completed', $payload['result']);
            $this->assertSame(1, $payload['candidate_count']);
            $this->assertSame(1, $payload['deleted_or_redacted_count']);

            $this->assertFalse(DB::table('idempotency_records')->where('id', $old)->exists());
            $this->assertTrue(DB::table('idempotency_records')->where('id', $recent)->exists());
            $this->assertTrue(DB::table('idempotency_records')->where('id', $failed)->exists());

            $evidence = DB::table('data_retention_execution_runs')->first();
            $this->assertNotNull($evidence);
            $this->assertSame('2026-09-12-v1', $evidence->policy_version_reference);
            $this->assertSame('idempotency_records', $evidence->data_class);
            $this->assertSame('approved technical TTL cleanup', $evidence->reason);
            $this->assertSame(1, (int) $evidence->candidate_count);
            $this->assertSame(1, (int) $evidence->deleted_or_redacted_count);
            $this->assertSame(0, (int) $evidence->skipped_hold_count);
            $this->assertSame('completed', $evidence->result);
            $this->assertNull($evidence->initiated_by_user_id);
            $this->assertNotNull($evidence->completed_at);
        } finally {
            DB::rollBack();
        }
    }

    public function test_execution_fails_closed_when_disabled_policy_mismatches_or_class_is_not_allowlisted(): void
    {
        FoundationSchema::ensureMigrated();
        DB::beginTransaction();

        try {
            CarbonImmutable::setTestNow('2026-09-15T12:00:00+02:00');
            $old = $this->record('completed', CarbonImmutable::now()->subDays(31));

            $disabled = Artisan::call('retention:run', [
                'dataClass' => 'idempotency_records',
                '--policy' => '2026-09-12-v1',
                '--reason' => 'must be refused',
                '--execute' => true,
                '--confirm' => RetentionExecutor::EXECUTION_CONFIRMATION,
            ]);
            $this->assertSame(Command::FAILURE, $disabled);

            config()->set('retention.executor.enabled', true);

            $wrongPolicy = Artisan::call('retention:run', [
                'dataClass' => 'idempotency_records',
                '--policy' => 'wrong-policy',
                '--reason' => 'must be refused',
                '--execute' => true,
                '--confirm' => RetentionExecutor::EXECUTION_CONFIRMATION,
            ]);
            $this->assertSame(Command::FAILURE, $wrongPolicy);

            $forbiddenClass = Artisan::call('retention:run', [
                'dataClass' => 'outbox_published',
                '--policy' => '2026-09-12-v1',
                '--reason' => 'must be refused',
                '--execute' => true,
                '--confirm' => RetentionExecutor::EXECUTION_CONFIRMATION,
            ]);
            $this->assertSame(Command::FAILURE, $forbiddenClass);

            $this->assertTrue(DB::table('idempotency_records')->where('id', $old)->exists());
            $this->assertSame(0, DB::table('data_retention_execution_runs')->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_server_side_row_fence_blocks_large_delete_and_records_review_evidence(): void
    {
        FoundationSchema::ensureMigrated();
        DB::beginTransaction();

        try {
            CarbonImmutable::setTestNow('2026-09-15T12:00:00+02:00');
            config()->set('retention.executor.enabled', true);
            config()->set('retention.executor.max_rows_per_execution', 1);

            $first = $this->record('completed', CarbonImmutable::now()->subDays(31));
            $second = $this->record('completed', CarbonImmutable::now()->subDays(32));

            $exit = Artisan::call('retention:run', [
                'dataClass' => 'idempotency_records',
                '--policy' => '2026-09-12-v1',
                '--reason' => 'bounded cleanup requires review',
                '--execute' => true,
                '--confirm' => RetentionExecutor::EXECUTION_CONFIRMATION,
                '--json' => true,
            ]);

            $this->assertSame(Command::FAILURE, $exit);
            $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('partial_requires_review', $payload['result']);
            $this->assertSame(2, $payload['candidate_count']);
            $this->assertSame(0, $payload['deleted_or_redacted_count']);

            $this->assertTrue(DB::table('idempotency_records')->where('id', $first)->exists());
            $this->assertTrue(DB::table('idempotency_records')->where('id', $second)->exists());

            $evidence = DB::table('data_retention_execution_runs')->first();
            $this->assertNotNull($evidence);
            $this->assertSame('partial_requires_review', $evidence->result);
            $this->assertSame(2, (int) $evidence->candidate_count);
            $this->assertSame(0, (int) $evidence->deleted_or_redacted_count);
        } finally {
            DB::rollBack();
        }
    }

    private function record(string $status, CarbonImmutable $completedAt): string
    {
        $id = (string) Str::uuid7();

        DB::table('idempotency_records')->insert([
            'id' => $id,
            'organization_id' => null,
            'operation_key' => 'retention-test',
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', $id),
            'status' => $status,
            'safe_response_snapshot' => json_encode(['ok' => true], JSON_THROW_ON_ERROR),
            'created_at' => $completedAt->subMinute(),
            'completed_at' => $completedAt,
            'expires_at' => $completedAt->addDays(7),
        ]);

        return $id;
    }
}
