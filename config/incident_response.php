<?php

return [
    'policy_version' => '2026-09-13-v1',
    'post_incident_review_required_for' => ['SEV1', 'SEV2'],
    'production_paging_smoke_test_required' => true,

    'owner_roles' => [
        'incident_commander',
        'technical_lead',
        'privacy_lead',
        'business_liaison',
        'communications_lead',
        'scribe',
    ],

    'contact_refs' => [
        'primary_on_call' => env('INCIDENT_PRIMARY_ON_CALL_REF'),
        'technical_lead' => env('INCIDENT_TECHNICAL_LEAD_REF'),
        'privacy_lead' => env('INCIDENT_PRIVACY_LEAD_REF'),
        'business_liaison' => env('INCIDENT_BUSINESS_LIAISON_REF'),
        'communications_lead' => env('INCIDENT_COMMUNICATIONS_LEAD_REF'),
        'paging_channel' => env('INCIDENT_PAGING_CHANNEL_REF'),
    ],

    'severities' => [
        'SEV1' => [
            'ack_minutes' => 10,
            'incident_commander_assigned_minutes' => 15,
            'status_update_minutes' => 30,
            'examples' => [
                'confirmed_or_suspected_active_security_compromise',
                'tier0_business_authority_unavailable_or_corrupt',
                'widespread_internal_exam_integrity_failure',
                'widespread_payment_state_integrity_failure',
                'destructive_operation_with_formal_or_financial_impact',
            ],
        ],
        'SEV2' => [
            'ack_minutes' => 30,
            'incident_commander_assigned_minutes' => 30,
            'status_update_minutes' => 60,
            'examples' => [
                'major_degradation_with_safe_workaround',
                'single_module_integrity_risk_without_confirmed_data_loss',
                'object_storage_or_queue_failure_affecting_multiple_tenants',
            ],
        ],
        'SEV3' => [
            'ack_minutes' => 240,
            'incident_commander_assigned_minutes' => 240,
            'status_update_minutes' => 240,
            'examples' => [
                'limited_noncritical_degradation',
                'single_tenant_operational_issue_without_integrity_risk',
            ],
        ],
    ],

    'personal_data_breach' => [
        'document_every_breach' => true,
        'processor_notifies_controller_without_undue_delay' => true,
        'controller_risk_assessment_required' => true,
        'supervisory_authority' => [
            'automatic_notification' => false,
            'condition' => 'likely_risk_to_rights_or_freedoms',
            'deadline_hours_from_awareness_when_required' => 72,
            'notify_without_undue_delay' => true,
            'late_notification_requires_delay_reasons' => true,
            'staged_information_allowed_when_not_all_information_available' => true,
        ],
        'data_subjects' => [
            'automatic_notification' => false,
            'condition' => 'likely_high_risk_to_rights_or_freedoms',
            'deadline' => 'without_undue_delay',
        ],
        'risk_inputs' => [
            'data_categories',
            'data_volume',
            'number_of_people',
            'identifiability',
            'confidentiality_integrity_availability_effect',
            'likelihood_of_misuse',
            'severity_of_possible_harm',
            'mitigations_already_effective',
        ],
    ],

    'runbooks' => [
        'database_or_data_integrity' => [
            'primary_owner' => 'technical_lead',
            'required_roles' => ['incident_commander', 'technical_lead', 'scribe'],
            'never' => [
                'blindly_rewrite_formal_business_facts',
                'blindly_rollback_destructive_migration',
                'restore_over_source_database',
            ],
        ],
        'object_storage' => [
            'primary_owner' => 'technical_lead',
            'required_roles' => ['incident_commander', 'technical_lead', 'scribe'],
            'never' => ['mass_delete_versions_during_investigation'],
        ],
        'redis_or_queue' => [
            'primary_owner' => 'technical_lead',
            'required_roles' => ['incident_commander', 'technical_lead', 'scribe'],
            'never' => ['treat_redis_as_business_authority', 'invent_success_for_lost_async_work'],
        ],
        'payment_or_reconciliation' => [
            'primary_owner' => 'technical_lead',
            'required_roles' => ['incident_commander', 'technical_lead', 'business_liaison', 'scribe'],
            'never' => ['mark_paid_from_browser_return', 'fabricate_provider_confirmation'],
        ],
        'internal_exam' => [
            'primary_owner' => 'business_liaison',
            'required_roles' => ['incident_commander', 'technical_lead', 'business_liaison', 'scribe'],
            'never' => ['invent_pass_result', 'consume_inventory_without_canonical_attempt'],
        ],
        'security_or_credentials' => [
            'primary_owner' => 'technical_lead',
            'required_roles' => ['incident_commander', 'technical_lead', 'privacy_lead', 'scribe'],
            'never' => ['destroy_security_evidence_before_scope_is_known'],
        ],
        'personal_data_breach' => [
            'primary_owner' => 'privacy_lead',
            'required_roles' => ['incident_commander', 'technical_lead', 'privacy_lead', 'communications_lead', 'scribe'],
            'never' => ['assume_every_breach_is_reportable', 'wait_for_perfect_information_before_starting_assessment'],
        ],
        'deployment_or_destructive_operation' => [
            'primary_owner' => 'technical_lead',
            'required_roles' => ['incident_commander', 'technical_lead', 'business_liaison', 'scribe'],
            'never' => ['apply_unreviewed_data_repair', 'erase_audit_to_hide_failed_operation'],
        ],
        'PKK_provider_deferred_boundary' => [
            'primary_owner' => 'business_liaison',
            'required_roles' => ['incident_commander', 'technical_lead', 'business_liaison', 'scribe'],
            'never' => ['invent_provider_state', 'enable_provider_specific_runtime_without_PWPW_authority'],
        ],
    ],
];
