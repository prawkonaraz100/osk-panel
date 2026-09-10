<?php

use App\Modules\IdentityTenant\Models\User;

return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => null,
    ],
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],
    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => null,
            'expire' => null,
            'throttle' => null,
        ],
    ],
    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
