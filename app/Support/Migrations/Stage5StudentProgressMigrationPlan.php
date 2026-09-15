<?php

namespace App\Support\Migrations;

use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @phpstan-type ProgressSource array{authority_path:string,authority_git_blob:string,extension_contract_version:int,phase_contract_version:int}
 * @phpstan-type ProgressBaseline array{plan_identity:string,execution_identity:string,authority_git_blob:string,nodes:int,implemented_nodes:int,implemented_steps:int}
 * @phpstan-type PreservedExtension array{plan_identity:string,execution_identity:string}
 * @phpstan-type ProgressNode array{node_id:string,type:string,order:int,object_name:string,requires:list<string>,phases:list<string>,restart_classification:string}
 * @phpstan-type ProgressPlan array{schema_version:int,extension_id:string,plan_identity:string,source:ProgressSource,stage4_baseline:ProgressBaseline,preserved_stage5_formal_documents:PreservedExtension,preserved_stage5_social_identity:PreservedExtension,preserved_stage5_commerce_order_sequence:PreservedExtension,phase_order:list<string>,execution_control:array<string,mixed>,nodes:list<ProgressNode>}
 * @phpstan-type ProgressStep array{node_id:string,phase:string,migration_file:string,migration_name:string,file_git_blob:string,restart_classification:string,safe_down:bool}
 * @phpstan-type ProgressRegistry array{schema_version:int,plan_identity:string,execution_identity:string,stage5_root:string,implemented_steps:list<ProgressStep>,claim_any_DDL_implemented:bool}
 */
final class Stage5StudentProgressMigrationPlan
{
    public const AUTHORITY_BLOB = '9f9b000fcbee2086c18da3ab0ac21e966c3a4c90';

    public const STAGE4_PLAN_IDENTITY = 'd2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10';

    public const STAGE4_EXECUTION_IDENTITY = '82da84d3efb312d78b432ad6081491c04b03569a0f250722d6befc11ca233712';

    public const FORMAL_DOCUMENTS_PLAN_IDENTITY = '34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99';

    public const FORMAL_DOCUMENTS_EXECUTION_IDENTITY = '31704fcab61761aa9a952d824dc543a6f46e7a3717792d0a9349cefaaf57651f';

    public const SOCIAL_IDENTITY_PLAN_IDENTITY = '85319b7c91cf9046e0ac8c00772aad141462e3897363b32d0ba222a50f73fe4d';

    public const SOCIAL_IDENTITY_EXECUTION_IDENTITY = 'b4a73588390d837fbb460d374114585064445462ddcba667d3919c7052f76cd0';

    public const COMMERCE_ORDER_SEQUENCE_PLAN_IDENTITY = '6d03c38e47ba30d07a3a090d514ac09384e1ea5e668947b95eeaaa6fce49c019';

    public const COMMERCE_ORDER_SEQUENCE_EXECUTION_IDENTITY = '4dc74dce0acaf9904a013a08c7f85d0539ccb8af6fcff0cc690aae2629100554';

    /** @var ProgressPlan */
    private array $plan;

    /** @var ProgressRegistry */
    private array $implementations;

    public function __construct()
    {
        /** @var ProgressPlan $plan */
        $plan = $this->decode(base_path('database/migration-plan/stage5-student-progress-plan.json'));
        /** @var ProgressRegistry $implementations */
        $implementations = $this->decode(base_path('database/migration-plan/stage5-student-progress-implementations.json'));

        $this->plan = $plan;
        $this->implementations = $implementations;
    }

