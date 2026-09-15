<?php

return [
    'policy_version' => '2026-09-15-v2',
    'enabled' => env('OPS_ALERTING_ENABLED', false),
    'transport' => 'https_webhook',
    'webhook_url' => env('OPS_ALERT_WEBHOOK_URL'),
    'webhook_secret' => env('OPS_ALERT_WEBHOOK_SECRET'),
    'timeout_seconds' => (int) env('OPS_ALERT_TIMEOUT_SECONDS', 5),

    'events' => [
        'synthetic_smoke' => [
            'severity' => 'SEV3',
            'runbook' => 'deployment_or_destructive_operation',
            'summary' => 'Synthetic operational alert smoke test.',
            'context_keys' => [],
        ],
        'reconciliation_findings' => [
            'severity' => 'SEV2',
            'runbook' => 'payment_or_reconciliation',
            'summary' => 'Core reconciliation findings require operator review; synthetic smoke is explicitly marked in context.',
            'context_keys' => [
                'policy_version',
                'findings_total',
                'findings_by_scope',
                'synthetic_smoke',
            ],
        ],
    ],
];
