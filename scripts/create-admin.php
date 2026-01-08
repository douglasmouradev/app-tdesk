<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/services.php';

$email = 'douglas@tdesksolutions.com.br';
$password = 'Titanium@10';
$name = 'Douglas';
$role = 'admin';

// Verificar se usuário já existe
$existing = find_user_by_email($email);
if ($existing !== null) {
    echo "⚠️  Usuário {$email} já existe!" . PHP_EOL;
    echo "   ID: {$existing['id']}" . PHP_EOL;
    echo "   Nome: {$existing['name']}" . PHP_EOL;
    echo "   Perfil: {$existing['role']}" . PHP_EOL;
    echo "" . PHP_EOL;
    echo "Deseja atualizar para admin? (s/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (trim(strtolower($line)) !== 's') {
        echo "Operação cancelada." . PHP_EOL;
        exit(0);
    }
    
    // Atualizar para admin
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET role = :role, name = :name WHERE id = :id');
    $stmt->execute([
        'role' => 'admin',
        'name' => $name,
        'id' => $existing['id'],
    ]);
    
    // Atualizar senha
    update_user_password((int)$existing['id'], $password);
    
    echo "✅ Usuário atualizado para admin com sucesso!" . PHP_EOL;
    echo "   Email: {$email}" . PHP_EOL;
    echo "   Senha: {$password}" . PHP_EOL;
    echo "   Perfil: admin" . PHP_EOL;
    exit(0);
}

// Criar novo usuário
try {
    $userId = create_user([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'role' => $role,
    ]);
    
    echo "✅ Usuário admin criado com sucesso!" . PHP_EOL;
    echo "" . PHP_EOL;
    echo "📋 Credenciais:" . PHP_EOL;
    echo "   Email: {$email}" . PHP_EOL;
    echo "   Senha: {$password}" . PHP_EOL;
    echo "   Perfil: {$role}" . PHP_EOL;
    echo "   ID: {$userId}" . PHP_EOL;
    echo "" . PHP_EOL;
    echo "🚀 Você pode fazer login agora!" . PHP_EOL;
} catch (Throwable $e) {
    echo "❌ Erro ao criar usuário: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