    public function validate(): void
    {
        $expectedPhases = ['expand', 'preflight', 'write_fence', 'backfill', 'reconcile', 'validate', 'contract'];
        $expectedNodePhases = ['preflight', 'expand', 'validate'];

        $this->assert($this->plan['schema_version'] === 1, 'Unsupported Stage-5 student progress plan schema.');
        $this->assert($this->plan['extension_id'] === 'student_progress_projection_v1', 'Unexpected student progress extension id.');
        $this->assert($this->plan['phase_order'] === $expectedPhases, 'Student progress phase contract mismatch.');
        $this->assert(count($this->plan['nodes']) === 2, 'Student progress plan must contain exactly two nodes.');

        $source = $this->plan['source'];
        $this->assert($source['authority_path'] === 'specs/database/student-progress-projection-migration-extension.yml', 'Unexpected student progress authority path.');
        $this->assert($this->gitBlobSha(base_path($source['authority_path'])) === $source['authority_git_blob'], 'Student progress authority blob mismatch.');
        $this->assert($source['authority_git_blob'] === self::AUTHORITY_BLOB, 'Unexpected student progress authority identity.');

        $stage4 = new MigrationPlan;
        $stage4->validate();
        $summary = $stage4->summary();
        $baseline = $this->plan['stage4_baseline'];
        $this->assert($stage4->identity() === self::STAGE4_PLAN_IDENTITY, 'Live Stage-4 plan identity differs from student progress corrective baseline.');
        $this->assert($stage4->executionIdentity() === self::STAGE4_EXECUTION_IDENTITY, 'Live Stage-4 execution identity differs from student progress corrective baseline.');
        $this->assert($baseline['plan_identity'] === self::STAGE4_PLAN_IDENTITY, 'Student progress Stage-4 plan baseline changed.');
        $this->assert($baseline['execution_identity'] === self::STAGE4_EXECUTION_IDENTITY, 'Student progress Stage-4 execution baseline changed.');
        $this->assert($summary['authority_blob'] === $baseline['authority_git_blob'], 'Live Stage-4 authority blob differs from student progress baseline.');
        $this->assert($stage4->nodeCount() === $baseline['nodes'], 'Live Stage-4 node count differs from student progress baseline.');
        $this->assert($stage4->implementedNodeCount() === $baseline['implemented_nodes'], 'Live Stage-4 implemented-node count differs from student progress baseline.');
        $this->assert($stage4->implementedStepCount() === $baseline['implemented_steps'], 'Live Stage-4 implemented-step count differs from student progress baseline.');

        $formal = new Stage5FormalDocumentsMigrationPlan;
        $formal->validate();
        $this->assert($formal->identity() === self::FORMAL_DOCUMENTS_PLAN_IDENTITY, 'Formal-documents plan changed during student progress corrective.');
        $this->assert($formal->executionIdentity() === self::FORMAL_DOCUMENTS_EXECUTION_IDENTITY, 'Formal-documents execution changed during student progress corrective.');

        $social = new Stage5SocialIdentityMigrationPlan;
        $social->validate();
        $this->assert($social->identity() === self::SOCIAL_IDENTITY_PLAN_IDENTITY, 'Social-identity plan changed during student progress corrective.');
        $this->assert($social->executionIdentity() === self::SOCIAL_IDENTITY_EXECUTION_IDENTITY, 'Social-identity execution changed during student progress corrective.');

        $commerce = new Stage5CommerceOrderSequenceMigrationPlan;
        $commerce->validate();
        $this->assert($commerce->identity() === self::COMMERCE_ORDER_SEQUENCE_PLAN_IDENTITY, 'Commerce allocator plan changed during student progress corrective.');
        $this->assert($commerce->executionIdentity() === self::COMMERCE_ORDER_SEQUENCE_EXECUTION_IDENTITY, 'Commerce allocator execution changed during student progress corrective.');

        $expectedNodes = [
            [
                'node_id' => 'S5PROG-TBL-LEARNING-PROGRESS-SOURCE-BINDINGS',
                'type' => 'table',
                'order' => 10,
                'object_name' => 'learning_progress_source_bindings',
                'requires' => [],
                'phases' => $expectedNodePhases,
                'restart_classification' => 'manual_review',
            ],
            [
                'node_id' => 'S5PROG-TBL-STUDENT-LEARNING-PROGRESS-PROJECTIONS',
                'type' => 'table',
                'order' => 20,
                'object_name' => 'student_learning_progress_projections',
                'requires' => ['S5PROG-TBL-LEARNING-PROGRESS-SOURCE-BINDINGS'],
                'phases' => $expectedNodePhases,
                'restart_classification' => 'manual_review',
            ],
        ];
        $this->assert($this->plan['nodes'] === $expectedNodes, 'Student progress corrective node contract mismatch.');
        $this->assert(hash('sha256', $this->planIdentityMaterial()) === $this->plan['plan_identity'], 'Stage-5 student progress plan identity mismatch.');

        $registry = $this->implementations;
        $this->assert($registry['schema_version'] === 1, 'Unsupported Stage-5 student progress registry schema.');
        $this->assert($registry['plan_identity'] === $this->plan['plan_identity'], 'Student progress registry plan mismatch.');
        $this->assert($registry['stage5_root'] === 'database/migrations/stage5/student-progress', 'Unexpected student progress migration root.');
        $this->assert(count($registry['implemented_steps']) === 6, 'Student progress corrective must materialize exactly six migration steps.');

        $registeredFiles = [];
        foreach ($registry['implemented_steps'] as $step) {
            $this->assert(in_array($step['node_id'], array_column($expectedNodes, 'node_id'), true), 'Student progress registry references an unexpected node.');
            $this->assert(in_array($step['phase'], $expectedNodePhases, true), 'Student progress registry references a phase outside authority.');
            $this->assert(
                str_starts_with($step['migration_file'], $registry['stage5_root'].'/'.$step['phase'].'/'.$step['node_id'].'/'),
                'Student progress migration file is outside its registered node/phase directory.',
            );
            $this->assert(is_file(base_path($step['migration_file'])), 'Registered student progress migration file is missing.');
            $this->assert(pathinfo($step['migration_file'], PATHINFO_FILENAME) === $step['migration_name'], 'Student progress migration name mismatch.');
            $this->assert($this->gitBlobSha(base_path($step['migration_file'])) === $step['file_git_blob'], 'Student progress migration blob mismatch.');
            $this->assert($step['restart_classification'] === 'manual_review', 'Student progress migration restart classification mismatch.');
            $this->assert($step['safe_down'] === false, 'Student progress corrective must not invent automatic destructive down.');
            $this->assert(! isset($registeredFiles[$step['migration_file']]), 'Duplicate student progress migration file registration.');
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
        $this->assert($actualFiles === $registered, 'Student progress migration tree contains an unregistered or missing PHP migration.');

        $rootMigrations = glob(base_path('database/migrations/*_*.php')) ?: [];
        $this->assert($rootMigrations === [], 'Student progress corrective migrations must not become discoverable by default php artisan migrate.');

        $this->assert(hash('sha256', $this->executionIdentityMaterial()) === $registry['execution_identity'], 'Stage-5 student progress execution identity mismatch.');
        $this->assert($registry['claim_any_DDL_implemented'] === true, 'Student progress registry must claim its materialized DDL.');
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

    /** @return list<ProgressStep> */
    public function phaseSteps(string $phase): array
    {
        $this->assert(in_array($phase, $this->plan['phase_order'], true), "Unknown Stage-5 student progress phase {$phase}.");

        return array_values(array_filter(
            $this->implementations['implemented_steps'],
            static fn (array $step): bool => $step['phase'] === $phase,
        ));
    }

    /** @param list<string> $appliedMigrations */
    public function assertPhaseEntry(string $phase, array $appliedMigrations): void
    {
        $materializedPhases = ['preflight', 'expand', 'validate'];
        $phaseIndex = array_search($phase, $materializedPhases, true);
        if ($phaseIndex === false) {
            throw new LogicException("Phase {$phase} is not materialized for the student progress corrective.");
        }

        $applied = array_fill_keys($appliedMigrations, true);
        foreach (array_slice($materializedPhases, 0, $phaseIndex) as $priorPhase) {
            $prior = $this->phaseSteps($priorPhase);
            $this->assert(count($prior) === 2, "Earlier student progress phase {$priorPhase} is not fully registered.");
            foreach ($prior as $step) {
                $this->assert(isset($applied[$step['migration_name']]), "Earlier student progress phase {$priorPhase} is not fully applied.");
            }
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
            'preserved_commerce_order_sequence_plan_identity' => $this->plan['preserved_stage5_commerce_order_sequence']['plan_identity'],
            'preserved_commerce_order_sequence_execution_identity' => $this->plan['preserved_stage5_commerce_order_sequence']['execution_identity'],
            'phase_order' => $this->plan['phase_order'],
        ];
    }

    private function planIdentityMaterial(): string
    {
        $lines = [
            implode('|', [
                $this->plan['source']['authority_git_blob'],
                (string) $this->plan['source']['extension_contract_version'],
                (string) $this->plan['source']['phase_contract_version'],
            ]),
        ];

        foreach ($this->plan['nodes'] as $node) {
            $lines[] = implode('|', [
                $node['node_id'],
                (string) $node['order'],
                $node['type'],
                $node['object_name'],
                implode(',', $node['requires']),
                implode(',', $node['phases']),
                $node['restart_classification'],
            ]);
        }

        return implode("\n", $lines);
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
