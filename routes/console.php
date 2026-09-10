<?php

use App\Support\Migrations\MigrationExecutionJournal;
use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\PostgresMigrationLock;
use Illuminate\Support\Facades\Artisan;

Artisan::command('migration:plan:validate {--json}', function (MigrationPlan $plan): int {
    $plan->validate();
    if ($this->option('json')) {
        $this->line(json_encode($plan->summary(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info('S5_MIGRATION_PLAN=PASS');
        $this->line('plan_identity='.$plan->identity());
        $this->line('nodes='.$plan->nodeCount());
        $this->line('review_batches='.$plan->batchCount());
        $this->line('implemented_nodes='.$plan->implementedNodeCount());
    }

    return self::SUCCESS;
})->purpose('Validate the immutable Stage-4 migration authority binding and review batches.');

Artisan::command('migration:controlled {--plan= : Exact migration plan identity} {--force}', function (
    MigrationPlan $migrationPlan,
    PostgresMigrationLock $lock,
    MigrationExecutionJournal $journal,
): int {
    $migrationPlan->validate();
    if ($this->option('plan') !== $migrationPlan->identity()) {
        $this->error('Migration plan identity mismatch; refusing execution.');

        return self::FAILURE;
    }

    if (! $lock->acquire()) {
        $this->error('Another migration executor holds the PostgreSQL advisory lock.');

        return self::FAILURE;
    }

    $journal->append(['event' => 'migration_run_started', 'plan_identity' => $migrationPlan->identity()]);
    try {
        $exit = Artisan::call('migrate', ['--force' => (bool) $this->option('force')]);
        $this->output->write(Artisan::output());
        $journal->append([
            'event' => $exit === self::SUCCESS ? 'migration_run_succeeded' : 'migration_run_failed',
            'plan_identity' => $migrationPlan->identity(),
            'exit_code' => $exit,
        ]);

        return $exit;
    } catch (Throwable $exception) {
        $journal->append([
            'event' => 'migration_run_failed',
            'plan_identity' => $migrationPlan->identity(),
            'exception' => $exception::class,
        ]);
        throw $exception;
    } finally {
        $lock->release();
    }
})->purpose('Run Laravel migrations under the Stage-4 plan identity, PostgreSQL advisory lock, and execution journal.');
