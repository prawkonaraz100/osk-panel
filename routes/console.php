<?php

use App\Support\Migrations\ControlledMigrationContext;
use App\Support\Migrations\MigrationExecutionJournal;
use App\Support\Migrations\MigrationPlan;
use App\Support\Migrations\PostgresMigrationLock;
use App\Support\Migrations\Stage5FormalDocumentsMigrationPlan;
use App\Support\Operations\CoreReconciliationScanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Command\Command;

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

    return Command::SUCCESS;
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

            return Command::FAILURE;
        }

        if ($this->option('plan') !== $migrationPlan->identity()) {
            $this->error('Migration plan identity mismatch; refusing execution.');

            return Command::FAILURE;
        }

        if ($this->option('execution') !== $migrationPlan->executionIdentity()) {
            $this->error('Migration execution identity mismatch; refusing execution.');

            return Command::FAILURE;
        }

        if (! $lock->acquire()) {
            $this->error('Another migration executor holds the PostgreSQL advisory lock.');

            return Command::FAILURE;
        }

        $executionIdentity = $migrationPlan->executionIdentity();
        $activeNode = null;

        /**
         * @return list<string>
         */
        $loadAppliedMigrations = static function (): array {
            if (! Schema::hasTable('migrations')) {
                return [];
            }

            $values = DB::table('migrations')->pluck('migration')->all();
            foreach ($values as $value) {
                if (! is_string($value)) {
                    throw new LogicException('Laravel migration repository contains a non-string migration name.');
                }
            }

            /** @var list<string> $values */
            return $values;
        };

        try {
            $applied = $loadAppliedMigrations();
            $migrationPlan->assertPhaseEntry($phase, $applied);

            $reviewedResumeOption = $this->option('reviewed-resume');
            $reviewedResumeValues = is_array($reviewedResumeOption) ? $reviewedResumeOption : [];
            $reviewedResume = array_values(array_unique(array_filter(
                $reviewedResumeValues,
                fn ($value): bool => is_string($value) && $value !== '',
            )));
            /** @var list<string> $reviewedResume */
            $phaseSteps = $migrationPlan->phaseSteps($phase);
            $phaseNodeIds = array_column($phaseSteps, 'node_id');

            foreach ($reviewedResume as $nodeId) {
                if (! in_array($nodeId, $phaseNodeIds, true)) {
                    $this->error("Reviewed-resume node {$nodeId} is not registered in phase {$phase}; refusing execution.");

                    return Command::FAILURE;
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

                    return Command::FAILURE;
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

                if ($exit !== Command::SUCCESS) {
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

                $applied = $loadAppliedMigrations();
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

            return Command::SUCCESS;
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

Artisan::command('migration:stage5:formal-docs:plan:validate {--json}', function (Stage5FormalDocumentsMigrationPlan $plan): int {
    $plan->validate();
    if ($this->option('json')) {
        $this->line(json_encode($plan->summary(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info('FORMAL_DOC_002_MIGRATION_EXTENSION_PLAN=PASS');
        $this->line('plan_identity='.$plan->identity());
        $this->line('execution_identity='.$plan->executionIdentity());
        $this->line('nodes='.$plan->nodeCount());
        $this->line('implemented_nodes='.$plan->implementedNodeCount());
        $this->line('implemented_steps='.$plan->implementedStepCount());
        $this->line('stage4_plan_identity='.$plan->summary()['stage4_plan_identity']);
        $this->line('stage4_execution_identity='.$plan->summary()['stage4_execution_identity']);
    }

    return Command::SUCCESS;
})->purpose('Validate the isolated Stage-5 formal-documents migration extension authority and registry.');

Artisan::command(
    'migration:stage5:formal-docs:controlled
        {--plan= : Exact Stage-5 formal-documents extension plan identity}
        {--execution= : Exact Stage-5 formal-documents implementation registry identity}
        {--phase= : One explicit phase: expand|preflight|write_fence|backfill|reconcile|validate|contract}
        {--reviewed-resume=* : Node IDs explicitly reviewed for this invocation after an interrupted manual-review step}
        {--force}',
    function (
        Stage5FormalDocumentsMigrationPlan $migrationPlan,
        PostgresMigrationLock $lock,
        MigrationExecutionJournal $journal,
    ): int {
        $migrationPlan->validate();

        $phase = $this->option('phase');
        if (! is_string($phase) || $phase === '') {
            $this->error('Explicit Stage-5 formal-documents migration phase is required; refusing execution.');

            return Command::FAILURE;
        }

        if ($this->option('plan') !== $migrationPlan->identity()) {
            $this->error('Stage-5 formal-documents migration plan identity mismatch; refusing execution.');

            return Command::FAILURE;
        }

        if ($this->option('execution') !== $migrationPlan->executionIdentity()) {
            $this->error('Stage-5 formal-documents migration execution identity mismatch; refusing execution.');

            return Command::FAILURE;
        }

        try {
            $phaseSteps = $migrationPlan->phaseSteps($phase);
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($phaseSteps === []) {
            $this->error("No materialized Stage-5 formal-documents migration steps for phase {$phase}; FORMAL-DOC-003 is required before execution.");

            return Command::FAILURE;
        }

        if (! $lock->acquire()) {
            $this->error('Another migration executor holds the shared PostgreSQL advisory lock.');

            return Command::FAILURE;
        }

        $executionIdentity = $migrationPlan->executionIdentity();
        $activeNode = null;

        /**
         * @return list<string>
         */
        $loadAppliedMigrations = static function (): array {
            if (! Schema::hasTable('migrations')) {
                return [];
            }

            $values = DB::table('migrations')->pluck('migration')->all();
            foreach ($values as $value) {
                if (! is_string($value)) {
                    throw new LogicException('Laravel migration repository contains a non-string migration name.');
                }
            }

            /** @var list<string> $values */
            return $values;
        };

        try {
            $applied = $loadAppliedMigrations();
            $migrationPlan->assertPhaseEntry($phase, $applied);

            $reviewedResumeOption = $this->option('reviewed-resume');
            $reviewedResumeValues = is_array($reviewedResumeOption) ? $reviewedResumeOption : [];
            $reviewedResume = array_values(array_unique(array_filter(
                $reviewedResumeValues,
                fn ($value): bool => is_string($value) && $value !== '',
            )));
            /** @var list<string> $reviewedResume */
            $phaseNodeIds = array_column($phaseSteps, 'node_id');

            foreach ($reviewedResume as $nodeId) {
                if (! in_array($nodeId, $phaseNodeIds, true)) {
                    $this->error("Reviewed-resume node {$nodeId} is not registered in Stage-5 formal-documents phase {$phase}; refusing execution.");

                    return Command::FAILURE;
                }
            }

            $journal->append([
                'event' => 'migration_run_started',
                'plan_scope' => 'stage5_formal_documents',
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

                    return Command::FAILURE;
                }

                if ($requiresReview) {
                    $journal->append([
                        'event' => 'migration_node_resume_authorized',
                        'plan_scope' => 'stage5_formal_documents',
                        'plan_identity' => $migrationPlan->identity(),
                        'execution_identity' => $executionIdentity,
                        'phase' => $phase,
                        'node_id' => $activeNode,
                    ]);
                }

                $journal->append([
                    'event' => 'migration_node_started',
                    'plan_scope' => 'stage5_formal_documents',
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

                if ($exit !== Command::SUCCESS) {
                    $journal->append([
                        'event' => 'migration_node_failed',
                        'plan_scope' => 'stage5_formal_documents',
                        'plan_identity' => $migrationPlan->identity(),
                        'execution_identity' => $executionIdentity,
                        'phase' => $phase,
                        'node_id' => $activeNode,
                        'exit_code' => $exit,
                    ]);

                    return $exit;
                }

                $applied = $loadAppliedMigrations();
                if (! in_array($step['migration_name'], $applied, true)) {
                    throw new LogicException("Laravel migration repository did not record {$activeNode}/{$phase} as applied.");
                }

                $journal->append([
                    'event' => 'migration_node_succeeded',
                    'plan_scope' => 'stage5_formal_documents',
                    'plan_identity' => $migrationPlan->identity(),
                    'execution_identity' => $executionIdentity,
                    'phase' => $phase,
                    'node_id' => $activeNode,
                ]);
                $activeNode = null;
            }

            $journal->append([
                'event' => 'migration_run_succeeded',
                'plan_scope' => 'stage5_formal_documents',
                'plan_identity' => $migrationPlan->identity(),
                'execution_identity' => $executionIdentity,
                'phase' => $phase,
            ]);

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            if ($activeNode !== null) {
                try {
                    $journal->append([
                        'event' => 'migration_node_failed',
                        'plan_scope' => 'stage5_formal_documents',
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
                    'plan_scope' => 'stage5_formal_documents',
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
)->purpose('Run one registered Stage-5 formal-documents migration phase under isolated identities and the shared migration lock.');

Artisan::command(
    'operations:reconciliation:scan
        {--json : Emit the full machine-readable report}
        {--log : Emit a safe summary to the application log}
        {--fail-on-findings : Return a failing exit code when findings exist}',
    function (CoreReconciliationScanner $scanner): int {
        $report = $scanner->scan();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('RECONCILIATION_SCAN='.$report['status']);
            $this->line('policy_version='.$report['policy_version']);
            $this->line('findings_total='.$report['findings_total']);
            $this->line('pkk_in_scope=false');
            $this->line('mutations_performed=0');
        }

        if ($this->option('log')) {
            $context = [
                'policy_version' => $report['policy_version'],
                'status' => $report['status'],
                'findings_total' => $report['findings_total'],
                'findings_by_scope' => $report['findings_by_scope'],
                'pkk_in_scope' => false,
                'mutations_performed' => 0,
            ];

            if ($report['findings_total'] > 0) {
                Log::warning('core_v1_reconciliation_findings', $context);
            } else {
                Log::info('core_v1_reconciliation_clean', $context);
            }
        }

        if ($this->option('fail-on-findings') && $report['findings_total'] > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    },
)->purpose('Read-only provider-neutral reconciliation scan for core-v1 durable authorities.');

Schedule::command('operations:reconciliation:scan --json --log --fail-on-findings')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30);
