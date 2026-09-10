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
        $this->assertSame(['DBT-CORE-099', 'DBT-CORE-100'], $catalog->implementedIds());
        $this->assertCount(489, $catalog->pendingIds());

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
