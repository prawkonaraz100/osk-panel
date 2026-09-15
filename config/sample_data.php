<?php

$appEnv = (string) env('APP_ENV', 'production');
$rawEnabled = env('SAMPLE_DATA_ENABLED');
$enabled = $rawEnabled === null
    ? $appEnv === 'local'
    : filter_var($rawEnabled, FILTER_VALIDATE_BOOL);

if ($enabled && $appEnv === 'production') {
    throw new LogicException('SAMPLE_DATA_ENABLED is forbidden in production.');
}

return [
    'enabled' => $enabled,
    'terms' => [
        'document_type' => 'terms',
        'version' => 'sample-terms-v1',
        'document_url' => '/regulamin/sample-terms-v1',
        'view' => 'legal.sample-terms-v1',
        'published_at' => '2026-09-15 00:00:00+00',
        'effective_from' => '2026-09-15 00:00:00+00',
    ],
    'license_pricing' => [
        'SAMPLE-LICENSE-1M' => [
            'currency' => 'PLN',
            'list_unit_amount_minor' => 2900,
            'charged_unit_amount_minor' => 1450,
            'vat_rate_basis_points' => 2300,
            'display_name' => 'Przykładowa licencja 1 miesiąc',
            'pricing_revision' => 'sample-dev-2026-09-15-v1',
        ],
        'SAMPLE-LICENSE-3M' => [
            'currency' => 'PLN',
            'list_unit_amount_minor' => 3800,
            'charged_unit_amount_minor' => 1900,
            'vat_rate_basis_points' => 2300,
            'display_name' => 'Przykładowa licencja 3 miesiące',
            'pricing_revision' => 'sample-dev-2026-09-15-v1',
        ],
        'SAMPLE-LICENSE-6M' => [
            'currency' => 'PLN',
            'list_unit_amount_minor' => 5900,
            'charged_unit_amount_minor' => 2950,
            'vat_rate_basis_points' => 2300,
            'display_name' => 'Przykładowa licencja 6 miesięcy',
            'pricing_revision' => 'sample-dev-2026-09-15-v1',
        ],
    ],
];
