<?php

namespace App\Support\Migrations;

use LogicException;

final class MigrationPlan
{
    private array $plan;
    private array $implementations;

    public function __construct()
    {
        $this->plan = $this->decode(base_path('database/migration-plan/plan.json'));
        $this->implementations = $this->decode(base_path('database/migration-plan/implementations.json'));
    }

    public function validate(): void
    {
        $expectedPhases = ['expand', 'preflight', 'write_fence', 'backfill', 'reconcile', 'validate', 'contract'];
        $this->assert($this->plan['schema_version'] === 1, 'Unsupported migration plan schema.');
        $this->assert($this->plan['phase_order'] === $expectedPhases, 'Seven-phase contract mismatch.');
        $this->assert(count($this->plan['nodes']) === 170, 'Migration plan must contain exactly 170 nodes.');
        $this->assert($this->gitBlobSha(base_path($this->plan['source']['authority_path'])) === $this->plan['source']['authority_git_blob'], 'Stage-4 migration authority blob mismatch.');
        $this->assert($this->plan['source']['authority_git_blob'] === 'ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441', 'Unexpected Stage-4 authority identity.');

        $nodesById = [];
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
                $this->assert($index !== false && $index > $last, "Invalid phase path for {$id}");
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
            $this->plan['source']['dag_contract_version'],
            $this->plan['source']['phase_contract_version'],
            $this->plan['source']['cutover_contract_version'],
        ])."\n";
        $material .= implode("\n", array_map(
            fn (array $node): string => implode('|', [$node['node_id'], $node['order'], implode(',', $node['phases']), $node['restart_classification']]),
            $this->plan['nodes'],
        ));
        $this->assert(hash('sha256', $material) === $this->plan['plan_identity'], 'Migration plan identity mismatch.');
        $this->assert($this->implementations['plan_identity'] === $this->plan['plan_identity'], 'Implementation registry plan mismatch.');

        $seen = [];
        foreach ($this->implementations['implemented_nodes'] as $implementation) {
            $id = $implementation['node_id'];
            $this->assert(isset($nodesById[$id]), "Implementation references unknown node {$id}");
            $this->assert(! isset($seen[$id]), "Duplicate implementation node {$id}");
            $seen[$id] = true;
            $this->assert(is_file(base_path($implementation['migration_file'])), "Missing migration file for {$id}");
            $this->assert($implementation['restart_classification'] === $nodesById[$id]['restart_classification'], "Restart class mismatch for {$id}");
            $this->assert(array_values(array_intersect($implementation['implemented_phases'], $nodesById[$id]['phases'])) === $implementation['implemented_phases'], "Implemented phase outside authority for {$id}");
            $this->assert($implementation['safe_down'] === false, "S5-MIG-001 must not invent destructive down for {$id}");
        }
        $this->assert(array_keys($seen) === ['MIG-EXT-BTREE-GIST'], 'S5-MIG-001 may materialize only the first restart-safe DAG node.');
        $this->assert($this->implementations['claim_all_170_DDL_nodes_implemented'] === false, 'Do not falsely claim all domain DDL is implemented.');
    }

    public function identity(): string
    {
        return $this->plan['plan_identity'];
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
        return count($this->implementations['implemented_nodes']);
    }

    public function summary(): array
    {
        return [
            'plan_identity' => $this->identity(),
            'authority_blob' => $this->plan['source']['authority_git_blob'],
            'nodes' => $this->nodeCount(),
            'review_batches' => $this->batchCount(),
            'implemented_nodes' => $this->implementedNodeCount(),
            'phase_order' => $this->plan['phase_order'],
        ];
    }

    private function decode(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assert(is_array($decoded), "Invalid JSON document: {$path}");

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
