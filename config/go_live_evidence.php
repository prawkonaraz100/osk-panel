<?php

return [
    'policy_version' => '2026-09-15-v2',
    'restore_drill_max_age_days' => 90,
    'future_clock_skew_seconds' => 300,

    'required_evidence' => [
        'target_production_configuration_preflight',
        'target_infrastructure_restore_drill',
        'production_backup_and_PITR_evidence',
        'production_object_versioning_and_restore_evidence',
        'production_secret_manager_or_equivalent_injection_evidence',
        'production_monitoring_dashboards_and_alert_routes',
        'incident_contact_roster_and_paging_smoke_test',
        'reconciliation_scheduler_execution_and_alert_delivery_smoke_test',
        'target_environment_release_smoke_test',
    ],
];
