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
        $this->assertSame(['DBT-CORE-009', 'DBT-CORE-015', 'DBT-IAM-005', 'DBT-IAM-009', 'DBT-IAM-013', 'DBT-IAM-015', 'DBT-IAM-016', 'DBT-IAM-017', 'DBT-IAM-020', 'DBT-AUD-003', 'DBT-AUD-005', 'DBT-CORE-099', 'DBT-CORE-100', 'DBT-RES-001', 'DBT-RES-002', 'DBT-RES-003', 'DBT-RES-004', 'DBT-RES-005', 'DBT-RES-006', 'DBT-RES-007', 'DBT-RES-008', 'DBT-RES-009', 'DBT-RES-010', 'DBT-RES-011', 'DBT-RES-012', 'DBT-RES-013', 'DBT-RES-014', 'DBT-RES-015', 'DBT-RES-016', 'DBT-RES-017', 'DBT-RES-018', 'DBT-RES-046', 'DBT-RES-047', 'DBT-RES-048', 'DBT-RES-049', 'DBT-RES-050', 'DBT-CAL-002', 'DBT-CAL-003', 'DBT-CAL-004', 'DBT-CAL-011', 'DBT-CAL-012', 'DBT-CAL-013', 'DBT-CAL-014', 'DBT-CAL-016', 'DBT-CAL-017', 'DBT-TRN-015', 'DBT-TRN-017', 'DBT-TRN-018', 'DBT-CAL-018', 'DBT-CAL-019', 'DBT-CAL-020', 'DBT-CAL-021', 'DBT-CAL-022', 'DBT-CAL-024', 'DBT-CAL-025', 'DBT-CAL-051', 'DBT-CAL-052', 'DBT-CAL-053', 'DBT-CAL-054', 'DBT-CAL-055', 'DBT-TRN-003', 'DBT-IAM-032', 'DBT-CORE-021', 'DBT-TRN-006', 'DBT-TRN-007', 'DBT-TRN-008', 'DBT-CORE-023', 'DBT-TRN-013', 'DBT-TRN-035', 'DBT-TRN-037', 'DBT-TRN-038', 'DBT-FIN-001', 'DBT-FIN-005', 'DBT-LIC-001', 'DBT-LIC-002', 'DBT-LIC-003', 'DBT-LIC-004', 'DBT-LIC-051'], $catalog->implementedIds());
        $this->assertCount(413, $catalog->pendingIds());
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
