<?php

return [
    'policy_version' => '2026-09-13-v1',

    'automatic_repair_allowed' => false,
    'remote_provider_truth_lookup_performed' => false,

    'schedule' => [
        'cadence_minutes' => 15,
        'fail_on_findings' => true,
        'without_overlapping_minutes' => 30,
    ],

    'scopes' => [
        'commerce_payment_settlement',
        'purchase_fulfillment',
        'license_inventory',
        'internal_exam_inventory',
        'outbox_publication',
    ],

    'PKK' => [
        'in_scope' => false,
        'status' => 'frozen_until_explicit_unfreeze',
        'provider_specific_reconciliation_allowed' => false,
    ],

    'repair_boundary' => [
        'scanner_may_mutate_business_state' => false,
        'manual_or_automated_repair_requires_separate_audited_path' => true,
        'may_infer_payment_confirmation_from_local_pending_state' => false,
        'may_mark_outbox_published_to_clear_backlog' => false,
        'may_fabricate_inventory_or_reservation_history' => false,
    ],
];
