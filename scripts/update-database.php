<?php

declare(strict_types=1);

/**
 * Script para atualizar o banco de dados com as novas tabelas
 * Execute: php scripts/update-database.php
 */

require __DIR__ . '/../src/env.php';
require __DIR__ . '/../config/config.php';

$config = require __DIR__ . '/../config/config.php';
$dbConfig = $config['database'];

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;charset=%s',
        $dbConfig['host'],
        $dbConfig['port'],
        $dbConfig['charset']
    );
    
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Ler o arquivo SQL
    $sqlFile = __DIR__ . '/../database/apptdesk.sql';
    if (!file_exists($sqlFile)) {
        throw new RuntimeException('Arquivo SQL não encontrado: ' . $sqlFile);
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Remover comentários e dividir em comandos
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    
    // Selecionar o banco de dados
    $pdo->exec("USE `{$dbConfig['name']}`");
    
    // Executar comandos SQL
    $commands = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($cmd) => !empty($cmd) && !preg_match('/^(SET|USE)/i', $cmd)
    );
    
    // Executar comandos que não são SET ou USE
    foreach ($commands as $command) {
        $command = trim($command);
        if (empty($command) || preg_match('/^(SET|USE)/i', $command)) {
            continue;
        }
        
        try {
            $pdo->exec($command);
            echo "✓ Comando executado com sucesso\n";
        } catch (PDOException $e) {
            // Ignorar erros de "table already exists" ou "database already exists"
            if (strpos($e->getMessage(), 'already exists') === false && 
                strpos($e->getMessage(), 'Duplicate') === false) {
                echo "⚠ Erro ao executar comando: " . $e->getMessage() . "\n";
                echo "   Comando: " . substr($command, 0, 100) . "...\n";
            } else {
                echo "ℹ " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n✅ Banco de dados atualizado com sucesso!\n";
    echo "   Tabelas criadas/atualizadas:\n";
    echo "   - ticket_responses\n";
    echo "   - ticket_response_attachments\n";
    
} catch (Throwable $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    echo "   Arquivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

