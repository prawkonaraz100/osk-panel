<?php

namespace Tests\Feature;

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\TriggerGuards\FinanceTriggerGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class Stage4FinanceTriggerGuardsTest extends TestCase
{
    public function test_finance_trigger_guards_enforce_write_once_history_and_deferred_balance_state(): void
    {
        FoundationSchema::ensureMigrated();

        $plan = app(MigrationPlan::class);
        $plan->validate();

        $this->assertSame(257, $plan->implementedStepCount());
        $this->assertCount(52, $plan->phaseSteps('write_fence'));

        DB::beginTransaction();

        try {
            $preflightExit = Artisan::call('migration:controlled', [
                '--plan' => $plan->identity(),
                '--execution' => $plan->executionIdentity(),
                '--phase' => 'preflight',
                '--force' => true,
            ]);
            $this->assertSame(0, $preflightExit, Artisan::output());

            foreach ($plan->phaseSteps('write_fence') as $step) {
                ControlledMigrationContext::enter('write_fence', $step['node_id'], $plan->executionIdentity());

                try {
                    /** @var Migration $migration */
                    $migration = require base_path($step['migration_file']);
                    $migration->up();
                } finally {
                    ControlledMigrationContext::leave();
                }
            }

            $beforeRows = [
                'student_charges' => DB::table('student_charges')->count(),
                'student_payments' => DB::table('student_payments')->count(),
            ];

            FinanceTriggerGuards::install();

            $this->assertSame($beforeRows, [
                'student_charges' => DB::table('student_charges')->count(),
                'student_payments' => DB::table('student_payments')->count(),
            ]);

            $triggers = $this->signedFinanceTriggers();
            $this->assertCount(4, $triggers);
            $this->assertCount(2, array_filter($triggers, static fn (array $row): bool => $row['constraint']));
            $this->assertCount(2, array_filter($triggers, static fn (array $row): bool => $row['deferrable'] && $row['initially_deferred']));

            $actor = FoundationSchema::actor();
            $studentId = (string) Str::uuid7();
            $chargeId = (string) Str::uuid7();
            $paymentId = (string) Str::uuid7();

            DB::table('students')->insert([
                'id' => $studentId,
                'organization_id' => $actor['organization_id'],
                'first_name' => 'Finance',
                'last_name' => 'Guard',
                'no_pesel_declared' => true,
                'birth_date' => '1990-01-01',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('student_charges')->insert([
                'id' => $chargeId,
                'organization_id' => $actor['organization_id'],
                'student_id' => $studentId,
                'course_enrollment_id' => null,
                'title' => 'Test charge',
                'amount_minor' => 10000,
                'currency' => 'PLN',
                'due_at' => null,
                'created_by_user_id' => $actor['user_id'],
                'created_at' => now(),
            ]);

            DB::table('student_payments')->insert([
                'id' => $paymentId,
                'organization_id' => $actor['organization_id'],
                'student_id' => $studentId,
                'charge_id' => $chargeId,
                'amount_minor' => 6000,
                'currency' => 'PLN',
                'paid_at' => now(),
                'payment_method' => 'cash',
                'note' => 'first',
                'received_by_user_id' => $actor['user_id'],
                'created_at' => now(),
            ]);

            $this->expectImmediateGuardViolation(
                fn () => DB::table('student_charges')->where('id', $chargeId)->update(['amount_minor' => 9000]),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('student_payments')->where('id', $paymentId)->update(['note' => 'rewritten']),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('student_payments')->where('id', $paymentId)->delete(),
            );

            $this->expectDeferredGuardViolation(function () use ($actor, $studentId, $chargeId): void {
                DB::table('student_payments')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $actor['organization_id'],
                    'student_id' => $studentId,
                    'charge_id' => $chargeId,
                    'amount_minor' => 5000,
                    'currency' => 'PLN',
                    'paid_at' => now(),
                    'payment_method' => 'cash',
                    'note' => null,
                    'received_by_user_id' => $actor['user_id'],
                    'created_at' => now(),
                ]);
            });

            $this->expectDeferredGuardViolation(function () use ($actor, $chargeId): void {
                DB::table('student_charges')->where('id', $chargeId)->update([
                    'cancelled_at' => now(),
                    'cancelled_by_user_id' => $actor['user_id'],
                    'cancellation_reason' => 'cannot cancel with active payment',
                ]);
            });

            DB::table('student_payments')->where('id', $paymentId)->update([
                'reversed_at' => now(),
                'reversed_by_user_id' => $actor['user_id'],
                'reversal_reason' => 'correction',
            ]);

            $this->expectImmediateGuardViolation(
                fn () => DB::table('student_payments')->where('id', $paymentId)->update([
                    'reversal_reason' => 'rewritten correction',
                ]),
            );

            DB::table('student_charges')->where('id', $chargeId)->update([
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor['user_id'],
                'cancellation_reason' => 'valid after reversal',
            ]);

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            $this->expectImmediateGuardViolation(
                fn () => DB::table('student_charges')->where('id', $chargeId)->update([
                    'cancellation_reason' => 'rewritten cancellation',
                ]),
            );
            $this->expectImmediateGuardViolation(
                fn () => DB::table('student_charges')->where('id', $chargeId)->delete(),
            );
        } finally {
            DB::rollBack();
        }
    }

    private function expectImmediateGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_finance_immediate');

        try {
            $operation();
            $this->fail('Expected immediate finance trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_finance_immediate');
        }
    }

    private function expectDeferredGuardViolation(callable $operation): void
    {
        DB::statement('SAVEPOINT stage4_finance_deferred');

        try {
            $operation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Expected deferred finance trigger guard violation.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT stage4_finance_deferred');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }

    /**
     * @return list<array{
     *   table: string,
     *   name: string,
     *   constraint: bool,
     *   deferrable: bool,
     *   initially_deferred: bool,
     *   signature: string
     * }>
     */
    private function signedFinanceTriggers(): array
    {
        return DB::table('pg_trigger as trg')
            ->join('pg_class as cls', 'cls.oid', '=', 'trg.tgrelid')
            ->join('pg_namespace as ns', 'ns.oid', '=', 'cls.relnamespace')
            ->join('pg_proc as pro', 'pro.oid', '=', 'trg.tgfoid')
            ->whereRaw('ns.nspname = current_schema()')
            ->where('trg.tgisinternal', false)
            ->whereRaw("COALESCE(obj_description(trg.oid, 'pg_trigger'), '') LIKE 'prawkonaraz:trigger-write-fence:v1:%'")
            ->where('pro.proname', 'like', 'fn_guard_finance_%')
            ->whereIn('cls.relname', ['student_charges', 'student_payments'])
            ->orderBy('cls.relname')
            ->orderBy('trg.tgname')
            ->get([
                'cls.relname as table_name',
                'trg.tgname',
                DB::raw('CASE WHEN trg.tgconstraint <> 0 THEN 1 ELSE 0 END AS is_constraint'),
                DB::raw('CASE WHEN trg.tgdeferrable THEN 1 ELSE 0 END AS is_deferrable'),
                DB::raw('CASE WHEN trg.tginitdeferred THEN 1 ELSE 0 END AS is_initially_deferred'),
                DB::raw("COALESCE(obj_description(trg.oid, 'pg_trigger'), '') AS signature"),
            ])
            ->map(static fn ($row): array => [
                'table' => (string) $row->table_name,
                'name' => (string) $row->tgname,
                'constraint' => (int) $row->is_constraint === 1,
                'deferrable' => (int) $row->is_deferrable === 1,
                'initially_deferred' => (int) $row->is_initially_deferred === 1,
                'signature' => (string) $row->signature,
            ])
            ->all();
    }
}
