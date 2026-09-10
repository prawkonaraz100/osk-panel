<?php

namespace Tests\Support;

use LogicException;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;

final class Stage4TestCatalog
{
    public const AUTHORITY_PATH = 'specs/database/final-migration-order-invariant-matrix.yml';

    public const AUTHORITY_BLOB = 'ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441';

    /** @var array<int, array<string, mixed>> */
    private array $entries;

    /** @var array<string, array{class: class-string, method: string, scope: string}> */
    private array $implementations;

    /** @var array<string, mixed> */
    private array $zeroGapSummary;

    public function __construct()
    {
        $authorityPath = base_path(self::AUTHORITY_PATH);
        $this->assert($this->gitBlobSha($authorityPath) === self::AUTHORITY_BLOB, 'Stage-4 test authority blob mismatch.');

        $matrix = Yaml::parseFile($authorityPath);
        $this->assert(is_array($matrix), 'Stage-4 test authority must parse as a mapping.');

        $base = $matrix['invariant_test_matrix']['tests'] ?? null;
        $contract = $matrix['test_coverage_traceability_contract'] ?? null;
        $anchors = is_array($contract) ? ($contract['final_test_catalog']['coverage_anchor_tests'] ?? null) : null;
        $summary = is_array($contract) ? ($contract['zero_gap_summary'] ?? null) : null;

        $this->assert(is_array($base), 'Base invariant test catalog missing.');
        $this->assert(is_array($anchors), 'Coverage anchor test catalog missing.');
        $this->assert(is_array($summary), 'Zero-gap summary missing.');

        $this->entries = array_values(array_merge($base, $anchors));
        $this->zeroGapSummary = $summary;
        $implementations = require base_path('tests/Contracts/stage4-test-implementations.php');
        $this->assert(is_array($implementations), 'Stage-4 executable implementation registry must be an array.');
        $this->implementations = $implementations;

        $this->validate();
    }

    /** @return array<int, array<string, mixed>> */
    public function entries(): array
    {
        return array_map(function (array $entry): array {
            $id = (string) $entry['test_id'];
            $implementation = $this->implementations[$id] ?? null;
            $entry['runtime_implementation_status'] = $implementation === null
                ? 'pending_domain_materialization'
                : 'implemented_executable_assertion';
            $entry['runtime_implementation'] = $implementation;

            return $entry;
        }, $this->entries);
    }

    /** @return list<string> */
    public function implementedIds(): array
    {
        return array_values(array_keys($this->implementations));
    }

    /** @return list<string> */
    public function pendingIds(): array
    {
        $implemented = array_fill_keys($this->implementedIds(), true);

        return array_values(array_map(
            static fn (array $entry): string => (string) $entry['test_id'],
            array_filter($this->entries, static fn (array $entry): bool => ! isset($implemented[(string) $entry['test_id']])),
        ));
    }

    /** @return array<string, int> */
    public function classCounts(): array
    {
        $counts = [];
        foreach ($this->entries as $entry) {
            $class = (string) $entry['test_class'];
            $counts[$class] = ($counts[$class] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /** @return array<string, mixed> */
    public function zeroGapSummary(): array
    {
        return $this->zeroGapSummary;
    }

    /** @return array<string, mixed> */
    public function entry(string $testId): array
    {
        foreach ($this->entries() as $entry) {
            if (($entry['test_id'] ?? null) === $testId) {
                return $entry;
            }
        }

        throw new LogicException("Unknown Stage-4 test ID {$testId}.");
    }

    private function validate(): void
    {
        $this->assert(count($this->entries) === 491, 'Final Stage-4 test catalog must contain exactly 491 IDs.');

        $ids = [];
        foreach ($this->entries as $entry) {
            $this->assert(is_array($entry), 'Each Stage-4 test entry must be a mapping.');
            $id = $entry['test_id'] ?? null;
            $class = $entry['test_class'] ?? null;
            $this->assert(is_string($id) && str_starts_with($id, 'DBT-'), 'Every Stage-4 test requires a DBT-* ID.');
            $this->assert(! isset($ids[$id]), "Duplicate Stage-4 test ID {$id}.");
            $ids[$id] = true;
            $this->assert(is_string($class) && $class !== '', "Stage-4 test {$id} is missing a test class.");
        }

        foreach ($this->implementations as $id => $implementation) {
            $this->assert(isset($ids[$id]), "Executable implementation references unknown Stage-4 test ID {$id}.");
            $this->assert(isset($implementation['class'], $implementation['method'], $implementation['scope']), "Executable implementation metadata incomplete for {$id}.");
            $class = $implementation['class'];
            $method = $implementation['method'];
            $this->assert(is_string($class) && class_exists($class), "Executable test class missing for {$id}.");
            $this->assert(is_string($method) && str_starts_with($method, 'test_') && method_exists($class, $method), "Executable test method missing for {$id}.");
            $reflection = new ReflectionMethod($class, $method);
            $this->assert($reflection->isPublic(), "Executable test method must be public for {$id}.");
        }

        $this->assert(count($this->implementedIds()) + count($this->pendingIds()) === 491, 'Every Stage-4 test ID must have exactly one runtime implementation state.');
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
