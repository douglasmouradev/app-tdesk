<?php

declare(strict_types=1);

// Carregar variáveis de ambiente
require __DIR__ . '/env.php';

// Validar variáveis obrigatórias
try {
    validate_env();
} catch (RuntimeException $e) {
    if (env('APP_DEBUG', 'false') === 'true') {
        die('Erro de configuração: ' . $e->getMessage());
    }
    die('Erro de configuração. Contate o administrador.');
}

date_default_timezone_set(app_config()['app']['timezone']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(app_config()['security']['session_name']);
    
    // Configurações de segurança de sessão
    ini_set('session.cookie_httponly', '1');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Strict');
    
    session_start();
    
    // Regenerar ID de sessão periodicamente para prevenir session fixation
    if (!isset($_SESSION['created'])) {
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    } elseif (time() - $_SESSION['created'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config/config.php';
    }

    return $config;
}

function app_base_url(): string
{
    $config = app_config();
    if (!empty($config['app']['url'])) {
        return rtrim($config['app']['url'], '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['database']['host'],
        $config['database']['port'],
        $config['database']['name'],
        $config['database']['charset']
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, $config['database']['username'], $config['database']['password'], $options);

    return $pdo;
}

function sanitize(string $value): string
{
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_role(string $role): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === $role;
}

function authorize(array $roles): void
{
    $user = current_user();
    if ($user === null) {
        header('Location: index.php');
        exit;
    }

    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        echo 'Acesso negado.';
        exit;
    }
}

function require_auth(): void
{
    if (current_user() === null) {
        header('Location: index.php');
        exit;
    }
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function login_user(array $user): void
{
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

// Funções de segurança CSRF
function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

// Rate limiting
function check_rate_limit(string $key, int $maxAttempts = 5, int $windowSeconds = 300): bool
{
    $cacheKey = "rate_limit_{$key}";
    $attempts = $_SESSION[$cacheKey] ?? [];
    $now = time();
    
    // Remove tentativas antigas (fora da janela de tempo)
    $attempts = array_filter($attempts, fn($t) => $t > $now - $windowSeconds);
    
    if (count($attempts) >= $maxAttempts) {
        return false;
    }
    
    $attempts[] = $now;
    $_SESSION[$cacheKey] = array_values($attempts);
    return true;
}

function get_rate_limit_remaining(string $key, int $maxAttempts = 5, int $windowSeconds = 300): int
{
    $cacheKey = "rate_limit_{$key}";
    $attempts = $_SESSION[$cacheKey] ?? [];
    $now = time();
    $attempts = array_filter($attempts, fn($t) => $t > $now - $windowSeconds);
    return max(0, $maxAttempts - count($attempts));
}

// Headers de segurança HTTP
function set_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Content Security Policy (ajustar conforme necessário)
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com; " .
           "style-src 'self' 'unsafe-inline'; " .
           "img-src 'self' data:; " .
           "font-src 'self' data:; " .
           "connect-src 'self';";
    header("Content-Security-Policy: {$csp}");
}

// Chamar headers de segurança
set_security_headers();

