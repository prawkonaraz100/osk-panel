<?php

namespace App\Support\Migrations;

use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @phpstan-type Stage5MigrationSource array{authority_path: string, authority_git_blob: string, extension_contract_version: int, phase_contract_version: int}
 * @phpstan-type Stage4Baseline array{plan_identity: string, execution_identity: string, authority_git_blob: string, nodes: int, implemented_nodes: int, implemented_steps: int}
 * @phpstan-type Stage5MigrationNode array{node_id: string, type: string, order: int, object_name: string, requires: list<string>, phases: list<string>, restart_classification: string}
 * @phpstan-type Stage5MigrationPlanDocument array{schema_version: int, extension_id: string, plan_identity: string, source: Stage5MigrationSource, stage4_baseline: Stage4Baseline, phase_order: list<string>, execution_control: array<string, mixed>, nodes: list<Stage5MigrationNode>}
 * @phpstan-type Stage5MigrationStep array{node_id: string, phase: string, migration_file: string, migration_name: string, file_sha256: string, restart_classification: string, safe_down: bool}
 * @phpstan-type Stage5MigrationImplementationDocument array{schema_version: int, plan_identity: string, execution_identity: string, stage5_root: string, implemented_steps: list<Stage5MigrationStep>, claim_any_DDL_implemented: bool}
 */
final class Stage5FormalDocumentsMigrationPlan
{
    public const AUTHORITY_BLOB = '05465b26264086d5cc50248b9c98c58e7c44a068';

    public const STAGE4_PLAN_IDENTITY = 'd2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10';

    public const STAGE4_EXECUTION_IDENTITY = '1d2f1d3a47a4a09b749d0b164137857a241c07afbaad02789ec21e03cb4118ce';

    /** @var Stage5MigrationPlanDocument */
    private array $plan;

    /** @var Stage5MigrationImplementationDocument */
    private array $implementations;

    public function __construct()
    {
        $this->plan = $this->decodePlan(base_path('database/migration-plan/stage5-formal-documents-plan.json'));
        $this->implementations = $this->decodeImplementations(base_path('database/migration-plan/stage5-formal-documents-implementations.json'));
    }

