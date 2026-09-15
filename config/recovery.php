<?php

return [
    'policy_version' => '2026-09-13-v1',
    'go_live_requires_restore_drill' => true,
    'drill_interval_days' => 90,
    'business_targets_may_be_tightened_without_schema_change' => true,
    'target_relaxation_requires_authority_change_and_new_drill' => true,

    'tiers' => [
        'tier0_postgresql_business_authority' => [
            'rpo_minutes' => 5,
            'rto_minutes' => 60,
            'source_of_truth' => true,
            'required_capabilities' => [
                'encrypted_base_backup',
                'point_in_time_recovery',
                'off_primary_failure_domain_copy',
                'isolated_restore',
            ],
            'includes' => [
                'formal_training',
                'training_hour_ledger',
                'internal_exams',
                'license_inventory',
                'exam_inventory',
                'student_finance',
                'orders_payments',
                'audit_logs',
                'domain_events',
            ],
        ],
        'tier1_formal_object_assets' => [
            'rpo_minutes' => 60,
            'rto_minutes' => 240,
            'source_of_truth' => true,
            'required_capabilities' => [
                'object_versioning_or_equivalent',
                'encrypted_storage',
                'single_object_restore',
                'bulk_scope_restore',
            ],
        ],
        'tier2_rebuildable_projections' => [
            'rpo_minutes' => null,
            'rto_minutes' => 240,
            'source_of_truth' => false,
            'required_capabilities' => [
                'deterministic_rebuild_from_retained_authority',
            ],
            'includes' => [
                'organization_activity_events',
                'notifications',
                'derived_dashboard_projections',
            ],
        ],
        'ephemeral_redis' => [
            'rpo_minutes' => null,
            'rto_minutes' => 30,
            'source_of_truth' => false,
            'required_capabilities' => [
                'reprovision_empty',
                'no_business_authority_dependency',
            ],
        ],
        'application_release_artifact' => [
            'rpo_minutes' => 0,
            'rto_minutes' => 60,
            'source_of_truth' => false,
            'required_capabilities' => [
                'immutable_release_reference',
                'reproducible_deploy',
            ],
        ],
    ],

    'drill' => [
        'requires_isolated_environment' => true,
        'requires_database_restore' => true,
        'requires_formal_object_restore' => true,
        'requires_integrity_checks' => true,
        'requires_measured_rpo_rto' => true,
        'requires_report' => true,
        'requires_corrective_actions_on_failure' => true,
        'redis_restore_required' => false,
    ],
];
