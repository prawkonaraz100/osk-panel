<?php

namespace App\Support\Migrations;

use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @phpstan-type CommerceSource array{authority_path:string, authority_git_blob:string, extension_contract_version:int, phase_contract_version:int}
 * @phpstan-type CommerceStage4Baseline array{plan_identity:string, execution_identity:string, authority_git_blob:string, nodes:int, implemented_nodes:int, implemented_steps:int}
 * @phpstan-type PreservedExtension array{plan_identity:string, execution_identity:string}
 * @phpstan-type CommerceNode array{node_id:string, type:string, order:int, object_name:string, requires:list<string>, phases:list<string>, restart_classification:string}
 * @phpstan-type CommercePlan array{schema_version:int, extension_id:string, plan_identity:string, source:CommerceSource, stage4_baseline:CommerceStage4Baseline, preserved_stage5_formal_documents:PreservedExtension, preserved_stage5_social_identity:PreservedExtension, phase_order:list<string>, execution_control:array<string,mixed>, nodes:list<CommerceNode>}
 * @phpstan-type CommerceStep array{node_id:string, phase:string, migration_file:string, migration_name:string, file_git_blob:string, restart_classification:string, safe_down:bool}
 * @phpstan-type CommerceRegistry array{schema_version:int, plan_identity:string, execution_identity:string, stage5_root:string, implemented_steps:list<CommerceStep>, claim_any_DDL_implemented:bool}
 */
final class Stage5CommerceOrderSequenceMigrationPlan
{
    public const AUTHORITY_BLOB = 'aa1ae73353481549a4ada229170ad2a492086b1a';

    public const STAGE4_PLAN_IDENTITY = 'd2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10';

    public const STAGE4_EXECUTION_IDENTITY = '82da84d3efb312d78b432ad6081491c04b03569a0f250722d6befc11ca233712';

    public const FORMAL_DOCUMENTS_PLAN_IDENTITY = '34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99';

    public const FORMAL_DOCUMENTS_EXECUTION_IDENTITY = '31704fcab61761aa9a952d824dc543a6f46e7a3717792d0a9349cefaaf57651f';

    public const SOCIAL_IDENTITY_PLAN_IDENTITY = '85319b7c91cf9046e0ac8c00772aad141462e3897363b32d0ba222a50f73fe4d';

    public const SOCIAL_IDENTITY_EXECUTION_IDENTITY = 'b4a73588390d837fbb460d374114585064445462ddcba667d3919c7052f76cd0';

    /** @var CommercePlan */
    private array $plan;

    /** @var CommerceRegistry */
    private array $implementations;

    public function __construct()
    {
        /** @var CommercePlan $plan */
        $plan = $this->decode(base_path('database/migration-plan/stage5-commerce-order-sequence-plan.json'));
        /** @var CommerceRegistry $implementations */
        $implementations = $this->decode(base_path('database/migration-plan/stage5-commerce-order-sequence-implementations.json'));

        $this->plan = $plan;
        $this->implementations = $implementations;
    }