    public function validate(): void
    {
        $expectedPhases = ['expand', 'preflight', 'write_fence', 'backfill', 'reconcile', 'validate', 'contract'];

        $this->assert($this->plan['schema_version'] === 1, 'Unsupported Stage-5 formal-documents plan schema.');
        $this->assert($this->plan['extension_id'] === 'formal_training_documents_v1', 'Unexpected Stage-5 formal-documents extension id.');
        $this->assert($this->plan['phase_order'] === $expectedPhases, 'Stage-5 formal-documents phase contract mismatch.');
        $this->assert(count($this->plan['nodes']) === 4, 'Stage-5 formal-documents plan must contain exactly four nodes.');
        $this->assert(
            $this->gitBlobSha(base_path($this->plan['source']['authority_path'])) === $this->plan['source']['authority_git_blob'],
            'Stage-5 formal-documents migration authority blob mismatch.',
        );
        $this->assert($this->plan['source']['authority_git_blob'] === self::AUTHORITY_BLOB, 'Unexpected Stage-5 formal-documents authority identity.');

        $stage4 = new MigrationPlan;
        $stage4->validate();
        $stage4Summary = $stage4->summary();
        $baseline = $this->plan['stage4_baseline'];

        $this->assert($baseline['plan_identity'] === self::STAGE4_PLAN_IDENTITY, 'Stage-4 baseline plan identity changed.');
        $this->assert($baseline['execution_identity'] === self::STAGE4_EXECUTION_IDENTITY, 'Stage-4 baseline execution identity changed.');
        $this->assert($stage4->identity() === $baseline['plan_identity'], 'Live Stage-4 plan identity differs from frozen FORMAL-DOC-002 baseline.');
        $this->assert($stage4Summary['authority_blob'] === $baseline['authority_git_blob'], 'Live Stage-4 authority blob differs from frozen FORMAL-DOC-002 baseline.');
        $this->assert($stage4->nodeCount() === $baseline['nodes'], 'Live Stage-4 node count differs from frozen FORMAL-DOC-002 baseline.');
        $this->assert(
            $stage4->implementedNodeCount() >= $baseline['implemented_nodes'],
            'Live Stage-4 implemented-node count regressed below frozen FORMAL-DOC-002 baseline.',
        );
        $this->assert(
            $stage4->implementedStepCount() >= $baseline['implemented_steps'],
            'Live Stage-4 implemented-step count regressed below frozen FORMAL-DOC-002 baseline.',
        );

        /** @var array<string, Stage5MigrationNode> $nodesById */
        $nodesById = [];
        /** @var array<int, true> $orders */
        $orders = [];

        foreach ($this->plan['nodes'] as $node) {
            $id = $node['node_id'];
            $this->assert(! isset($nodesById[$id]), "Duplicate Stage-5 formal-documents node: {$id}");
            $this->assert(! isset($orders[$node['order']]), "Duplicate Stage-5 formal-documents order: {$node['order']}");
            $this->assert(in_array($node['restart_classification'], ['restart_safe', 'manual_review'], true), "Invalid restart classification for {$id}");

            $lastPhaseIndex = -1;
            foreach ($node['phases'] as $phase) {
                $phaseIndex = array_search($phase, $expectedPhases, true);
                if ($phaseIndex === false || $phaseIndex <= $lastPhaseIndex) {
                    throw new LogicException("Invalid phase path for {$id}");
                }
                $lastPhaseIndex = $phaseIndex;
            }

            $nodesById[$id] = $node;
            $orders[$node['order']] = true;
        }

        foreach ($this->plan['nodes'] as $node) {
            foreach ($node['requires'] as $dependency) {
                $this->assert(isset($nodesById[$dependency]), "Unknown Stage-5 formal-documents dependency {$dependency}");
                $this->assert($nodesById[$dependency]['order'] < $node['order'], "Dependency appears after {$node['node_id']}");
            }
        }

        $this->assert(hash('sha256', $this->planIdentityMaterial()) === $this->plan['plan_identity'], 'Stage-5 formal-documents plan identity mismatch.');

        $this->assert($this->implementations['schema_version'] === 1, 'Unsupported Stage-5 formal-documents implementation registry schema.');
        $this->assert($this->implementations['plan_identity'] === $this->plan['plan_identity'], 'Stage-5 formal-documents implementation registry plan mismatch.');
        $this->assert($this->implementations['stage5_root'] === 'database/migrations/stage5/formal-documents', 'Unexpected Stage-5 formal-documents migration root.');

        /** @var array<string, true> $seenSteps */
        $seenSteps = [];
        /** @var array<string, true> $seenFiles */
        $seenFiles = [];
        /** @var array<string, list<string>> $phasesByNode */
        $phasesByNode = [];
        /** @var list<string> $implementedNodeIds */
        $implementedNodeIds = [];

        foreach ($this->implementations['implemented_steps'] as $step) {
            $id = $step['node_id'];
            $phase = $step['phase'];
            $this->assert(isset($nodesById[$id]), "Stage-5 implementation references unknown node {$id}");
            $this->assert(in_array($phase, $nodesById[$id]['phases'], true), "Stage-5 implementation phase outside authority for {$id}");

            $stepKey = "{$id}|{$phase}";
            $this->assert(! isset($seenSteps[$stepKey]), "Duplicate Stage-5 implementation step {$stepKey}");
            $seenSteps[$stepKey] = true;

            $file = $step['migration_file'];
            $this->assert(! isset($seenFiles[$file]), "Duplicate Stage-5 migration file {$file}");
            $seenFiles[$file] = true;
            $this->assert(
                str_starts_with($file, $this->implementations['stage5_root'].'/'.$phase.'/'.$id.'/'),
                "Stage-5 migration file outside node/phase directory for {$stepKey}",
            );
            $this->assert(is_file(base_path($file)), "Missing Stage-5 migration file for {$stepKey}");
            $this->assert(pathinfo($file, PATHINFO_FILENAME) === $step['migration_name'], "Stage-5 migration name mismatch for {$stepKey}");
            $this->assert(hash_file('sha256', base_path($file)) === $step['file_sha256'], "Stage-5 migration file hash mismatch for {$stepKey}");
            $this->assert($step['restart_classification'] === $nodesById[$id]['restart_classification'], "Stage-5 restart class mismatch for {$id}");
            $this->assert($step['safe_down'] === false, "FORMAL-DOC-002 must not invent destructive down for {$id}");

            $phasesByNode[$id][] = $phase;
            if (! in_array($id, $implementedNodeIds, true)) {
                $implementedNodeIds[] = $id;
            }
        }

        $implementedNodeSet = array_fill_keys($implementedNodeIds, true);
        $canonicalImplementedNodeIds = array_values(array_filter(
            array_column($this->plan['nodes'], 'node_id'),
            fn (string $id): bool => isset($implementedNodeSet[$id]),
        ));
        $this->assert($implementedNodeIds === $canonicalImplementedNodeIds, 'Stage-5 implemented nodes must preserve canonical order.');

        foreach ($implementedNodeIds as $id) {
            foreach ($nodesById[$id]['requires'] as $dependency) {
                $this->assert(in_array($dependency, $implementedNodeIds, true), "Stage-5 implementation subset is not dependency-closed at {$id}");
            }

            $registeredPhases = $phasesByNode[$id] ?? [];
            $authoritativePhases = $nodesById[$id]['phases'];
            $this->assert(
                $registeredPhases === array_slice($authoritativePhases, 0, count($registeredPhases)),
                "Stage-5 registered phases must be an authority prefix for {$id}",
            );
        }

        foreach ($expectedPhases as $phase) {
            $phaseSteps = array_values(array_filter(
                $this->implementations['implemented_steps'],
                fn (array $step): bool => $step['phase'] === $phase,
            ));
            $expectedNames = array_column($phaseSteps, 'migration_name');
            $sortedNames = $expectedNames;
            sort($sortedNames, SORT_STRING);
            $this->assert($expectedNames === $sortedNames, "Stage-5 migration filenames must preserve canonical order in phase {$phase}");
        }

        $rootFiles = glob(base_path('database/migrations/*_*.php')) ?: [];
        $this->assert($rootFiles === [], 'Stage-5 formal-documents migrations must not become discoverable by default php artisan migrate.');

        $actualFiles = [];
        $stage5Root = base_path($this->implementations['stage5_root']);
        if (is_dir($stage5Root)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage5Root));
            foreach ($iterator as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $actualFiles[] = str_replace('\\', '/', substr($item->getPathname(), strlen(base_path()) + 1));
                }
            }
        }
        sort($actualFiles, SORT_STRING);
        $registeredFiles = array_keys($seenFiles);
        sort($registeredFiles, SORT_STRING);
        $this->assert($actualFiles === $registeredFiles, 'Stage-5 formal-documents migration tree contains an unregistered or missing PHP migration.');

        $this->assert($this->executionIdentityMaterial() === $this->implementations['execution_identity'], 'Stage-5 formal-documents execution identity mismatch.');
        $this->assert(
            $this->implementations['claim_any_DDL_implemented'] === ($this->implementations['implemented_steps'] !== []),
            'Stage-5 formal-documents DDL implementation claim does not match the executable registry.',
        );
    }

    public function identity(): string
    {
        return $this->plan['plan_identity'];
    }

    public function executionIdentity(): string
    {
        return $this->implementations['execution_identity'];
    }

    public function nodeCount(): int
    {
        return count($this->plan['nodes']);
    }

    public function implementedNodeCount(): int
    {
        return count(array_unique(array_column($this->implementations['implemented_steps'], 'node_id')));
    }

    public function implementedStepCount(): int
    {
        return count($this->implementations['implemented_steps']);
    }

    /**
     * @return list<Stage5MigrationStep>
     */
    public function phaseSteps(string $phase): array
    {
        $this->assert(in_array($phase, $this->plan['phase_order'], true), "Unknown Stage-5 formal-documents migration phase {$phase}");

        return array_values(array_filter(
            $this->implementations['implemented_steps'],
            fn (array $step): bool => $step['phase'] === $phase,
        ));
    }

    /**
     * @param  list<string>  $appliedMigrations
     */
    public function assertPhaseEntry(string $phase, array $appliedMigrations): void
    {
        $phaseIndex = array_search($phase, $this->plan['phase_order'], true);
        if ($phaseIndex === false) {
            throw new LogicException("Unknown Stage-5 formal-documents migration phase {$phase}");
        }

        $this->assert($this->phaseSteps($phase) !== [], "No materialized Stage-5 formal-documents migration steps for phase {$phase}");

        $applied = array_fill_keys($appliedMigrations, true);
        foreach (array_slice($this->plan['phase_order'], 0, $phaseIndex) as $previousPhase) {
            $requiredNodeIds = [];
            foreach ($this->plan['nodes'] as $node) {
                if (in_array($previousPhase, $node['phases'], true)) {
                    $requiredNodeIds[] = $node['node_id'];
                }
            }

            if ($requiredNodeIds === []) {
                continue;
            }

            $registeredSteps = $this->phaseSteps($previousPhase);
            $registeredNodeIds = array_column($registeredSteps, 'node_id');
            $this->assert($registeredNodeIds === $requiredNodeIds, "Earlier Stage-5 phase {$previousPhase} is not fully materialized.");

            foreach ($registeredSteps as $step) {
                $this->assert(isset($applied[$step['migration_name']]), "Earlier Stage-5 phase {$previousPhase} is not fully applied: {$step['node_id']}");
            }
        }
    }

    /**
     * @return array{plan_identity: string, execution_identity: string, authority_blob: string, nodes: int, implemented_nodes: int, implemented_steps: int, stage4_plan_identity: string, stage4_execution_identity: string, phase_order: list<string>}
     */
    public function summary(): array
    {
        return [
            'plan_identity' => $this->identity(),
            'execution_identity' => $this->executionIdentity(),
            'authority_blob' => $this->plan['source']['authority_git_blob'],
            'nodes' => $this->nodeCount(),
            'implemented_nodes' => $this->implementedNodeCount(),
            'implemented_steps' => $this->implementedStepCount(),
            'stage4_plan_identity' => $this->plan['stage4_baseline']['plan_identity'],
            'stage4_execution_identity' => $this->plan['stage4_baseline']['execution_identity'],
            'phase_order' => $this->plan['phase_order'],
        ];
    }

    private function planIdentityMaterial(): string
    {
        $material = implode('|', [
            $this->plan['source']['authority_git_blob'],
            (string) $this->plan['source']['extension_contract_version'],
            (string) $this->plan['source']['phase_contract_version'],
        ])."\n";

        $material .= implode("\n", array_map(
            fn (array $node): string => implode('|', [
                $node['node_id'],
                $node['order'],
                $node['type'],
                $node['object_name'],
                implode(',', $node['requires']),
                implode(',', $node['phases']),
                $node['restart_classification'],
            ]),
            $this->plan['nodes'],
        ));

        return $material;
    }

    private function executionIdentityMaterial(): string
    {
        $lines = [
            'schema_version|'.$this->implementations['schema_version'],
            'plan_identity|'.$this->implementations['plan_identity'],
            'stage5_root|'.$this->implementations['stage5_root'],
        ];

        foreach ($this->implementations['implemented_steps'] as $step) {
            $lines[] = implode('|', [
                'step',
                $step['node_id'],
                $step['phase'],
                $step['migration_file'],
                $step['migration_name'],
                $step['file_sha256'],
                $step['restart_classification'],
                $step['safe_down'] ? '1' : '0',
            ]);
        }

        return hash('sha256', implode("\n", $lines)."\n");
    }

    /**
     * @return Stage5MigrationPlanDocument
     */
    private function decodePlan(string $path): array
    {
        $decoded = $this->decode($path);

        /** @var Stage5MigrationPlanDocument $decoded */
        return $decoded;
    }

    /**
     * @return Stage5MigrationImplementationDocument
     */
    private function decodeImplementations(string $path): array
    {
        $decoded = $this->decode($path);

        /** @var Stage5MigrationImplementationDocument $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new LogicException("Invalid JSON document: {$path}");
        }

        return $decoded;
    }

    private function gitBlobSha(string $path): string
    {
        $bytes = (string) file_get_contents($path);

        return sha1('blob '.strlen($bytes)."\0".$bytes);
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new LogicException($message);
        }
    }
}
