<?php

return [
    'disk' => env('UPLOAD_STORAGE_DISK', env('FILESYSTEM_DISK', 's3')),
    'ttl_minutes' => (int) env('UPLOAD_PRESIGN_TTL_MINUTES', 15),

    'purposes' => [
        'formal_training_signed_scan' => [
            'max_size_bytes' => 25 * 1024 * 1024,
            'mime_types' => ['application/pdf'],
        ],
        'staff_photo' => [
            'max_size_bytes' => 10 * 1024 * 1024,
            'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        ],
        'vehicle_photo' => [
            'max_size_bytes' => 10 * 1024 * 1024,
            'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        ],
        'vehicle_document' => [
            'max_size_bytes' => 25 * 1024 * 1024,
            'mime_types' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
        ],
    ],
];
