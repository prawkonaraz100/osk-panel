<?php

namespace Tests\Feature;

use Tests\Support\Stage4TestCatalog;
use Tests\TestCase;

class Stage4MigrationPostcheckTest extends TestCase
{
    public function test_dbt_core_099_zero_gap_traceability_authority_is_executable(): void
    {
        $catalog = new Stage4TestCatalog;
        $summary = $catalog->zeroGapSummary();

        $this->assertCount(491, $catalog->entries());
        $this->assertSame(['DBT-CORE-099', 'DBT-CORE-100'], $catalog->implementedIds());
        $this->assertCount(489, $catalog->pendingIds());
        $this->assertSame([
            'concurrency' => 29,
            'migration_postcheck' => 2,
            'migration_preflight' => 14,
            'projection' => 31,
            'schema_constraint' => 180,
            'security_storage' => 57,
            'transaction' => 178,
        ], $catalog->classCounts());

        $this->assertSame(77, $summary['resolved_blockers_expected_after_this_step']);
        $this->assertSame([], $summary['resolved_blockers_without_coverage']);
        $this->assertSame(183, $summary['critical_constraints_total']);
        $this->assertSame([], $summary['critical_constraints_without_final_test_id']);
        $this->assertSame(45, $summary['transactional_invariants_total']);
        $this->assertSame([], $summary['transactional_invariants_without_final_test_id']);
        $this->assertSame(1581, $summary['bounded_local_required_test_occurrences']);
        $this->assertSame(0, $summary['bounded_local_required_test_occurrences_not_preserved']);
        $this->assertSame([], $summary['unknown_final_test_refs']);
        $this->assertSame([], $summary['unknown_or_orphan_source_refs']);
        $this->assertSame([], $summary['final_test_id_collisions']);
        $this->assertSame('PASS_ZERO_GAPS', $summary['result']);
        $this->assertSame(
            'implemented_executable_assertion',
            $catalog->entry('DBT-CORE-099')['runtime_implementation_status'],
        );
    }

    public function test_dbt_core_100_final_aggregate_sync_is_executable(): void
    {
        $this->assertSame(
            Stage4TestCatalog::AUTHORITY_BLOB,
            $this->gitBlobSha(base_path(Stage4TestCatalog::AUTHORITY_PATH)),
        );
        $this->assertSame(
            'b0629cc48e26798d945356eaa1d0c9b1187aa542',
            $this->gitBlobSha(base_path('specs/database/core-schema.yml')),
        );
        $this->assertSame(
            '306dabaa58223abe2f3b2a34b888b075c88e9ec4',
            $this->gitBlobSha(base_path('specs/gates/stage-4-database-contract-gate.yml')),
        );
        $this->assertSame(
            'a48eacd92d4a9c8e989f5d70c3bec95b9231a77c',
            $this->gitBlobSha(base_path('docs/87-physical-database-schema.md')),
        );
        $this->assertSame(
            '6f76255742d4902546f45c28d54c1da6ee3d3fd7',
            $this->gitBlobSha(base_path('docs/116-stage-4-final-migration-order-invariant-matrix-audit.md')),
        );

        $catalog = new Stage4TestCatalog;
        $entry = $catalog->entry('DBT-CORE-100');
        $this->assertCount(491, $catalog->entries());
        $this->assertSame('migration_postcheck', $entry['test_class']);
        $this->assertSame('implemented_executable_assertion', $entry['runtime_implementation_status']);
    }

    private function gitBlobSha(string $path): string
    {
        $bytes = (string) file_get_contents($path);

        return sha1('blob '.strlen($bytes)."\0".$bytes);
    }
}
