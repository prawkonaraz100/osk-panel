<?php

namespace Tests\Unit;

use App\Support\Privacy\RetentionExecutor;
use App\Support\Privacy\RetentionPolicy;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class RetentionExecutorContractTest extends TestCase
{
    public function test_cutoff_is_server_derived_and_executor_scope_is_narrow(): void
    {
        $root = dirname(__DIR__, 2);
        $config = (string) file_get_contents($root.'/config/retention.php');
        $command = (string) file_get_contents($root.'/app/Console/Commands/RetentionRunCommand.php');
        $bootstrap = (string) file_get_contents($root.'/bootstrap/app.php');
        $console = (string) file_get_contents($root.'/routes/console.php');
        $web = (string) file_get_contents($root.'/routes/web.php');
        $spec = (string) file_get_contents($root.'/specs/privacy/retention-executor.yml');

        self::assertStringContainsString("'allowed_data_classes' => [", $config);
        self::assertStringContainsString("'idempotency_records'", $config);
        self::assertStringNotContainsString("'outbox_published',", $config);

        self::assertStringContainsString('retention:run', $command);
        self::assertStringContainsString('RetentionRunCommand::class', $bootstrap);
        self::assertStringContainsString(RetentionExecutor::EXECUTION_CONFIRMATION, file_get_contents($root.'/app/Support/Privacy/RetentionExecutor.php'));
        self::assertStringNotContainsString('retention:run', $web);
        self::assertStringNotContainsString("Schedule::command('retention:run", $console);

        self::assertStringContainsString('status: PASS', $spec);
        self::assertStringContainsString('ordinary_application_role_access: forbidden', $spec);
        self::assertStringContainsString('outbox_trigger_bypass: forbidden', $spec);
        self::assertStringContainsString('PKK_provider_runtime: untouched_frozen', $spec);
    }

    public function test_policy_can_derive_direct_clock_cutoff_but_not_inherited_or_fiscal_authority(): void
    {
        $policy = new RetentionPolicy;
        $now = CarbonImmutable::parse('2026-09-15T12:00:00+02:00');

        self::assertSame(
            '2026-08-16T12:00:00+02:00',
            $policy->cutoffAt('idempotency_records', $now)?->toIso8601String(),
        );
        self::assertNull($policy->cutoffAt('student_formal_identity', $now));
        self::assertNull($policy->cutoffAt('finance_accounting_records', $now));
        self::assertNull($policy->cutoffAt('provider_pkk_raw_payload', $now));
    }
}
