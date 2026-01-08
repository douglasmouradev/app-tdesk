<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/services.php';

if (current_user() !== null) {
    header('Location: dashboard.php');
    exit;
}

$requestError = null;
$resetError = null;
$flash = get_flash();
$appName = app_config()['app']['name'];
$mode = 'request';
$selector = $_GET['selector'] ?? '';
$token = $_GET['token'] ?? '';
$resetRecord = null;

if ($selector && $token) {
    $resetRecord = validate_password_reset($selector, $token);
    if ($resetRecord) {
        $mode = 'reset';
    } else {
        $requestError = 'O link informado é inválido ou expirou. Solicite um novo e-mail.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($csrfToken)) {
        $requestError = 'Token de segurança inválido. Por favor, recarregue a página.';
    } else {
        $action = $_POST['action'] ?? 'request';

        if ($action === 'request') {
            // Rate limiting para reset de senha
            $rateLimitKey = 'reset_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            if (!check_rate_limit($rateLimitKey, 3, 600)) {
                $requestError = 'Muitas solicitações. Tente novamente em alguns minutos.';
            } else {
                $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
                if (!$email) {
                    $requestError = 'Informe um e-mail válido.';
                } else {
                    $user = find_user_by_email($email);
                    if ($user) {
                        $reset = create_password_reset($user);
                        send_password_reset_email($user, $reset['selector'], $reset['token']);
                    }

                    set_flash('success', 'Se o e-mail estiver cadastrado, enviaremos instruções em instantes.');
                    header('Location: reset.php');
                    exit;
                }
            }
        } elseif ($action === 'reset') {
            $selector = $_POST['selector'] ?? '';
            $token = $_POST['token'] ?? '';
            $password = $_POST['password'] ?? '';
            $confirmation = $_POST['password_confirmation'] ?? '';
            $mode = 'reset';

            // Validação de senha mais forte
            $passwordErrors = validate_password_strength($password);
            if (!empty($passwordErrors)) {
                $resetError = implode(' ', $passwordErrors);
            } elseif ($password !== $confirmation) {
                $resetError = 'As senhas informadas não conferem.';
            } else {
                $resetRecord = validate_password_reset($selector, $token);
                if (!$resetRecord) {
                    $mode = 'request';
                    $resetError = 'Link inválido ou expirado. Solicite um novo e-mail.';
                } else {
                    update_user_password((int)$resetRecord['user_id'], $password);
                    mark_password_reset_used((int)$resetRecord['id']);
                    set_flash('success', 'Senha atualizada! Faça login com a nova senha.');
                    header('Location: index.php');
                    exit;
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $appName; ?> • Recuperar acesso</title>
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
                    <p>Recuperação de acesso</p>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= sanitize($flash['type']); ?>">
                    <?= sanitize($flash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if ($requestError && $mode === 'request'): ?>
                <div class="alert alert-error"><?= sanitize($requestError); ?></div>
            <?php endif; ?>

            <?php if ($resetError && $mode === 'reset'): ?>
                <div class="alert alert-error"><?= sanitize($resetError); ?></div>
            <?php endif; ?>

            <?php if ($mode === 'request'): ?>
                <form method="post" class="auth-form">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="request">
                    <label for="email">Informe seu e-mail cadastrado</label>
                    <input type="email" id="email" name="email" required placeholder="voce@tdesk.com.br">
                    <button type="submit">Enviar instruções</button>
                </form>
            <?php else: ?>
                <form method="post" class="auth-form">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="reset">
                    <input type="hidden" name="selector" value="<?= sanitize($selector); ?>">
                    <input type="hidden" name="token" value="<?= sanitize($token); ?>">

                    <label for="password">Nova senha</label>
                    <input type="password" id="password" name="password" required minlength="8" placeholder="Nova senha segura">

                    <label for="password_confirmation">Confirmar senha</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required minlength="8" placeholder="Repita a nova senha">

                    <button type="submit">Atualizar senha</button>
                </form>
            <?php endif; ?>

            <p class="auth-helper">
                <a href="index.php">Voltar para o login</a>
            </p>
        </div>
    </div>
</body>
</html>

