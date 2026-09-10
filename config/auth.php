<?php

return [
    /* S5-FOUND-001 owns the real IdentityTenant auth contract. */
    'defaults' => [
        'guard' => null,
        'passwords' => null,
    ],
    /* Explicit nulls neutralize Laravel's merged framework defaults. */
    'guards' => [
        'web' => [
            'driver' => null,
            'provider' => null,
        ],
    ],
    'providers' => [
        'users' => [
            'driver' => null,
            'model' => null,
        ],
    ],
    'passwords' => [
        'users' => [
            'provider' => null,
            'table' => null,
            'expire' => null,
            'throttle' => null,
        ],
    ],
    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
