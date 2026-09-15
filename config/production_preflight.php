<?php

return [
    'policy_version' => '2026-09-15-v1',

    'external_evidence_required' => [
        'target_infrastructure_restore_drill',
        'production_backup_and_PITR_evidence',
        'production_object_versioning_and_restore_evidence',
        'production_secret_manager_or_equivalent_injection_evidence',
        'production_monitoring_dashboards_and_alert_routes',
        'incident_contact_roster_and_paging_smoke_test',
        'reconciliation_scheduler_execution_and_alert_delivery_smoke_test',
        'target_environment_release_smoke_test',
    ],

    'deferred_boundaries' => [
        'PKK_PWPW' => 'FROZEN_UNTIL_EXPLICIT_UNFREEZE',
        'payment_provider_specific_webhook' => 'EXTERNAL_PROVIDER_BOUNDARY',
    ],
];