    public function validate(): void
    {
        $expectedPhases = ['expand', 'preflight', 'write_fence', 'backfill', 'reconcile', 'validate', 'contract'];
        $expectedNodePhases = ['preflight', 'expand', 'backfill', 'validate'];

        $this->assert($this->plan['schema_version'] === 1, 'Unsupported Stage-5 commerce order-sequence plan schema.');
        $this->assert($this->plan['extension_id'] === 'commerce_order_sequence_allocator_v1', 'Unexpected commerce order-sequence extension id.');
        $this->assert($this->plan['phase_order'] === $expectedPhases, 'Commerce order-sequence phase contract mismatch.');
        $this->assert(count($this->plan['nodes']) === 1, 'Commerce order-sequence plan must contain exactly one node.');

        $source = $this->plan['source'];
        $this->assert($source['authority_path'] === 'specs/database/commerce-order-sequence-migration-extension.yml', 'Unexpected commerce order-sequence authority path.');
        $this->assert($this->gitBlobSha(base_path($source['authority_path'])) === $source['authority_git_blob'], 'Commerce order-sequence authority blob mismatch.');
        $this->assert($source['authority_git_blob'] === self::AUTHORITY_BLOB, 'Unexpected commerce order-sequence authority identity.');

        $stage4 = new MigrationPlan;
        $stage4->validate();
        $summary = $stage4->summary();
        $baseline = $this->plan['stage4_baseline'];
        $this->assert($stage4->identity() === self::STAGE4_PLAN_IDENTITY, 'Live Stage-4 plan identity differs from commerce corrective baseline.');
        $this->assert($stage4->executionIdentity() === self::STAGE4_EXECUTION_IDENTITY, 'Live Stage-4 execution identity differs from commerce corrective baseline.');
        $this->assert($baseline['plan_identity'] === self::STAGE4_PLAN_IDENTITY, 'Commerce corrective Stage-4 plan baseline changed.');
        $this->assert($baseline['execution_identity'] === self::STAGE4_EXECUTION_IDENTITY, 'Commerce corrective Stage-4 execution baseline changed.');
        $this->assert($summary['authority_blob'] === $baseline['authority_git_blob'], 'Live Stage-4 authority blob differs from commerce corrective baseline.');
        $this->assert($stage4->nodeCount() === $baseline['nodes'], 'Live Stage-4 node count differs from commerce corrective baseline.');
        $this->assert($stage4->implementedNodeCount() === $baseline['implemented_nodes'], 'Live Stage-4 implemented-node count differs from commerce corrective baseline.');
        $this->assert($stage4->implementedStepCount() === $baseline['implemented_steps'], 'Live Stage-4 implemented-step count differs from commerce corrective baseline.');

        $formal = new Stage5FormalDocumentsMigrationPlan;
        $formal->validate();
        $this->assert($formal->identity() === self::FORMAL_DOCUMENTS_PLAN_IDENTITY, 'Formal-documents plan changed during commerce corrective.');
        $this->assert($formal->executionIdentity() === self::FORMAL_DOCUMENTS_EXECUTION_IDENTITY, 'Formal-documents execution changed during commerce corrective.');

        $social = new Stage5SocialIdentityMigrationPlan;
        $social->validate();
        $this->assert($social->identity() === self::SOCIAL_IDENTITY_PLAN_IDENTITY, 'Social-identity plan changed during commerce corrective.');
        $this->assert($social->executionIdentity() === self::SOCIAL_IDENTITY_EXECUTION_IDENTITY, 'Social-identity execution changed during commerce corrective.');

        $node = $this->plan['nodes'][0];
        $this->assert($node['node_id'] === 'S5COM-TBL-ORGANIZATION-COMMERCE-ORDER-SEQUENCES', 'Unexpected commerce corrective node id.');
        $this->assert($node['type'] === 'table_plus_initialization', 'Unexpected commerce corrective node type.');
        $this->assert($node['order'] === 10, 'Unexpected commerce corrective node order.');
        $this->assert($node['object_name'] === 'organization_commerce_order_sequences', 'Unexpected commerce corrective object name.');
        $this->assert($node['requires'] === [], 'Commerce corrective must not invent extension dependencies.');
        $this->assert($node['phases'] === $expectedNodePhases, 'Commerce corrective node phase path mismatch.');
        $this->assert($node['restart_classification'] === 'manual_review', 'Commerce corrective must remain manual-review classified.');
        $this->assert(hash('sha256', $this->planIdentityMaterial()) === $this->plan['plan_identity'], 'Stage-5 commerce order-sequence plan identity mismatch.');

        $registry = $this->implementations;
        $this->assert($registry['schema_version'] === 1, 'Unsupported Stage-5 commerce order-sequence registry schema.');
        $this->assert($registry['plan_identity'] === $this->plan['plan_identity'], 'Commerce order-sequence registry plan mismatch.');
        $this->assert($registry['stage5_root'] === 'database/migrations/stage5/commerce-order-sequence', 'Unexpected commerce order-sequence migration root.');
        $this->assert(count($registry['implemented_steps']) === 4, 'Commerce corrective must materialize exactly four migration steps.');
        $this->assert(array_column($registry['implemented_steps'], 'phase') === $expectedNodePhases, 'Commerce corrective registry phase order mismatch.');

        $registeredFiles = [];
        foreach ($registry['implemented_steps'] as $step) {
            $this->assert($step['node_id'] === $node['node_id'], 'Commerce registry references an unexpected node.');
            $this->assert(in_array($step['phase'], $expectedNodePhases, true), 'Commerce registry references a phase outside authority.');
            $this->assert(
                str_starts_with($step['migration_file'], $registry['stage5_root'].'/'.$step['phase'].'/'.$node['node_id'].'/'),
                'Commerce migration file is outside its registered node/phase directory.',
            );
            $this->assert(is_file(base_path($step['migration_file'])), 'Registered commerce migration file is missing.');
            $this->assert(pathinfo($step['migration_file'], PATHINFO_FILENAME) === $step['migration_name'], 'Commerce migration name mismatch.');
            $this->assert($this->gitBlobSha(base_path($step['migration_file'])) === $step['file_git_blob'], 'Commerce migration blob mismatch.');
            $this->assert($step['restart_classification'] === 'manual_review', 'Commerce migration restart classification mismatch.');
            $this->assert($step['safe_down'] === false, 'Commerce corrective must not invent automatic destructive down.');
            $this->assert(! isset($registeredFiles[$step['migration_file']]), 'Duplicate commerce migration file registration.');
            $registeredFiles[$step['migration_file']] = true;
        }

        $actualFiles = [];
        $root = base_path($registry['stage5_root']);
        if (is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($iterator as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $actualFiles[] = str_replace('\\', '/', substr($item->getPathname(), strlen(base_path()) + 1));
                }
            }
        }

