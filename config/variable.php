<?php

return [
    'cookie_secure' => env('COOKIE_SECURE', false),
    'cookie_samesite' => env('COOKIE_SAMESITE', env('COOKIE_SAMSITE', 'Lax')),
    'cookie_samsite' => env('COOKIE_SAMESITE', env('COOKIE_SAMSITE', 'Lax')), // backward compatibility
    'google_recaptcha_secret_key' => env('GOOGLE_RECAPTCHA_SECRET_KEY'),
];
