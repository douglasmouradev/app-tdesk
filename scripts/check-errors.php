<?php

declare(strict_types=1);

/**
 * Script para verificar erros recentes nos logs
 * Execute: php scripts/check-errors.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/services.php';

echo "=== Diagnóstico do TDesk ===\n\n";

// Verificar conexão com banco
try {
    $pdo = db();
    echo "✅ Conexão com banco de dados: OK\n";
    
    // Verificar tabelas
    $tables = ['users', 'tickets', 'ticket_activity', 'ticket_attachments', 'ticket_responses', 'ticket_response_attachments'];
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
            $count = $stmt->fetchColumn();
            echo "✅ Tabela '{$table}': OK ({$count} registros)\n";
        } catch (PDOException $e) {
            echo "❌ Tabela '{$table}': ERRO - " . $e->getMessage() . "\n";
        }
    }
    
    // Verificar usuários
    $stmt = $pdo->query("SELECT id, name, email, role FROM users LIMIT 5");
    $users = $stmt->fetchAll();
    echo "\n📊 Usuários cadastrados: " . count($users) . "\n";
    foreach ($users as $user) {
        echo "   - {$user['name']} ({$user['email']}) - {$user['role']}\n";
    }
    
    // Verificar chamados
    $stmt = $pdo->query("SELECT id, title, status, priority FROM tickets ORDER BY id DESC LIMIT 5");
    $tickets = $stmt->fetchAll();
    echo "\n📊 Chamados cadastrados: " . count($tickets) . "\n";
    foreach ($tickets as $ticket) {
        echo "   - #{$ticket['id']}: {$ticket['title']} [{$ticket['status']}] - {$ticket['priority']}\n";
    }
    
    // Verificar permissões de diretório
    echo "\n📁 Verificando diretórios:\n";
    $uploadDir = __DIR__ . '/../public/uploads/attachments/';
    if (is_dir($uploadDir)) {
        echo "✅ Diretório de uploads existe\n";
        if (is_writable($uploadDir)) {
            echo "✅ Diretório de uploads é gravável\n";
        } else {
            echo "❌ Diretório de uploads NÃO é gravável\n";
        }
    } else {
        echo "❌ Diretório de uploads não existe\n";
    }
    
    // Verificar configuração
    echo "\n⚙️ Configuração:\n";
    $config = app_config();
    echo "   - APP_DEBUG: " . (env('APP_DEBUG', 'false') === 'true' ? 'ATIVADO' : 'DESATIVADO') . "\n";
    echo "   - DB_NAME: " . $config['database']['name'] . "\n";
    echo "   - DB_HOST: " . $config['database']['host'] . "\n";
    
} catch (Throwable $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    echo "   Arquivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

echo "\n✅ Diagnóstico concluído!\n";

