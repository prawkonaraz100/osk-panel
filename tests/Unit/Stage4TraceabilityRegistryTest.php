<?php

namespace Tests\Unit;

use Tests\Support\Stage4TestCatalog;
use Tests\TestCase;

class Stage4TraceabilityRegistryTest extends TestCase
{
    public function test_every_stage_four_test_id_has_exactly_one_truthful_runtime_state(): void
    {
        $catalog = new Stage4TestCatalog;
        $entries = $catalog->entries();

        $this->assertCount(491, $entries);
        $this->assertCount(491, array_unique(array_column($entries, 'test_id')));
        $this->assertSame(['DBT-CORE-009', 'DBT-CORE-015', 'DBT-IAM-005', 'DBT-IAM-009', 'DBT-IAM-013', 'DBT-IAM-015', 'DBT-IAM-016', 'DBT-IAM-017', 'DBT-IAM-020', 'DBT-AUD-003', 'DBT-AUD-005', 'DBT-CORE-099', 'DBT-CORE-100', 'DBT-RES-001', 'DBT-RES-002', 'DBT-RES-003', 'DBT-RES-004', 'DBT-RES-005', 'DBT-RES-006', 'DBT-RES-007', 'DBT-RES-008', 'DBT-RES-009', 'DBT-RES-010', 'DBT-RES-011', 'DBT-RES-012', 'DBT-RES-013', 'DBT-RES-014', 'DBT-RES-015', 'DBT-RES-016', 'DBT-RES-017', 'DBT-RES-018', 'DBT-RES-046', 'DBT-RES-047', 'DBT-RES-048', 'DBT-RES-049', 'DBT-RES-050', 'DBT-CAL-002', 'DBT-CAL-003', 'DBT-CAL-004', 'DBT-CAL-011', 'DBT-CAL-012', 'DBT-CAL-013', 'DBT-CAL-014', 'DBT-CAL-016', 'DBT-CAL-017', 'DBT-TRN-015', 'DBT-TRN-017', 'DBT-TRN-018', 'DBT-CAL-018', 'DBT-CAL-019', 'DBT-CAL-020', 'DBT-CAL-021', 'DBT-CAL-022', 'DBT-CAL-024', 'DBT-CAL-025', 'DBT-CAL-051', 'DBT-CAL-052', 'DBT-CAL-053', 'DBT-CAL-054', 'DBT-CAL-055', 'DBT-TRN-003', 'DBT-IAM-032', 'DBT-CORE-021', 'DBT-TRN-006', 'DBT-TRN-007', 'DBT-TRN-008', 'DBT-CORE-023', 'DBT-TRN-013', 'DBT-TRN-035', 'DBT-TRN-037', 'DBT-TRN-038', 'DBT-FIN-001', 'DBT-FIN-005', 'DBT-LIC-001', 'DBT-LIC-002', 'DBT-LIC-003', 'DBT-LIC-004', 'DBT-LIC-051'], $catalog->implementedIds());
        $this->assertCount(413, $catalog->pendingIds());

        foreach ($entries as $entry) {
            $this->assertContains($entry['runtime_implementation_status'], [
                'pending_domain_materialization',
                'implemented_executable_assertion',
            ]);

            if ($entry['runtime_implementation_status'] === 'implemented_executable_assertion') {
                $this->assertIsArray($entry['runtime_implementation']);
                $this->assertNotEmpty($entry['runtime_implementation']['class']);
                $this->assertNotEmpty($entry['runtime_implementation']['method']);
            } else {
                $this->assertNull($entry['runtime_implementation']);
            }
        }
    }

    public function test_domain_migration_preflight_contracts_remain_pending_until_their_domain_ddl_exists(): void
    {
        $catalog = new Stage4TestCatalog;
        $preflights = array_values(array_filter(
            $catalog->entries(),
            static fn (array $entry): bool => $entry['test_class'] === 'migration_preflight',
        ));

        $this->assertCount(14, $preflights);
        foreach ($preflights as $entry) {
            $this->assertSame('pending_domain_materialization', $entry['runtime_implementation_status']);
            $this->assertNull($entry['runtime_implementation']);
        }
    }

    public function test_both_stage_four_migration_postchecks_are_executable(): void
    {
        $catalog = new Stage4TestCatalog;
        $postchecks = array_values(array_filter(
            $catalog->entries(),
            static fn (array $entry): bool => $entry['test_class'] === 'migration_postcheck',
        ));

        $this->assertCount(2, $postchecks);
        $this->assertSame(
            ['DBT-CORE-099', 'DBT-CORE-100'],
            array_column($postchecks, 'test_id'),
        );
        foreach ($postchecks as $entry) {
            $this->assertSame('implemented_executable_assertion', $entry['runtime_implementation_status']);
            $this->assertIsArray($entry['runtime_implementation']);
        }
    }
}
