<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/services.php';

if (current_user() !== null) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($csrfToken)) {
        $errors[] = 'Token de segurança inválido. Por favor, recarregue a página.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm'] ?? '';

        if (!validate_name($name)) {
            $errors[] = 'Nome inválido. Use apenas letras e espaços (3-100 caracteres).';
        }

        if (!$email) {
            $errors[] = 'E-mail inválido.';
        }

        // Validação de senha mais forte
        $passwordErrors = validate_password_strength($password);
        if (!empty($passwordErrors)) {
            $errors = array_merge($errors, $passwordErrors);
        }

        if ($password !== $confirm) {
            $errors[] = 'As senhas não conferem.';
        }

        if (!$errors) {
            if (find_user_by_email($email)) {
                $errors[] = 'Já existe uma conta com este e-mail.';
            } else {
                create_user([
                    'name' => sanitize($name),
                    'email' => $email,
                    'password' => $password,
                    'role' => 'client',
                ]);

                set_flash('success', 'Conta criada! Faça login para abrir chamados.');
                header('Location: index.php');
                exit;
            }
        }
    }
}

$appName = app_config()['app']['name'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $appName; ?> • Registrar</title>
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
                <p>Cadastro de clientes</p>
            </div>
        </div>
        <?php if ($errors): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?= sanitize($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="post" class="auth-form">
            <?= csrf_field(); ?>
            <label for="name">Nome completo</label>
            <input type="text" id="name" name="name" required value="<?= isset($_POST['name']) ? sanitize($_POST['name']) : ''; ?>">

            <label for="email">E-mail corporativo</label>
            <input type="email" id="email" name="email" required value="<?= isset($_POST['email']) ? sanitize($_POST['email']) : ''; ?>">

            <div class="dual">
                <div>
                    <label for="password">Senha</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div>
                    <label for="confirm">Confirme a senha</label>
                    <input type="password" id="confirm" name="confirm" required>
                </div>
            </div>

            <button type="submit">Criar conta</button>
        </form>
        <p class="auth-helper">
            Já possui cadastro? <a href="index.php">Voltar para o login</a>
        </p>
    </div>
    </div>
</body>
</html>

