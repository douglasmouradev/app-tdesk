<?php

declare(strict_types=1);

/**
 * Carrega e valida variáveis de ambiente do arquivo .env
 * 
 * @param string $key Chave da variável
 * @param mixed $default Valor padrão se não encontrado
 * @return mixed
 */
function env(string $key, $default = null)
{
    static $env = null;
    
    if ($env === null) {
        $envFile = __DIR__ . '/../.env';
        
        if (!file_exists($envFile)) {
            // Em produção, use variáveis de ambiente do sistema
            return getenv($key) !== false ? getenv($key) : $default;
        }
        
        $env = [];
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Ignora comentários
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Ignora linhas sem =
            if (strpos($line, '=') === false) {
                continue;
            }
            
            [$envKey, $envValue] = explode('=', $line, 2);
            $envKey = trim($envKey);
            $envValue = trim($envValue);
            
            // Remove aspas se existirem
            if ((substr($envValue, 0, 1) === '"' && substr($envValue, -1) === '"') ||
                (substr($envValue, 0, 1) === "'" && substr($envValue, -1) === "'")) {
                $envValue = substr($envValue, 1, -1);
            }
            
            $env[$envKey] = $envValue;
        }
    }
    
    return $env[$key] ?? (getenv($key) !== false ? getenv($key) : $default);
}

/**
 * Valida se todas as variáveis de ambiente obrigatórias estão definidas
 */
function validate_env(): void
{
    $required = [
        'DB_HOST',
        'DB_NAME',
        'DB_USERNAME',
    ];
    
    $missing = [];
    foreach ($required as $key) {
        if (empty(env($key))) {
            $missing[] = $key;
        }
    }
    
    if (!empty($missing)) {
        throw new RuntimeException(
            'Variáveis de ambiente obrigatórias não definidas: ' . implode(', ', $missing) . 
            '. Verifique o arquivo .env'
        );
    }
}

