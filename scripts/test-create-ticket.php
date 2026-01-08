<?php

declare(strict_types=1);

/**
 * Script de teste para criar um chamado e identificar erros
 * Execute: php scripts/test-create-ticket.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/services.php';

try {
    // Simular usuário admin
    $user = find_user_by_email('admin@tdesk.local');
    
    if (!$user) {
        echo "❌ Usuário admin não encontrado. Execute o script de seed primeiro.\n";
        exit(1);
    }
    
    echo "✓ Usuário encontrado: {$user['name']} ({$user['email']})\n";
    echo "✓ Role: {$user['role']}\n\n";
    
    // Testar criação de chamado
    echo "Testando criação de chamado...\n";
    
    $ticketId = create_ticket_record($user, [
        'title' => 'Teste de Chamado',
        'category' => 'Suporte',
        'priority' => 'medium',
        'description' => 'Este é um chamado de teste para verificar se a criação está funcionando.',
        'attachments' => [],
    ]);
    
    echo "✅ Chamado criado com sucesso! ID: {$ticketId}\n";
    
    // Verificar se o chamado foi salvo
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM tickets WHERE id = :id');
    $stmt->execute(['id' => $ticketId]);
    $ticket = $stmt->fetch();
    
    if ($ticket) {
        echo "✅ Chamado encontrado no banco de dados:\n";
        echo "   - Título: {$ticket['title']}\n";
        echo "   - Categoria: {$ticket['category']}\n";
        echo "   - Prioridade: {$ticket['priority']}\n";
        echo "   - Status: {$ticket['status']}\n";
    } else {
        echo "❌ Chamado não encontrado no banco de dados!\n";
    }
    
} catch (Throwable $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    echo "   Arquivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "   Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

