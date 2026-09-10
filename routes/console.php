<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationExecutionJournal;
use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\PostgresMigrationLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('migration:plan:validate {--json}', function (MigrationPlan $plan): int {
    $plan->validate();
    if ($this->option('json')) {
        $this->line(json_encode($plan->summary(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info('S5_MIGRATION_PLAN=PASS');
        $this->line('plan_identity='.$plan->identity());
        $this->line('execution_identity='.$plan->executionIdentity());
        $this->line('nodes='.$plan->nodeCount());
        $this->line('review_batches='.$plan->batchCount());
        $this->line('implemented_nodes='.$plan->implementedNodeCount());
        $this->line('implemented_steps='.$plan->implementedStepCount());
    }

    return self::SUCCESS;
})->purpose('Validate the immutable Stage-4 migration authority and registered executable steps.');

Artisan::command(
    'migration:controlled
        {--plan= : Exact immutable Stage-4 migration plan identity}
        {--execution= : Exact implementation registry and migration-content identity}
        {--phase= : One explicit phase: expand|preflight|write_fence|backfill|reconcile|validate|contract}
        {--reviewed-resume=* : Node IDs explicitly reviewed for this invocation after an interrupted manual-review step}
        {--force}',
    function (
        MigrationPlan $migrationPlan,
        PostgresMigrationLock $lock,
        MigrationExecutionJournal $journal,
    ): int {
        $migrationPlan->validate();

        $phase = $this->option('phase');
        if (! is_string($phase) || $phase === '') {
            $this->error('Explicit migration phase is required; refusing execution.');

            return self::FAILURE;
        }

        if ($this->option('plan') !== $migrationPlan->identity()) {
            $this->error('Migration plan identity mismatch; refusing execution.');

            return self::FAILURE;
        }

        if ($this->option('execution') !== $migrationPlan->executionIdentity()) {
            $this->error('Migration execution identity mismatch; refusing execution.');

            return self::FAILURE;
        }

        if (! $lock->acquire()) {
            $this->error('Another migration executor holds the PostgreSQL advisory lock.');

            return self::FAILURE;
        }

        $executionIdentity = $migrationPlan->executionIdentity();
        $activeNode = null;

        try {
            $applied = Schema::hasTable('migrations')
                ? DB::table('migrations')->pluck('migration')->all()
                : [];
            $migrationPlan->assertPhaseEntry($phase, $applied);

            $reviewedResume = array_values(array_unique(array_filter(
                $this->option('reviewed-resume'),
                fn ($value): bool => is_string($value) && $value !== '',
            )));
            $phaseSteps = $migrationPlan->phaseSteps($phase);
            $phaseNodeIds = array_column($phaseSteps, 'node_id');

            foreach ($reviewedResume as $nodeId) {
                if (! in_array($nodeId, $phaseNodeIds, true)) {
                    $this->error("Reviewed-resume node {$nodeId} is not registered in phase {$phase}; refusing execution.");

                    return self::FAILURE;
                }
            }

            $journal->append([
                'event' => 'migration_run_started',
                'plan_identity' => $migrationPlan->identity(),
                'execution_identity' => $executionIdentity,
                'phase' => $phase,
            ]);

            foreach ($phaseSteps as $step) {
                if (in_array($step['migration_name'], $applied, true)) {
                    continue;
                }

                $activeNode = $step['node_id'];
                $requiresReview = $step['restart_classification'] === 'manual_review'
                    && $journal->requiresReviewedResume($executionIdentity, $activeNode);

                if ($requiresReview && ! in_array($activeNode, $reviewedResume, true)) {
                    $this->error("Manual-review node {$activeNode} has interrupted or failed evidence; explicit --reviewed-resume={$activeNode} is required.");

                    return self::FAILURE;
                }

                if ($requiresReview) {
                    $journal->append([
                        'event' => 'migration_node_resume_authorized',
                        'plan_identity' => $migrationPlan->identity(),
                        'execution_identity' => $executionIdentity,
                        'phase' => $phase,
                        'node_id' => $activeNode,
                    ]);
                }

                $journal->append([
                    'event' => 'migration_node_started',
                    'plan_identity' => $migrationPlan->identity(),
                    'execution_identity' => $executionIdentity,
                    'phase' => $phase,
                    'node_id' => $activeNode,
                    'restart_classification' => $step['restart_classification'],
                ]);

                ControlledMigrationContext::enter($phase, $activeNode, $executionIdentity);
                try {
                    $exit = Artisan::call('migrate', [
                        '--path' => dirname($step['migration_file']),
                        '--force' => (bool) $this->option('force'),
                    ]);
                    $this->output->write(Artisan::output());
                } finally {
                    ControlledMigrationContext::leave();
                }

                if ($exit !== self::SUCCESS) {
                    $journal->append([
                        'event' => 'migration_node_failed',
                        'plan_identity' => $migrationPlan->identity(),
                        'execution_identity' => $executionIdentity,
                        'phase' => $phase,
                        'node_id' => $activeNode,
                        'exit_code' => $exit,
                    ]);

                    return $exit;
                }

                $applied = Schema::hasTable('migrations')
                    ? DB::table('migrations')->pluck('migration')->all()
                    : [];
                if (! in_array($step['migration_name'], $applied, true)) {
                    throw new LogicException("Laravel migration repository did not record {$activeNode}/{$phase} as applied.");
                }

                $journal->append([
                    'event' => 'migration_node_succeeded',
                    'plan_identity' => $migrationPlan->identity(),
                    'execution_identity' => $executionIdentity,
                    'phase' => $phase,
                    'node_id' => $activeNode,
                ]);
                $activeNode = null;
            }

            $journal->append([
                'event' => 'migration_run_succeeded',
                'plan_identity' => $migrationPlan->identity(),
                'execution_identity' => $executionIdentity,
                'phase' => $phase,
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($activeNode !== null) {
                try {
                    $journal->append([
                        'event' => 'migration_node_failed',
                        'plan_identity' => $migrationPlan->identity(),
                        'execution_identity' => $executionIdentity,
                        'phase' => $phase,
                        'node_id' => $activeNode,
                        'exception' => $exception::class,
                    ]);
                } catch (Throwable) {
                }
            }

            try {
                $journal->append([
                    'event' => 'migration_run_failed',
                    'plan_identity' => $migrationPlan->identity(),
                    'execution_identity' => $executionIdentity,
                    'phase' => $phase,
                    'exception' => $exception::class,
                ]);
            } catch (Throwable) {
            }

            throw $exception;
        } finally {
            ControlledMigrationContext::leave();
            $lock->release();
        }
    }
)->purpose('Run one registered Stage-4 migration phase under exact identities, node-level evidence, and PostgreSQL advisory lock.');
