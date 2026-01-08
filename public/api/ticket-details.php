<?php

declare(strict_types=1);

// Ajustar caminho relativo - public/api/ticket-details.php -> raiz do projeto
// Usar realpath para garantir caminho absoluto correto
$basePath = realpath(__DIR__ . '/../../');

if (!$basePath) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Erro de configuração: não foi possível determinar o caminho base do projeto.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$bootstrapFile = $basePath . '/src/bootstrap.php';
$servicesFile = $basePath . '/src/services.php';

if (!file_exists($bootstrapFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Erro de configuração: arquivo bootstrap.php não encontrado.',
        'debug' => 'Base path: ' . $basePath . ', Bootstrap: ' . $bootstrapFile
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require $bootstrapFile;
require $servicesFile;

require_auth();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$ticketId = (int)($_GET['id'] ?? 0);

if ($ticketId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do chamado inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Usuário não autenticado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $ticket = fetch_ticket_details($user, $ticketId);
    
    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['error' => 'Chamado não encontrado ou você não tem permissão para visualizá-lo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Verificar permissão de edição
    $canEdit = false;
    if ($user['role'] === 'admin') {
        $canEdit = true;
    } elseif ($user['role'] === 'support' && isset($ticket['assigned_to']) && $ticket['assigned_to'] == $user['id']) {
        $canEdit = true;
    } elseif (isset($ticket['user_id']) && $ticket['user_id'] == $user['id']) {
        $canEdit = true;
    }
    
    // Verificar permissão de exclusão
    $canDelete = false;
    if ($user['role'] === 'admin' || (isset($ticket['user_id']) && $ticket['user_id'] == $user['id'])) {
        $canDelete = true;
    }
    
    $ticket['can_edit'] = $canEdit;
    $ticket['can_delete'] = $canDelete;
    
    // Garantir que todos os campos necessários existem
    if (!isset($ticket['attachments'])) {
        $ticket['attachments'] = [];
    }
    if (!isset($ticket['activities'])) {
        $ticket['activities'] = [];
    }
    
    echo json_encode($ticket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    $errorMessage = $exception->getMessage();
    $errorDetails = sprintf('[TDesk Error] %s: %s in %s:%d', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine());
    error_log($errorDetails);
    
    // Sempre mostrar mensagem detalhada para debug
    $response = [
        'error' => $errorMessage,
        'debug' => $errorDetails,
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => explode("\n", $exception->getTraceAsString())
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

