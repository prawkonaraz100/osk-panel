<?php

return [
    'sensitive_identifiers' => [
        'lookup_key' => env('SENSITIVE_IDENTIFIER_LOOKUP_KEY'),
        'previous_lookup_keys' => array_values(array_filter(array_map(
            static fn (string $key): string => trim($key),
            explode(',', (string) env('SENSITIVE_IDENTIFIER_PREVIOUS_LOOKUP_KEYS', '')),
        ), static fn (string $key): bool => $key !== '')),
    ],
];
