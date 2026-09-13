<?php

namespace App\Support\Migrations;

use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @phpstan-type MigrationSource array{authority_path: string, authority_git_blob: string, dag_contract_version: int, phase_contract_version: int, cutover_contract_version: int}
 * @phpstan-type MigrationNode array{node_id: string, type: string, order: int, object_name: string, requires: list<string>, phases: list<string>, restart_classification: string, batch_id: string}
 * @phpstan-type MigrationReviewBatch array{batch_id: string, review_limit: int, node_count: int, first_order: int, last_order: int, node_ids: list<string>}
 * @phpstan-type MigrationPlanDocument array{schema_version: int, plan_identity: string, source: MigrationSource, phase_order: list<string>, execution_control: array<string, mixed>, review_batches: list<MigrationReviewBatch>, nodes: list<MigrationNode>}
 * @phpstan-type MigrationStep array{node_id: string, phase: string, migration_file: string, migration_name: string, file_sha256: string, restart_classification: string, safe_down: bool}
 * @phpstan-type MigrationImplementationDocument array{schema_version: int, plan_identity: string, execution_identity: string, stage4_root: string, implemented_steps: list<MigrationStep>, remaining_nodes_materialize_in_review_batches: bool, claim_all_170_DDL_nodes_implemented: bool}
 */
final class MigrationPlan
{
    /** @var MigrationPlanDocument */
    private array $plan;

    /** @var MigrationImplementationDocument */
    private array $implementations;

    public function __construct()
    {
        $this->plan = $this->decodePlan(base_path('database/migration-plan/plan.json'));
        $this->implementations = $this->decodeImplementations(base_path('database/migration-plan/implementations.json'));
    }

