<?php

declare(strict_types=1);

// Carregar função env() se ainda não foi carregada
if (!function_exists('env')) {
    require __DIR__ . '/../src/env.php';
}

return [
    'app' => [
        'name' => env('APP_NAME', 'TDesk Solutions'),
        'timezone' => env('APP_TIMEZONE', 'America/Sao_Paulo'),
        'url' => env('APP_URL', 'http://localhost:8080'),
        'env' => env('APP_ENV', 'production'),
        'debug' => env('APP_DEBUG', 'false') === 'true',
    ],
    'database' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int)env('DB_PORT', '3306'),
        'name' => env('DB_NAME', 'tdesk_solutions'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
    ],
    'security' => [
        'session_name' => env('SESSION_NAME', 'tdesk_session'),
        'password_algo' => constant(env('PASSWORD_ALGO', 'PASSWORD_DEFAULT')),
        'app_key' => env('APP_KEY', ''),
        'csrf_token_expiry' => (int)env('CSRF_TOKEN_EXPIRY', '3600'),
    ],
    'mail' => [
        'from' => env('MAIL_FROM', 'no-reply@tdesk.local'),
        'host' => env('MAIL_HOST', ''),
        'port' => (int)env('MAIL_PORT', '587'),
        'username' => env('MAIL_USERNAME', ''),
        'password' => env('MAIL_PASSWORD', ''),
        'encryption' => env('MAIL_ENCRYPTION', 'tls'),
    ],
];

