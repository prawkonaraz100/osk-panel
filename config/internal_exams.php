<?php

return [
    'station_heartbeat_fresh_seconds' => env('INTERNAL_EXAM_STATION_HEARTBEAT_FRESH_SECONDS'),
    'execution_token_ttl_minutes' => env('INTERNAL_EXAM_EXECUTION_TOKEN_TTL_MINUTES'),
    'result_token_ttl_minutes' => env('INTERNAL_EXAM_RESULT_TOKEN_TTL_MINUTES'),
    'remote_access_ttl_minutes' => env('INTERNAL_EXAM_REMOTE_ACCESS_TTL_MINUTES'),
    'remote_public_base_url' => env('INTERNAL_EXAM_REMOTE_PUBLIC_BASE_URL'),
    'token_verifier_key_v1' => env('INTERNAL_EXAM_TOKEN_VERIFIER_KEY_V1'),
    'station_verifier_key_v1' => env('INTERNAL_EXAM_STATION_VERIFIER_KEY_V1'),
    'document_storage_disk' => env('INTERNAL_EXAM_DOCUMENT_STORAGE_DISK', 'local'),
];