    public function validate(): void
    {
        $expectedPhases = ['expand', 'preflight', 'write_fence', 'backfill', 'reconcile', 'validate', 'contract'];
        $this->assert($this->plan['schema_version'] === 1, 'Unsupported migration plan schema.');
        $this->assert($this->plan['phase_order'] === $expectedPhases, 'Seven-phase contract mismatch.');
        $this->assert(count($this->plan['nodes']) === 170, 'Migration plan must contain exactly 170 nodes.');
        $this->assert($this->gitBlobSha(base_path($this->plan['source']['authority_path'])) === $this->plan['source']['authority_git_blob'], 'Stage-4 migration authority blob mismatch.');
        $this->assert($this->plan['source']['authority_git_blob'] === 'ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441', 'Unexpected Stage-4 authority identity.');

        /** @var array<string, MigrationNode> $nodesById */
        $nodesById = [];
        /** @var array<int, true> $orders */
        $orders = [];
        foreach ($this->plan['nodes'] as $node) {
            $id = $node['node_id'];
            $this->assert(! isset($nodesById[$id]), "Duplicate migration node: {$id}");
            $this->assert(! isset($orders[$node['order']]), "Duplicate migration order: {$node['order']}");
            $nodesById[$id] = $node;
            $orders[$node['order']] = true;

            $last = -1;
            foreach ($node['phases'] as $phase) {
                $index = array_search($phase, $expectedPhases, true);
                if ($index === false || $index <= $last) {
                    throw new LogicException("Invalid phase path for {$id}");
                }
                $last = $index;
            }
            $this->assert(in_array($node['restart_classification'], ['restart_safe', 'manual_review'], true), "Invalid restart classification for {$id}");
        }

        foreach ($this->plan['nodes'] as $node) {
            foreach ($node['requires'] as $dependency) {
                $this->assert(isset($nodesById[$dependency]), "Unknown dependency {$dependency}");
                $this->assert($nodesById[$dependency]['order'] < $node['order'], "Dependency appears after {$node['node_id']}");
            }
        }

        $flattened = [];
        $this->assert(count($this->plan['review_batches']) === 17, 'Expected 17 review batches.');
        foreach ($this->plan['review_batches'] as $batch) {
            $this->assert($batch['node_count'] === count($batch['node_ids']) && $batch['node_count'] <= 10, "Invalid review batch {$batch['batch_id']}");
            array_push($flattened, ...$batch['node_ids']);
        }
        $this->assert($flattened === array_column($this->plan['nodes'], 'node_id'), 'Review batches must preserve exact topological order.');

        $material = implode('|', [
            $this->plan['source']['authority_git_blob'],
            (string) $this->plan['source']['dag_contract_version'],
            (string) $this->plan['source']['phase_contract_version'],
            (string) $this->plan['source']['cutover_contract_version'],
        ])."\n";
        $material .= implode("\n", array_map(
            fn (array $node): string => implode('|', [$node['node_id'], $node['order'], implode(',', $node['phases']), $node['restart_classification']]),
            $this->plan['nodes'],
        ));
        $this->assert(hash('sha256', $material) === $this->plan['plan_identity'], 'Migration plan identity mismatch.');

        $this->assert($this->implementations['schema_version'] === 2, 'Unsupported implementation registry schema.');
        $this->assert($this->implementations['plan_identity'] === $this->plan['plan_identity'], 'Implementation registry plan mismatch.');
        $this->assert($this->implementations['stage4_root'] === 'database/migrations/stage4', 'Unexpected Stage-4 migration root.');
        $this->assert($this->implementations['claim_all_170_DDL_nodes_implemented'] === false, 'Do not falsely claim all domain DDL is implemented.');

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
            $this->assert(isset($nodesById[$id]), "Implementation references unknown node {$id}");
            $this->assert(in_array($phase, $nodesById[$id]['phases'], true), "Implementation phase outside authority for {$id}");

            $stepKey = "{$id}|{$phase}";
            $this->assert(! isset($seenSteps[$stepKey]), "Duplicate implementation step {$stepKey}");
            $seenSteps[$stepKey] = true;

            $file = $step['migration_file'];
            $this->assert(! isset($seenFiles[$file]), "Duplicate migration file {$file}");
            $seenFiles[$file] = true;
            $this->assert(str_starts_with($file, $this->implementations['stage4_root'].'/'.$phase.'/'.$id.'/'), "Migration file outside node/phase directory for {$stepKey}");
            $this->assert(is_file(base_path($file)), "Missing migration file for {$stepKey}");
            $this->assert(pathinfo($file, PATHINFO_FILENAME) === $step['migration_name'], "Migration name mismatch for {$stepKey}");
            $this->assert(hash_file('sha256', base_path($file)) === $step['file_sha256'], "Migration file hash mismatch for {$stepKey}");
            $this->assert($step['restart_classification'] === $nodesById[$id]['restart_classification'], "Restart class mismatch for {$id}");
            $this->assert($step['safe_down'] === false, "S5-MIG-001 must not invent destructive down for {$id}");

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
        $this->assert($implementedNodeIds === $canonicalImplementedNodeIds, 'Implemented nodes must preserve canonical topological order.');

        foreach ($implementedNodeIds as $id) {
            foreach ($nodesById[$id]['requires'] as $dependency) {
                $this->assert(in_array($dependency, $implementedNodeIds, true), "Implementation subset is not dependency-closed at {$id}");
            }

            $registeredPhases = $phasesByNode[$id] ?? [];
            $authoritativePhases = $nodesById[$id]['phases'];
            $this->assert($registeredPhases === array_slice($authoritativePhases, 0, count($registeredPhases)), "Registered phases must be an authority prefix for {$id}");
        }

        foreach ($expectedPhases as $phase) {
            $phaseSteps = array_values(array_filter(
                $this->implementations['implemented_steps'],
                fn (array $step): bool => $step['phase'] === $phase,
            ));
            $expectedNames = array_column($phaseSteps, 'migration_name');
            $sortedNames = $expectedNames;
            sort($sortedNames, SORT_STRING);
            $this->assert($expectedNames === $sortedNames, "Migration filenames must preserve canonical order in phase {$phase}");
        }

        $rootFiles = glob(base_path('database/migrations/*_*.php')) ?: [];
        $this->assert($rootFiles === [], 'Stage-4 migrations must not be discoverable by default php artisan migrate.');

        $actualFiles = [];
        $stage4Root = base_path($this->implementations['stage4_root']);
        if (is_dir($stage4Root)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage4Root));
            foreach ($iterator as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $actualFiles[] = str_replace('\\', '/', substr($item->getPathname(), strlen(base_path()) + 1));
                }
            }
        }
        sort($actualFiles, SORT_STRING);
        $registeredFiles = array_keys($seenFiles);
        sort($registeredFiles, SORT_STRING);
        $this->assert($actualFiles === $registeredFiles, 'Stage-4 migration tree contains an unregistered or missing PHP migration.');

        $this->assert($this->executionIdentityMaterial() === $this->implementations['execution_identity'], 'Implementation execution identity mismatch.');
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

    public function batchCount(): int
    {
        return count($this->plan['review_batches']);
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
     * @return list<MigrationStep>
     */
    public function phaseSteps(string $phase): array
    {
        $this->assert(in_array($phase, $this->plan['phase_order'], true), "Unknown migration phase {$phase}");

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
            throw new LogicException("Unknown migration phase {$phase}");
        }
        $this->assert($this->phaseSteps($phase) !== [], "No materialized migration steps for phase {$phase}");

        $applied = array_fill_keys($appliedMigrations, true);
        $implementedNodeIds = array_fill_keys(
            array_values(array_unique(array_column($this->implementations['implemented_steps'], 'node_id'))),
            true,
        );

        foreach (array_slice($this->plan['phase_order'], 0, $phaseIndex) as $previousPhase) {
            $requiredNodeIds = [];
            foreach ($this->plan['nodes'] as $node) {
                if (isset($implementedNodeIds[$node['node_id']])
                    && in_array($previousPhase, $node['phases'], true)) {
                    $requiredNodeIds[] = $node['node_id'];
                }
            }

            $registeredSteps = $this->phaseSteps($previousPhase);
            $registeredNodeIds = array_column($registeredSteps, 'node_id');
            $this->assert(
                $registeredNodeIds === $requiredNodeIds,
                "Earlier phase {$previousPhase} is not fully materialized for the registered Stage-4 implementation prefix.",
            );

            foreach ($registeredSteps as $step) {
                $this->assert(isset($applied[$step['migration_name']]), "Earlier phase {$previousPhase} is not fully applied: {$step['node_id']}");
            }
        }
    }

    /**
     * @return array{plan_identity: string, execution_identity: string, authority_blob: string, nodes: int, review_batches: int, implemented_nodes: int, implemented_steps: int, phase_order: list<string>}
     */
    public function summary(): array
    {
        return [
            'plan_identity' => $this->identity(),
            'execution_identity' => $this->executionIdentity(),
            'authority_blob' => $this->plan['source']['authority_git_blob'],
            'nodes' => $this->nodeCount(),
            'review_batches' => $this->batchCount(),
            'implemented_nodes' => $this->implementedNodeCount(),
            'implemented_steps' => $this->implementedStepCount(),
            'phase_order' => $this->plan['phase_order'],
        ];
    }

    private function executionIdentityMaterial(): string
    {
        $lines = [
            'schema_version|'.$this->implementations['schema_version'],
            'plan_identity|'.$this->implementations['plan_identity'],
            'stage4_root|'.$this->implementations['stage4_root'],
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
     * @return MigrationPlanDocument
     */
    private function decodePlan(string $path): array
    {
        $decoded = $this->decode($path);

        /** @var MigrationPlanDocument $decoded */
        return $decoded;
    }

    /**
     * @return MigrationImplementationDocument
     */
    private function decodeImplementations(string $path): array
    {
        $decoded = $this->decode($path);

        /** @var MigrationImplementationDocument $decoded */
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
