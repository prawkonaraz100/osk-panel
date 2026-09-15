<?php

return [
    'policy_version' => '2026-09-15-v1',

    'required_incident_contact_refs' => [
        'primary_on_call',
        'technical_lead',
        'privacy_lead',
        'business_liaison',
        'communications_lead',
        'paging_channel',
    ],

    'known_development_values' => [
        'database_password' => 'osk_panel_local_only',
        's3_access_key' => 'test',
        's3_secret_key' => 'test',
        's3_bucket' => 'osk-panel-test',
    ],

    'external_evidence_required' => [
        'secret_manager_or_equivalent_injection_attestation',
        'target_postgresql_PITR_and_off_primary_restore_drill',
        'target_object_version_restore_drill',
        'measured_target_RPO_RTO',
        'production_monitoring_dashboards_and_alert_routes',
        'incident_contact_roster_and_paging_smoke_test',
        'production_scheduler_and_alert_delivery_smoke_test',
        'external_https_release_smoke_test',
    ],
];
