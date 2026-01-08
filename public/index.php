<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/services.php';

if (current_user() !== null) {
    header('Location: dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($csrfToken)) {
        $error = 'Token de segurança inválido. Por favor, recarregue a página.';
    } else {
        // Rate limiting
        $rateLimitKey = 'login_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!check_rate_limit($rateLimitKey, 5, 300)) {
            $remaining = get_rate_limit_remaining($rateLimitKey, 5, 300);
            $error = 'Muitas tentativas de login. Tente novamente em alguns minutos.';
        } else {
            $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
            $password = $_POST['password'] ?? '';

            if (!$email || !$password) {
                $error = 'Informe e-mail e senha.';
            } else {
                $user = find_user_by_email($email);
                if ($user && password_verify($password, $user['password_hash'])) {
                    login_user($user);
                    set_flash('success', 'Bem-vindo de volta!');
                    header('Location: dashboard.php');
                    exit;
                }

                $error = 'Credenciais inválidas.';
            }
        }
    }
}

$flash = get_flash();
$appName = app_config()['app']['name'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $appName; ?> • Entrar</title>
    <link rel="icon" type="image/png" href="assets/imgs/logo-4.png">
    <link rel="shortcut icon" type="image/png" href="assets/imgs/logo-4.png">
    <link rel="apple-touch-icon" href="assets/imgs/logo-4.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
    <header class="global-header">
        <div class="global-header__brand">
            <img src="assets/imgs/logo-4.png" alt="Logo TDesk Solutions">
            <span>TDesk Solutions</span>
        </div>
    </header>
    <div class="auth-wrapper">
    <div class="auth-card">
        <div class="brand">
            <img src="assets/imgs/logo-4.png" alt="Logo TDesk Solutions">
            <div>
                <h1><?= $appName; ?></h1>
                <p>Central de gestão de chamados</p>
            </div>
        </div>
        <?php if ($flash): ?>
            <div class="alert alert-<?= sanitize($flash['type']); ?>">
                <?= sanitize($flash['message']); ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?= sanitize($error); ?>
            </div>
        <?php endif; ?>
        <form method="post" class="auth-form">
            <?= csrf_field(); ?>
            <label for="email">E-mail corporativo</label>
            <input type="email" id="email" name="email" required placeholder="voce@tdesk.com.br" value="<?= isset($_POST['email']) ? sanitize($_POST['email']) : ''; ?>">

            <label for="password">Senha</label>
            <input type="password" id="password" name="password" required placeholder="••••••••">

            <button type="submit">Entrar</button>
        </form>
        <p class="auth-helper">
            Esqueceu sua senha? <a href="reset.php">Recuperar acesso</a>
        </p>
    </div>
    </div>
</body>
</html>