        sort($actualFiles, SORT_STRING);
        $registered = array_keys($registeredFiles);
        sort($registered, SORT_STRING);
        $this->assert($actualFiles === $registered, 'Commerce order-sequence migration tree contains an unregistered or missing PHP migration.');

        $rootMigrations = glob(base_path('database/migrations/*_*.php')) ?: [];
        $this->assert($rootMigrations === [], 'Commerce corrective migrations must not become discoverable by default php artisan migrate.');

        $this->assert(hash('sha256', $this->executionIdentityMaterial()) === $registry['execution_identity'], 'Stage-5 commerce order-sequence execution identity mismatch.');
        $this->assert($registry['claim_any_DDL_implemented'] === true, 'Commerce corrective registry must claim its materialized DDL.');
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

    /** @return list<CommerceStep> */
    public function phaseSteps(string $phase): array
    {
        $this->assert(in_array($phase, $this->plan['phase_order'], true), "Unknown Stage-5 commerce order-sequence phase {$phase}.");

        return array_values(array_filter(
            $this->implementations['implemented_steps'],
            static fn (array $step): bool => $step['phase'] === $phase,
        ));
    }

    /** @param list<string> $appliedMigrations */
    public function assertPhaseEntry(string $phase, array $appliedMigrations): void
    {
        $nodePhases = $this->plan['nodes'][0]['phases'];
        $phaseIndex = array_search($phase, $nodePhases, true);
        if ($phaseIndex === false) {
            throw new LogicException("Phase {$phase} is not materialized for the commerce corrective node.");
        }

        $applied = array_fill_keys($appliedMigrations, true);
        foreach (array_slice($nodePhases, 0, $phaseIndex) as $priorPhase) {
            $prior = $this->phaseSteps($priorPhase);
            $this->assert(count($prior) === 1, "Earlier commerce corrective phase {$priorPhase} is not fully registered.");
            $this->assert(isset($applied[$prior[0]['migration_name']]), "Earlier commerce corrective phase {$priorPhase} is not fully applied.");
        }
    }

    /** @return array<string,mixed> */
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
            'preserved_formal_documents_plan_identity' => $this->plan['preserved_stage5_formal_documents']['plan_identity'],
            'preserved_formal_documents_execution_identity' => $this->plan['preserved_stage5_formal_documents']['execution_identity'],
            'preserved_social_identity_plan_identity' => $this->plan['preserved_stage5_social_identity']['plan_identity'],
            'preserved_social_identity_execution_identity' => $this->plan['preserved_stage5_social_identity']['execution_identity'],
            'phase_order' => $this->plan['phase_order'],
        ];
    }

    private function planIdentityMaterial(): string
    {
        $node = $this->plan['nodes'][0];

        return implode('|', [
            $this->plan['source']['authority_git_blob'],
            (string) $this->plan['source']['extension_contract_version'],
            (string) $this->plan['source']['phase_contract_version'],
        ])."\n".implode('|', [
            $node['node_id'],
            (string) $node['order'],
            $node['type'],
            $node['object_name'],
            implode(',', $node['requires']),
            implode(',', $node['phases']),
            $node['restart_classification'],
        ]);
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
                $step['file_git_blob'],
                $step['restart_classification'],
                $step['safe_down'] ? '1' : '0',
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /** @return array<string,mixed> */
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
