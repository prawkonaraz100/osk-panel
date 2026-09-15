<?php

return [
    'reset_url_template' => env('PASSWORD_RESET_URL_TEMPLATE')
        ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/reset-password?token={token}',
    'mailer' => env('PASSWORD_RESET_MAILER'),
];
