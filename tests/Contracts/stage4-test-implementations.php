<?php

use Tests\Feature\Stage4MigrationPostcheckTest;

return [
    'DBT-CORE-099' => [
        'class' => Stage4MigrationPostcheckTest::class,
        'method' => 'test_dbt_core_099_zero_gap_traceability_authority_is_executable',
        'scope' => 'Stage-4 zero-gap coverage summary and complete 491-ID catalog remain executable and unchanged.',
    ],
    'DBT-CORE-100' => [
        'class' => Stage4MigrationPostcheckTest::class,
        'method' => 'test_dbt_core_100_final_aggregate_sync_is_executable',
        'scope' => 'Final Stage-4 aggregate authority blobs and 491-test catalog remain synchronized.',
    ],
];
