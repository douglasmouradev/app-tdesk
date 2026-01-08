<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/services.php';

require_auth();

$user = current_user();
if (!$user || !isset($user['id']) || empty($user['id'])) {
    // Se o usuário não estiver autenticado corretamente, redirecionar para login
    set_flash('error', 'Sessão expirada. Faça login novamente.');
    header('Location: index.php');
    exit;
}
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($csrfToken)) {
        set_flash('error', 'Token de segurança inválido. Por favor, recarregue a página.');
        header('Location: dashboard.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_ticket') {
            $title = sanitize($_POST['title'] ?? '');
            $category = sanitize($_POST['category'] ?? '');
            $priority = sanitize($_POST['priority'] ?? '');
            $description = sanitize(trim($_POST['description'] ?? '')); // Sanitizar antes do banco

            // Log para debug (remover em produção)
            if (env('APP_DEBUG', 'false') === 'true') {
                error_log(sprintf('[TDesk Debug] create_ticket - title: %s, category: %s, priority: %s, description length: %d', 
                    $title, $category, $priority, strlen($description)));
            }

            if ($title === '' || $category === '' || $description === '') {
                throw new InvalidArgumentException('Preencha todos os campos do novo chamado.');
            }
            
            // Validar prioridade - se não fornecida, usar 'medium' como padrão
            if ($priority === '') {
                $priority = 'medium';
            }
            
            // Validar se a prioridade é válida
            if (!in_array($priority, ['low', 'medium', 'high'], true)) {
                throw new InvalidArgumentException('Prioridade inválida. Use: Baixa, Média ou Alta.');
            }

            // Processar anexos
            $attachments = [];
            if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'])) {
                // Verificar se é array múltiplo ou arquivo único
                if (is_array($_FILES['attachments']['name'])) {
                    $files = $_FILES['attachments'];
                    $fileCount = count($files['name']);
                    
                    for ($i = 0; $i < $fileCount; $i++) {
                        // Verificar se há um arquivo válido neste índice
                        if (!empty($files['name'][$i]) && $files['error'][$i] === UPLOAD_ERR_OK) {
                            $file = [
                                'name' => $files['name'][$i],
                                'type' => $files['type'][$i],
                                'tmp_name' => $files['tmp_name'][$i],
                                'error' => $files['error'][$i],
                                'size' => $files['size'][$i],
                            ];
                            
                            try {
                                $processed = validate_and_process_upload($file);
                                if ($processed !== null) {
                                    $attachments[] = $processed;
                                }
                            } catch (Exception $e) {
                                throw new InvalidArgumentException('Erro no arquivo "' . htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8') . '": ' . $e->getMessage());
                            }
                        } elseif (!empty($files['name'][$i]) && $files['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                            $errorMsg = 'Erro desconhecido no upload';
                            switch ($files['error'][$i]) {
                                case UPLOAD_ERR_INI_SIZE:
                                case UPLOAD_ERR_FORM_SIZE:
                                    $errorMsg = 'Arquivo muito grande';
                                    break;
                                case UPLOAD_ERR_PARTIAL:
                                    $errorMsg = 'Upload parcial do arquivo';
                                    break;
                                case UPLOAD_ERR_NO_TMP_DIR:
                                    $errorMsg = 'Diretório temporário não encontrado';
                                    break;
                                case UPLOAD_ERR_CANT_WRITE:
                                    $errorMsg = 'Erro ao escrever arquivo';
                                    break;
                                case UPLOAD_ERR_EXTENSION:
                                    $errorMsg = 'Upload bloqueado por extensão';
                                    break;
                            }
                            throw new InvalidArgumentException('Erro no upload do arquivo "' . htmlspecialchars($files['name'][$i], ENT_QUOTES, 'UTF-8') . '": ' . $errorMsg);
                        }
                    }
                } elseif (!empty($_FILES['attachments']['name']) && $_FILES['attachments']['error'] === UPLOAD_ERR_OK) {
                    // Arquivo único
                    try {
                        $processed = validate_and_process_upload($_FILES['attachments']);
                        if ($processed !== null) {
                            $attachments[] = $processed;
                        }
                    } catch (Exception $e) {
                        throw new InvalidArgumentException('Erro no arquivo "' . htmlspecialchars($_FILES['attachments']['name'], ENT_QUOTES, 'UTF-8') . '": ' . $e->getMessage());
                    }
                }
            }

            // Validar que o usuário está autenticado e tem ID válido
            if (!isset($user['id']) || empty($user['id'])) {
                throw new RuntimeException('Usuário não autenticado corretamente. Faça login novamente.');
            }

            create_ticket_record($user, [
                'title' => $title,
                'category' => $category,
                'priority' => $priority,
                'description' => $description,
                'attachments' => $attachments,
            ]);

            $attachmentMsg = count($attachments) > 0 ? ' com ' . count($attachments) . ' anexo(s)' : '';
            set_flash('success', 'Chamado aberto com sucesso!' . $attachmentMsg);
        } elseif ($action === 'update_status') {
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $status = sanitize($_POST['status'] ?? '');
            $note = sanitize($_POST['note'] ?? '');

            // A função update_ticket_status já verifica permissões (admin ou support)
            // Não precisamos verificar can_modify_ticket aqui, pois update_ticket_status
            // permite que admin e support alterem status de qualquer chamado
            update_ticket_status($user, $ticketId, $status, $note);
            set_flash('success', 'Status atualizado com sucesso!');
        } elseif ($action === 'assign_ticket') {
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $agentId = (int)($_POST['assignee'] ?? 0);
            assign_ticket_to_agent($user, $ticketId, $agentId);
            set_flash('success', 'Chamado atribuído com sucesso.');
        } elseif ($action === 'create_user_admin') {
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Apenas administradores podem criar contas.');
            }

            $name = trim($_POST['new_user_name'] ?? '');
            $email = filter_input(INPUT_POST, 'new_user_email', FILTER_VALIDATE_EMAIL);
            $password = $_POST['new_user_password'] ?? '';
            $role = sanitize($_POST['new_user_role'] ?? 'client');

            if (!validate_name($name)) {
                throw new InvalidArgumentException('Nome inválido. Use apenas letras e espaços (3-100 caracteres).');
            }

            if (!$email) {
                throw new InvalidArgumentException('E-mail inválido.');
            }

            // Validação de senha mais forte
            $passwordErrors = validate_password_strength($password);
            if (!empty($passwordErrors)) {
                throw new InvalidArgumentException(implode(' ', $passwordErrors));
            }

            if (!in_array($role, ['admin', 'support', 'client'], true)) {
                throw new InvalidArgumentException('Perfil inválido.');
            }

            if (find_user_by_email($email)) {
                throw new InvalidArgumentException('Já existe um usuário com este e-mail.');
            }

            create_user([
                'name' => sanitize($name),
                'email' => $email,
                'password' => $password,
                'role' => $role,
            ]);

            set_flash('success', 'Usuário criado com sucesso.');
        } elseif ($action === 'delete_user') {
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Apenas administradores podem excluir usuários.');
            }
            $userId = (int)($_POST['user_id'] ?? 0);
            delete_user($user, $userId);
            set_flash('success', 'Usuário excluído com sucesso.');
        } elseif ($action === 'update_user_password') {
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Apenas administradores podem alterar senhas.');
            }
            $userId = (int)($_POST['user_id'] ?? 0);
            $newPassword = $_POST['new_password'] ?? '';
            
            if (strlen($newPassword) < 8) {
                throw new InvalidArgumentException('A senha deve ter pelo menos 8 caracteres.');
            }
            
            update_user_password($userId, $newPassword);
            set_flash('success', 'Senha alterada com sucesso.');
        } elseif ($action === 'update_user_role') {
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Apenas administradores podem alterar níveis.');
            }
            $userId = (int)($_POST['user_id'] ?? 0);
            $newRole = sanitize($_POST['new_role'] ?? '');
            
            update_user_role($user, $userId, $newRole);
            set_flash('success', 'Nível do usuário alterado com sucesso.');
        } elseif ($action === 'edit_ticket') {
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $title = sanitize($_POST['title'] ?? '');
            $category = sanitize($_POST['category'] ?? '');
            $priority = sanitize($_POST['priority'] ?? '');
            $description = sanitize(trim($_POST['description'] ?? ''));

            if ($title === '' || $category === '' || $description === '') {
                throw new InvalidArgumentException('Preencha todos os campos obrigatórios.');
            }

            update_ticket($user, $ticketId, [
                'title' => $title,
                'category' => $category,
                'priority' => $priority,
                'description' => $description,
            ]);

            set_flash('success', 'Chamado atualizado com sucesso!');
        } elseif ($action === 'delete_ticket') {
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            delete_ticket($user, $ticketId);
            set_flash('success', 'Chamado excluído com sucesso.');
        } elseif ($action === 'add_response') {
            if (!in_array($user['role'], ['admin', 'support'], true)) {
                throw new RuntimeException('Apenas administradores e suporte podem adicionar respostas.');
            }
            
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $responseText = sanitize(trim($_POST['response'] ?? ''));
            
            // Log para debug
            if (env('APP_DEBUG', 'false') === 'true') {
                error_log(sprintf('[TDesk Debug] add_response - ticket_id: %d, response length: %d, user_id: %d, user_role: %s', 
                    $ticketId, strlen($responseText), $user['id'] ?? 0, $user['role'] ?? 'unknown'));
            }
            
            if ($responseText === '') {
                throw new InvalidArgumentException('Digite uma resposta.');
            }
            
            if ($ticketId <= 0) {
                throw new InvalidArgumentException('ID do chamado inválido.');
            }
            
            // Processar anexos da resposta
            $attachments = [];
            if (!empty($_FILES['response_images']['name'][0])) {
                $files = $_FILES['response_images'];
                $fileCount = count($files['name']);
                
                for ($i = 0; $i < $fileCount; $i++) {
                    if (!empty($files['name'][$i]) && $files['error'][$i] === UPLOAD_ERR_OK) {
                        $file = [
                            'name' => $files['name'][$i],
                            'type' => $files['type'][$i],
                            'tmp_name' => $files['tmp_name'][$i],
                            'error' => $files['error'][$i],
                            'size' => $files['size'][$i],
                        ];
                        
                        try {
                            $processed = validate_and_process_upload($file, 'responses');
                            if ($processed !== null) {
                                $attachments[] = $processed;
                            }
                        } catch (Exception $e) {
                            throw new InvalidArgumentException('Erro no arquivo "' . htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8') . '": ' . $e->getMessage());
                        }
                    }
                }
            }
            
            add_ticket_response($user, $ticketId, $responseText, $attachments);
            set_flash('success', 'Resposta adicionada com sucesso!');
        }
    } catch (InvalidArgumentException $exception) {
        // Erros de validação: mostrar mensagem específica ao usuário
        error_log(sprintf('[TDesk Validation Error] %s: %s in %s:%d', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));
        set_flash('error', $exception->getMessage());
    } catch (RuntimeException $exception) {
        // Erros de runtime: mostrar mensagem mais específica
        error_log(sprintf('[TDesk Runtime Error] %s: %s in %s:%d', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));
        $errorMessage = $exception->getMessage();
        if (env('APP_DEBUG', 'false') === 'true') {
            $errorMessage .= ' (Arquivo: ' . basename($exception->getFile()) . ':' . $exception->getLine() . ')';
        }
        set_flash('error', $errorMessage);
    } catch (Throwable $exception) {
        // Logar erro completo
        $errorDetails = sprintf('[TDesk Error] %s: %s in %s:%d', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine());
        error_log($errorDetails);
        
        // Mostrar mensagem mais específica
        $errorMessage = 'Ocorreu um erro ao processar sua solicitação.';
        $debugMode = env('APP_DEBUG', 'false') === 'true';
        
        // Sempre mostrar a mensagem da exceção se for uma mensagem útil
        if ($exception->getMessage() && !empty(trim($exception->getMessage()))) {
            $errorMessage = $exception->getMessage();
        }
        
        if ($debugMode) {
            $errorMessage .= ' (Arquivo: ' . basename($exception->getFile()) . ':' . $exception->getLine() . ')';
        }
        
        set_flash('error', $errorMessage);
    }

    header('Location: dashboard.php');
    exit;
}

$flash = get_flash();
$stats = fetch_ticket_stats($user);
$tickets = fetch_ticket_list($user);
$operationalTickets = fetch_operational_board($user);
$closedTickets = fetch_closed_tickets($user);
$activities = fetch_recent_activity($user);
$agents = fetch_support_agents();
$allUsers = [];
$userFilterRole = $_GET['filter_role'] ?? '';
$userSearchTerm = sanitize($_GET['search_user'] ?? '');
if (in_array($user['role'], ['admin', 'support'], true)) {
    $allUsers = fetch_all_users($userFilterRole, $userSearchTerm);
}

$statusLabels = [
    'open' => 'Aberto',
    'in_progress' => 'Em andamento',
    'resolved' => 'Resolvido',
    'closed' => 'Fechado',
];

$priorityLabels = [
    'low' => 'Baixa',
    'medium' => 'Média',
    'high' => 'Alta',
];

$priorityDistribution = fetch_priority_distribution($user);
$priorityCounts = ['low' => 0, 'medium' => 0, 'high' => 0];
if (!empty($priorityDistribution['slugs'])) {
    foreach ($priorityDistribution['slugs'] as $index => $slug) {
        if (isset($priorityCounts[$slug])) {
            $priorityCounts[$slug] = (int)($priorityDistribution['data'][$index] ?? 0);
        }
    }
}

$statusDistribution = fetch_status_distribution($user);
$appName = app_config()['app']['name'];
$isAdmin = $user['role'] === 'admin';
$section = $_GET['page'] ?? 'dashboard';
$allowedSections = ['dashboard', 'chamados', 'fechados', 'usuarios', 'relatorios'];
$canAccessUsers = in_array($user['role'], ['admin', 'support'], true);
if (!in_array($section, $allowedSections, true) || ($section === 'usuarios' && !$canAccessUsers)) {
    $section = 'dashboard';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $appName; ?> • Painel</title>
    <link rel="icon" type="image/png" href="assets/imgs/logo-4.png">
    <link rel="shortcut icon" type="image/png" href="assets/imgs/logo-4.png">
    <link rel="apple-touch-icon" href="assets/imgs/logo-4.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" defer></script>
    <!-- Fallback local para exportação (evita bloqueio de CDN) -->
    <script src="assets/js/vendor/jspdf.umd.min.js" defer></script>
    <script src="assets/js/vendor/html2canvas.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" integrity="sha512-NaWAnbcRiSy57wiVdc+GqOR/mdrIW6DCe4k74vNysGEKMluSleqrs9jwELyhl725LLJoPLD114F8CbnMD4Pl4g==" crossorigin="anonymous" referrerpolicy="no-referrer" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha512-BNa5lHppZArYrusS4x+h0/pk3jfbQ3VIAtF+wNCz7L+G2kZZgwyUr0YJIUKGwEuITdSb9VjA36TObgGGeO3nkw==" crossorigin="anonymous" referrerpolicy="no-referrer" defer></script>
    <script src="assets/js/dashboard.js" type="module" defer></script>
</head>
<body class="dashboard-shell">
    <aside class="app-sidebar" id="appSidebar">
        <div class="app-sidebar__brand">
            <img src="assets/imgs/logo-4.png" alt="Logo TDesk Solutions">
            <div>
                <strong><?= $appName; ?></strong>
                <small>Central de operações</small>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php?page=dashboard" class="sidebar-link<?= $section === 'dashboard' ? ' is-active' : ''; ?>">
                <span class="sidebar-icon">📊</span>
                <div>
                    <strong>Painel Operacional</strong>
                    <small>Dashboard com métricas</small>
                </div>
            </a>
            <a href="dashboard.php?page=chamados" class="sidebar-link<?= $section === 'chamados' ? ' is-active' : ''; ?>">
                <span class="sidebar-icon">📁</span>
                <div>
                    <strong>Chamados</strong>
                    <small>Gerenciar chamados</small>
                </div>
            </a>
            <a href="dashboard.php?page=fechados" class="sidebar-link<?= $section === 'fechados' ? ' is-active' : ''; ?>">
                <span class="sidebar-icon">🕓</span>
                <div>
                    <strong>Chamados Fechados</strong>
                    <small>Histórico de chamados</small>
                </div>
            </a>
            <?php if ($canAccessUsers): ?>
                <a href="dashboard.php?page=usuarios" class="sidebar-link<?= $section === 'usuarios' ? ' is-active' : ''; ?>">
                    <span class="sidebar-icon">👤</span>
                    <div>
                        <strong>Usuários</strong>
                        <small>Gerenciar usuários</small>
                    </div>
                </a>
            <?php endif; ?>
            <a href="dashboard.php?page=relatorios" class="sidebar-link<?= $section === 'relatorios' ? ' is-active' : ''; ?>">
                <span class="sidebar-icon">📄</span>
                <div>
                    <strong>Relatórios</strong>
                    <small>Exportar relatórios</small>
                </div>
            </a>
        </nav>
    </aside>
    <div class="app-content">
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <header class="topbar">
            <div class="topbar-left">
                <button class="sidebar-trigger" id="sidebarToggle" type="button" aria-label="Abrir menu lateral" aria-expanded="false">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="brand">
                    <img src="assets/imgs/logo-4.png" alt="Logo TDesk Solutions">
                    <div>
                        <h1><?= $appName; ?></h1>
                        <p>Gestão inteligente de chamados</p>
                    </div>
                </div>
            </div>
            <div class="user-meta">
                <span><?= sanitize($user['name']); ?> · <?= strtoupper($user['role']); ?></span>
                <a class="link" href="logout.php">Sair</a>
            </div>
            <div class="report-menu" id="reportMenu" data-table-target="#operationalTable">
                <p>Exportar chamados</p>
                <button type="button" data-report="excel">Exportar Excel</button>
                <button type="button" data-report="pdf">Exportar PDF</button>
                <hr>
                <p>Exportar chamados fechados</p>
                <button type="button" data-report="excel-closed">Excel (fechados)</button>
                <button type="button" data-report="pdf-closed">PDF (fechados)</button>
            </div>
        </header>
        <main class="page">
            <?php if ($flash): ?>
                <div class="alert alert-<?= sanitize($flash['type']); ?>">
                    <?= sanitize($flash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if ($section === 'dashboard'): ?>
                <section class="cards">
                    <article class="card">
                        <p>Total de chamados</p>
                        <strong><?= $stats['total']; ?></strong>
                    </article>
                    <article class="card">
                        <p>Abertos</p>
                        <strong><?= $stats['open']; ?></strong>
                    </article>
                    <article class="card">
                        <p>Em andamento</p>
                        <strong><?= $stats['in_progress']; ?></strong>
                    </article>
                    <article class="card">
                        <p>Finalizados</p>
                        <strong><?= $stats['completed']; ?></strong>
                    </article>
                    <article class="card warning">
                        <p>Alta prioridade</p>
                        <strong><?= $stats['alerts']; ?></strong>
                    </article>
                </section>

                <section class="cta-bar">
                    <div>
                        <h2>Painel operacional</h2>
                        <p>Acompanhe, abra e distribua chamados em um só lugar.</p>
                    </div>
                    <button type="button" class="btn-primary" id="openTicketButton">
                        + Abrir chamado
                    </button>
                </section>

                <section
                    id="dashboardInsights"
                    class="panel panel--charts"
                    data-status-open="<?= $statusDistribution['open'] ?? 0; ?>"
                    data-status-in-progress="<?= $statusDistribution['in_progress'] ?? 0; ?>"
                    data-status-resolved="<?= $statusDistribution['resolved'] ?? 0; ?>"
                    data-status-closed="<?= $statusDistribution['closed'] ?? 0; ?>"
                    data-priority-low="<?= $priorityCounts['low']; ?>"
                    data-priority-medium="<?= $priorityCounts['medium']; ?>"
                    data-priority-high="<?= $priorityCounts['high']; ?>"
                >
                    <div class="panel-header">
                        <div>
                            <h2>Painel de gráficos interativos</h2>
                            <p>Clique nos segmentos para filtrar a Central operacional em tempo real.</p>
                        </div>
                    </div>
                    <div class="charts-grid">
                        <article class="chart-card">
                            <h3>Status dos chamados</h3>
                            <p>Visualize como seus chamados estão distribuídos entre as etapas.</p>
                            <canvas id="statusPieChart" aria-label="Gráfico de status dos chamados"></canvas>
                            <div class="chart-legend" id="statusLegend"></div>
                            <p class="chart-note" id="statusFilterHint">Clique em um segmento para filtrar por status.</p>
                        </article>
                        <article class="chart-card">
                            <h3>Prioridade dos chamados</h3>
                            <p>Compare rapidamente urgências baixas, médias e altas.</p>
                            <canvas id="priorityPieChart" aria-label="Gráfico de prioridade dos chamados"></canvas>
                            <div class="chart-legend" id="priorityLegend"></div>
                            <p class="chart-note" id="priorityFilterHint">Clique em um segmento para filtrar por prioridade.</p>
                        </article>
                    </div>
                </section>

                <details class="panel collapsible-panel" id="activity-panel">
                    <summary>
                        <div>
                            <h2>Atividades recentes</h2>
                            <p>Histórico das últimas movimentações</p>
                        </div>
                        <span class="summary-indicator" aria-hidden="true"></span>
                    </summary>
                    <div class="collapsible-body">
                        <ul class="activity">
                            <?php if (!$activities): ?>
                                <li>Nenhuma atividade registrada ainda.</li>
                            <?php endif; ?>
                            <?php foreach ($activities as $activity): ?>
                                <li>
                                    <div>
                                        <strong>#<?= $activity['id']; ?></strong>
                                        <span><?= sanitize($activity['title']); ?></span>
                                        <small><?= sanitize($activity['action']); ?> · <?= $activity['actor_name'] ? sanitize($activity['actor_name']) : 'Sistema'; ?></small>
                                    </div>
                                    <span><?= date('d/m H:i', strtotime($activity['created_at'])); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </details>

                <details class="panel split-panel collapsible-panel" id="quick-actions">
                    <summary>
                        <div>
                            <h2>Fluxo rápido</h2>
                            <p>Ações disponíveis de acordo com o seu perfil</p>
                        </div>
                        <span class="summary-indicator" aria-hidden="true"></span>
                    </summary>
                    <div class="collapsible-body">
                        <div class="quick-grid">
                            <?php if (in_array($user['role'], ['client', 'admin', 'support'], true)): ?>
                                <form method="post" class="quick-form" enctype="multipart/form-data">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="create_ticket">
                                    <label for="title">Título</label>
                                    <input type="text" id="title" name="title" required>

                                    <label for="category">Categoria</label>
                                    <input type="text" id="category" name="category" required>

                                    <label for="priority">Prioridade</label>
                                    <select id="priority" name="priority">
                                        <option value="low">Baixa</option>
                                        <option value="medium" selected>Média</option>
                                        <option value="high">Alta</option>
                                    </select>

                                    <label for="description">Descrição</label>
                                    <textarea id="description" name="description" rows="4" required></textarea>

                                    <label for="attachments">Anexos (opcional)</label>
                                    <input type="file" id="attachments" name="attachments[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.jpg,.jpeg,.png,.gif,.zip,.rar">
                                    <small style="display: block; margin-top: 0.25rem; color: #666; font-size: 0.875rem;">
                                        Formatos permitidos: PDF, DOC, DOCX, XLS, XLSX, TXT, JPG, PNG, GIF, ZIP, RAR (máx. 10MB por arquivo)
                                    </small>

                                    <button type="submit">Abrir chamado</button>
                                </form>
                            <?php endif; ?>

                            <div class="quick-actions">
                                <?php if (in_array($user['role'], ['admin', 'support'], true)): ?>
                                    <form method="post" class="quick-form">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_status">
                                        <label for="ticket_id">Chamado</label>
                                        <select id="ticket_id" name="ticket_id" required>
                                            <?php foreach ($tickets as $ticket): ?>
                                                <option value="<?= $ticket['id']; ?>">#<?= $ticket['id']; ?> - <?= sanitize($ticket['title']); ?></option>
                                            <?php endforeach; ?>
                                        </select>

                                        <label for="status">Novo status</label>
                                        <select id="status" name="status">
                                            <?php foreach ($statusLabels as $key => $label): ?>
                                                <option value="<?= $key; ?>"><?= $label; ?></option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="submit">Atualizar status</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($user['role'] === 'admin'): ?>
                                    <form method="post" class="quick-form">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="assign_ticket">
                                        <label for="ticket_assign">Chamado</label>
                                        <select id="ticket_assign" name="ticket_id" required>
                                            <?php foreach ($tickets as $ticket): ?>
                                                <option value="<?= $ticket['id']; ?>">#<?= $ticket['id']; ?> - <?= sanitize($ticket['title']); ?></option>
                                            <?php endforeach; ?>
                                        </select>

                                        <label for="assignee">Responsável</label>
                                        <select id="assignee" name="assignee" required>
                                            <?php foreach ($agents as $agent): ?>
                                                <option value="<?= $agent['id']; ?>"><?= sanitize($agent['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="submit">Atribuir</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </details>

            <?php elseif ($section === 'chamados'): ?>
                <section class="panel board-panel">
                    <div class="panel-header">
                        <h2>Central operacional</h2>
                        <p>Gerencie e exporte a visão consolidada de chamados.</p>
                    </div>
                    
                    <section class="filters-section" style="margin-bottom: 2rem; padding: 1.5rem; background: #fff; border-radius: 12px; border: 1px solid var(--border);">
                        <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem; font-weight: 600;">Gerenciamento de Chamados</h3>
                        <p style="margin: 0 0 1.5rem 0; color: #666; font-size: 0.9rem;">Filtros rápidos para status, prioridade e solicitante</p>
                        
                        <div class="filters-row" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                            <div class="filter-group" style="flex: 1; min-width: 150px;">
                                <label for="filterId" style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.9rem;">Buscar por ID</label>
                        <input 
                            type="text" 
                                    id="filterId" 
                                    placeholder="ID"
                                    style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem;"
                        >
                    </div>
                            
                            <div class="filter-group" style="flex: 1; min-width: 150px;">
                                <label for="filterStatus" style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.9rem;">Status</label>
                                <select 
                                    id="filterStatus"
                                    style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem; background: #fff;"
                                >
                                    <option value="">Todos</option>
                                    <option value="open">Aberto</option>
                                    <option value="in_progress">Em andamento</option>
                                    <option value="resolved">Resolvido</option>
                                    <option value="closed">Fechado</option>
                                </select>
                            </div>
                            
                            <div class="filter-group" style="flex: 1; min-width: 150px;">
                                <label for="filterPriority" style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.9rem;">Prioridade</label>
                                <select 
                                    id="filterPriority"
                                    style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem; background: #fff;"
                                >
                                    <option value="">Todas</option>
                                    <option value="low">Baixa</option>
                                    <option value="medium">Média</option>
                                    <option value="high">Alta</option>
                                </select>
                            </div>
                            
                            <div class="filter-group" style="flex: 1; min-width: 150px;">
                                <label for="filterUser" style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.9rem;">Usuário</label>
                                <input 
                                    type="text" 
                                    id="filterUser" 
                                    placeholder="ID ou Nome"
                                    style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem;"
                                >
                            </div>
                            
                            <div class="filter-group" style="flex-shrink: 0;">
                                <button 
                                    type="button" 
                                    id="applyFilters"
                                    class="btn-primary"
                                    style="padding: 0.75rem 2rem; background: var(--primary); color: #fff; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; font-size: 1rem;"
                                >
                                    Aplicar
                                </button>
                            </div>
                        </div>
                    </section>
                    
                    <div class="table-wrapper">
                        <table id="operationalTable">
                            <thead>
                                <tr>
                                    <th>CHAMADO</th>
                                    <th>Título</th>
                                    <th>Solicitante</th>
                                    <th>Responsável</th>
                                    <th>Categoria</th>
                                    <th>Prioridade</th>
                                    <th>Status</th>
                                    <th>Atualizado</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$operationalTickets): ?>
                                    <tr>
                                        <td colspan="9">Nenhum registro disponível.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($operationalTickets as $ticket): ?>
                                    <tr
                                        data-priority="<?= sanitize($ticket['priority']); ?>"
                                        data-status="<?= sanitize($ticket['status']); ?>"
                                    >
                                        <td><?= $ticket['id']; ?></td>
                                        <td><?= sanitize($ticket['title']); ?></td>
                                        <td><?= sanitize($ticket['requester']); ?></td>
                                        <td><?= $ticket['assignee'] ? sanitize($ticket['assignee']) : '—'; ?></td>
                                        <td><?= sanitize($ticket['category']); ?></td>
                                        <td><span class="tag priority-<?= sanitize($ticket['priority']); ?>"><?= $priorityLabels[$ticket['priority']] ?? strtoupper($ticket['priority']); ?></span></td>
                                        <td><span class="tag status-<?= sanitize($ticket['status']); ?>"><?= $statusLabels[$ticket['status']] ?? strtoupper($ticket['status']); ?></span></td>
                                        <td><?= date('d/m/Y H:i', strtotime($ticket['updated_at'])); ?></td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                <button type="button" class="btn-view" data-ticket-id="<?= $ticket['id']; ?>" onclick="openTicketModal(<?= $ticket['id']; ?>)">
                                                    VER
                                                </button>
                                                <?php 
                                                // Verificar se o usuário pode excluir (admin sempre pode, client pode excluir seus próprios chamados)
                                                $canDelete = ($user['role'] === 'admin' || ($user['role'] === 'client' && isset($ticket['user_id']) && $ticket['user_id'] == $user['id']));
                                                if ($canDelete): 
                                                ?>
                                                    <button type="button" class="btn-danger" onclick="deleteTicket(<?= $ticket['id']; ?>)" style="padding: 0.5rem 1rem; background: #ef4444; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 0.875rem; font-weight: 500;" title="Excluir chamado">
                                                        Excluir
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <details class="panel split-panel collapsible-panel" id="quick-actions">
                    <summary>
                        <div>
                            <h2>Fluxo rápido</h2>
                            <p>Ações disponíveis de acordo com o seu perfil</p>
                        </div>
                        <span class="summary-indicator" aria-hidden="true"></span>
                    </summary>
                    <div class="collapsible-body">
                        <div class="quick-grid">
                            <?php if (in_array($user['role'], ['client', 'admin', 'support'], true)): ?>
                                <form method="post" class="quick-form" enctype="multipart/form-data">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="create_ticket">
                                    <label for="title2">Título</label>
                                    <input type="text" id="title2" name="title" required>

                                    <label for="category2">Categoria</label>
                                    <input type="text" id="category2" name="category" required>

                                    <label for="priority2">Prioridade</label>
                                    <select id="priority2" name="priority">
                                        <option value="low">Baixa</option>
                                        <option value="medium" selected>Média</option>
                                        <option value="high">Alta</option>
                                    </select>

                                    <label for="description2">Descrição</label>
                                    <textarea id="description2" name="description" rows="4" required></textarea>

                                    <label for="attachments2">Anexos (opcional)</label>
                                    <input type="file" id="attachments2" name="attachments[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.jpg,.jpeg,.png,.gif,.zip,.rar">
                                    <small style="display: block; margin-top: 0.25rem; color: #666; font-size: 0.875rem;">
                                        Formatos permitidos: PDF, DOC, DOCX, XLS, XLSX, TXT, JPG, PNG, GIF, ZIP, RAR (máx. 10MB por arquivo)
                                    </small>

                                    <button type="submit">Abrir chamado</button>
                                </form>
                            <?php endif; ?>

                            <div class="quick-actions">
                                <?php if (in_array($user['role'], ['admin', 'support'], true)): ?>
                                    <form method="post" class="quick-form">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_status">
                                        <label for="ticket_id">Chamado</label>
                                        <select id="ticket_id" name="ticket_id" required>
                                            <?php foreach ($tickets as $ticket): ?>
                                                <option value="<?= $ticket['id']; ?>">#<?= $ticket['id']; ?> - <?= sanitize($ticket['title']); ?></option>
                                            <?php endforeach; ?>
                                        </select>

                                        <label for="status">Novo status</label>
                                        <select id="status" name="status">
                                            <?php foreach ($statusLabels as $key => $label): ?>
                                                <option value="<?= $key; ?>"><?= $label; ?></option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="submit">Atualizar status</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($user['role'] === 'admin'): ?>
                                    <form method="post" class="quick-form">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="assign_ticket">
                                        <label for="ticket_assign">Chamado</label>
                                        <select id="ticket_assign" name="ticket_id" required>
                                            <?php foreach ($tickets as $ticket): ?>
                                                <option value="<?= $ticket['id']; ?>">#<?= $ticket['id']; ?> - <?= sanitize($ticket['title']); ?></option>
                                            <?php endforeach; ?>
                                        </select>

                                        <label for="assignee">Responsável</label>
                                        <select id="assignee" name="assignee" required>
                                            <?php foreach ($agents as $agent): ?>
                                                <option value="<?= $agent['id']; ?>"><?= sanitize($agent['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="submit">Atribuir</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </details>

            <?php elseif ($section === 'fechados'): ?>
                <section class="panel board-panel" id="closedSection">
                    <div class="panel-header">
                        <h2>Chamados fechados</h2>
                        <p>Histórico completo de chamados finalizados recentemente.</p>
                    </div>
                    <div class="table-wrapper">
                        <table id="closedTable">
                            <thead>
                                <tr>
                                    <th>CHAMADO</th>
                                    <th>Título</th>
                                    <th>Solicitante</th>
                                    <th>Responsável</th>
                                    <th>Prioridade</th>
                                    <th>Status</th>
                                    <th>Fechado em</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$closedTickets): ?>
                                    <tr>
                                        <td colspan="7">Nada fechado nos últimos registros.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($closedTickets as $ticket): ?>
                                    <tr>
                                        <td><?= $ticket['id']; ?></td>
                                        <td><?= sanitize($ticket['title']); ?></td>
                                        <td><?= sanitize($ticket['requester']); ?></td>
                                        <td><?= $ticket['assignee'] ? sanitize($ticket['assignee']) : '—'; ?></td>
                                        <td><span class="tag priority-<?= sanitize($ticket['priority']); ?>"><?= $priorityLabels[$ticket['priority']] ?? strtoupper($ticket['priority']); ?></span></td>
                                        <td><span class="tag status-<?= sanitize($ticket['status']); ?>"><?= $statusLabels[$ticket['status']] ?? strtoupper($ticket['status']); ?></span></td>
                                        <td><?= date('d/m/Y H:i', strtotime($ticket['updated_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php elseif ($section === 'usuarios' && $canAccessUsers): ?>
                <!-- Lista de Usuários -->
                <section class="panel board-panel" id="usersListSection">
                    <div class="panel-header">
                        <h2>Usuários Cadastrados</h2>
                        <p>Lista de todos os usuários do sistema</p>
                    </div>
                    
                    <!-- Filtros -->
                    <form method="get" class="filter-form" style="margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                        <input type="hidden" name="page" value="usuarios">
                        <div style="flex: 1; min-width: 200px;">
                            <label for="search_user" style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #1a1a1a;">Buscar</label>
                            <input type="text" id="search_user" name="search_user" placeholder="Nome ou e-mail..." value="<?= htmlspecialchars($userSearchTerm); ?>" style="width: 100%; padding: 0.6rem; border: 1px solid #d1d5db; border-radius: 8px;">
                        </div>
                        <div style="min-width: 150px;">
                            <label for="filter_role" style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #1a1a1a;">Nível</label>
                            <select id="filter_role" name="filter_role" style="width: 100%; padding: 0.6rem; border: 1px solid #d1d5db; border-radius: 8px;">
                                <option value="">Todos</option>
                                <option value="admin" <?= $userFilterRole === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                                <option value="support" <?= $userFilterRole === 'support' ? 'selected' : ''; ?>>Suporte</option>
                                <option value="client" <?= $userFilterRole === 'client' ? 'selected' : ''; ?>>Cliente</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="btn-primary" style="padding: 0.6rem 1.5rem;">Filtrar</button>
                            <a href="dashboard.php?page=usuarios" class="btn-outline" style="padding: 0.6rem 1.5rem; margin-left: 0.5rem;">Limpar</a>
                        </div>
                    </form>
                    
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nome</th>
                                    <th>E-mail</th>
                                    <th>Nível</th>
                                    <th>Criado em</th>
                                    <?php if ($user['role'] === 'admin'): ?>
                                        <th>Ações</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allUsers)): ?>
                                    <tr>
                                        <td colspan="<?= $user['role'] === 'admin' ? '6' : '5'; ?>">Nenhum usuário encontrado.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $roleLabels = [
                                        'admin' => 'Administrador',
                                        'support' => 'Suporte',
                                        'client' => 'Cliente'
                                    ];
                                    foreach ($allUsers as $usr): ?>
                                        <tr>
                                            <td><?= $usr['id']; ?></td>
                                            <td><?= sanitize($usr['name']); ?></td>
                                            <td><?= sanitize($usr['email']); ?></td>
                                            <td>
                                                <span class="tag" style="
                                                    <?php 
                                                    $roleColor = [
                                                        'admin' => 'background: #ef4444; color: #fff;',
                                                        'support' => 'background: #3b82f6; color: #fff;',
                                                        'client' => 'background: #10b981; color: #fff;'
                                                    ];
                                                    echo $roleColor[$usr['role']] ?? 'background: #6b7280; color: #fff;';
                                                    ?>
                                                ">
                                                    <?= $roleLabels[$usr['role']] ?? strtoupper($usr['role']); ?>
                                                </span>
                                            </td>
                                            <td><?= date('d/m/Y H:i', strtotime($usr['created_at'])); ?></td>
                                            <?php if ($user['role'] === 'admin'): ?>
                                                <td>
                                                    <div style="display: flex; gap: 0.5rem;">
                                                        <button onclick="openUserModal(<?= $usr['id']; ?>, '<?= htmlspecialchars($usr['name'], ENT_QUOTES); ?>', '<?= htmlspecialchars($usr['email'], ENT_QUOTES); ?>', '<?= htmlspecialchars($usr['role'], ENT_QUOTES); ?>')" class="btn-outline" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;">Editar</button>
                                                        <button onclick="confirmDeleteUser(<?= $usr['id']; ?>, '<?= htmlspecialchars($usr['name'], ENT_QUOTES); ?>')" class="btn-outline" style="padding: 0.4rem 0.8rem; font-size: 0.85rem; color: #ef4444; border-color: #ef4444;">Excluir</button>
                                                    </div>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Formulário de Criação (apenas para admin) -->
                <?php if ($user['role'] === 'admin'): ?>
                <section class="panel" id="createUserSection">
                    <div class="panel-header">
                        <h2>Criar novo usuário</h2>
                        <p>Cadastre rapidamente administradores, suporte ou clientes.</p>
                    </div>
                    <form method="post" class="quick-form">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="create_user_admin">
                        <div class="dual">
                            <div>
                                <label for="new_user_name">Nome</label>
                                <input type="text" id="new_user_name" name="new_user_name" required>
                            </div>
                            <div>
                                <label for="new_user_role">Perfil</label>
                                <select id="new_user_role" name="new_user_role">
                                    <option value="client">Cliente</option>
                                    <option value="support">Suporte</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                        </div>
                        <label for="new_user_email">E-mail corporativo</label>
                        <input type="email" id="new_user_email" name="new_user_email" required>

                        <label for="new_user_password">Senha temporária</label>
                        <input type="password" id="new_user_password" name="new_user_password" minlength="8" required>

                        <button type="submit" class="btn-primary">Criar usuário</button>
                    </form>
                </section>
                <?php endif; ?>

            <?php elseif ($section === 'relatorios'): ?>
                <section class="panel" id="reportSection">
                    <div class="panel-header">
                        <h2>Central de relatórios</h2>
                        <p>Escolha o formato e gere os arquivos com um clique.</p>
                    </div>
                    <div class="report-actions">
                        <div class="report-group">
                            <h3>Chamados atuais</h3>
                            <p>Exporta todos os chamados visíveis na central operacional.</p>
                            <div class="report-buttons">
                                <button class="btn-outline" data-report-action="excel">Exportar em Excel</button>
                                <button class="btn-primary" data-report-action="pdf">Exportar em PDF</button>
                            </div>
                        </div>
                        <div class="report-group">
                            <h3>Chamados fechados</h3>
                            <p>Histórico de chamados finalizados nos últimos registros.</p>
                            <div class="report-buttons">
                                <button class="btn-outline" data-report-action="excel-closed">Excel (fechados)</button>
                                <button class="btn-primary" data-report-action="pdf-closed">PDF (fechados)</button>
                            </div>
                        </div>
                    </div>
                </section>
                <div style="display:none">
                    <table id="operationalTable">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Título</th>
                            <th>Solicitante</th>
                            <th>Responsável</th>
                            <th>Categoria</th>
                            <th>Prioridade</th>
                            <th>Status</th>
                            <th>Atualizado</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($operationalTickets as $ticket): ?>
                            <tr>
                                <td><?= $ticket['id']; ?></td>
                                <td><?= sanitize($ticket['title']); ?></td>
                                <td><?= sanitize($ticket['requester']); ?></td>
                                <td><?= $ticket['assignee'] ? sanitize($ticket['assignee']) : '—'; ?></td>
                                <td><?= sanitize($ticket['category']); ?></td>
                                <td><?= strtoupper($ticket['priority']); ?></td>
                                <td><?= strtoupper($ticket['status']); ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($ticket['updated_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <table id="closedTable">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Título</th>
                            <th>Solicitante</th>
                            <th>Responsável</th>
                            <th>Prioridade</th>
                            <th>Status</th>
                            <th>Fechado em</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($closedTickets as $ticket): ?>
                            <tr>
                                <td><?= $ticket['id']; ?></td>
                                <td><?= sanitize($ticket['title']); ?></td>
                                <td><?= sanitize($ticket['requester']); ?></td>
                                <td><?= $ticket['assignee'] ? sanitize($ticket['assignee']) : '—'; ?></td>
                                <td><?= strtoupper($ticket['priority']); ?></td>
                                <td><?= strtoupper($ticket['status']); ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($ticket['updated_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
    </main>
    </div>

    <!-- Modal de Detalhes do Chamado -->
    <div id="ticketModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Detalhes do Chamado #<span id="modalTicketId"></span></h2>
                <button type="button" class="modal-close" onclick="closeTicketModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <div style="text-align: center; padding: 2rem;">
                    <p>Carregando...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function openTicketModal(ticketId) {
            const modal = document.getElementById('ticketModal');
            const modalBody = document.getElementById('modalBody');
            const modalTicketId = document.getElementById('modalTicketId');
            
            modal.style.display = 'flex';
            modalTicketId.textContent = ticketId;
            modalBody.innerHTML = '<div style="text-align: center; padding: 2rem;"><p>Carregando...</p></div>';
            
            // Buscar detalhes do chamado
            fetch(`api/ticket-details.php?id=${ticketId}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            })
                .then(response => {
                    if (!response.ok) {
                        return response.text().then(text => {
                            let errorData;
                            try {
                                errorData = JSON.parse(text);
                            } catch (e) {
                                errorData = { error: `Erro HTTP ${response.status}: ${text}` };
                            }
                            throw new Error(errorData.error || `Erro HTTP ${response.status}`);
                        });
                    }
                    return response.text().then(text => {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('Erro ao parsear JSON:', text);
                            throw new Error('Resposta inválida do servidor');
                        }
                    });
                })
                .then(data => {
                    if (data.error) {
                        let errorHtml = `<div class="alert alert-error">${escapeHtml(data.error)}</div>`;
                        if (data.debug) {
                            errorHtml += `<div style="margin-top: 1rem; padding: 1rem; background: #f5f5f5; border-radius: 8px; font-size: 0.875rem; color: #666;"><strong>Debug:</strong><pre style="white-space: pre-wrap; margin-top: 0.5rem;">${escapeHtml(data.debug)}</pre></div>`;
                        }
                        errorHtml += `<button type="button" class="btn-outline" onclick="closeTicketModal()" style="margin-top: 1rem;">Fechar</button>`;
                        modalBody.innerHTML = errorHtml;
                        return;
                    }
                    
                    const statusLabels = {
                        'open': 'Aberto',
                        'in_progress': 'Em andamento',
                        'resolved': 'Resolvido',
                        'closed': 'Fechado'
                    };
                    
                    const priorityLabels = {
                        'low': 'Baixa',
                        'medium': 'Média',
                        'high': 'Alta'
                    };
                    
                    let html = `
                        <div class="ticket-details">
                            <div class="detail-row">
                                <label>Título:</label>
                                <span id="detailTitle" style="color: #1a1a1a; font-weight: 500;">${escapeHtml(data.title)}</span>
                            </div>
                            <div class="detail-row">
                                <label>Categoria:</label>
                                <span id="detailCategory" style="color: #1a1a1a; font-weight: 500;">${escapeHtml(data.category)}</span>
                            </div>
                            <div class="detail-row">
                                <label>Prioridade:</label>
                                <span class="tag priority-${data.priority}">${priorityLabels[data.priority] || data.priority.toUpperCase()}</span>
                            </div>
                            <div class="detail-row">
                                <label>Status:</label>
                                <span class="tag status-${data.status}">${statusLabels[data.status] || data.status.toUpperCase()}</span>
                            </div>
                            <div class="detail-row">
                                <label>Solicitante:</label>
                                <span style="color: #1a1a1a; font-weight: 500;">${escapeHtml(data.requester)}</span>
                            </div>
                            <div class="detail-row">
                                <label>Responsável:</label>
                                <span style="color: #1a1a1a; font-weight: 500;">${data.assignee_name ? escapeHtml(data.assignee_name) : '—'}</span>
                            </div>
                            <div class="detail-row">
                                <label>Descrição:</label>
                                <div id="detailDescription" style="white-space: pre-wrap; margin-top: 0.5rem; color: #1a1a1a; line-height: 1.6;">${escapeHtml(data.description)}</div>
                            </div>
                            <div class="detail-row">
                                <label>Criado em:</label>
                                <span style="color: #1a1a1a; font-weight: 500;">${formatDate(data.created_at)}</span>
                            </div>
                            <div class="detail-row">
                                <label>Atualizado em:</label>
                                <span style="color: #1a1a1a; font-weight: 500;">${formatDate(data.updated_at)}</span>
                            </div>
                    `;
                    
                    // Anexos
                    if (data.attachments && data.attachments.length > 0) {
                        html += `
                            <div class="detail-row">
                                <label>Anexos:</label>
                                <div style="margin-top: 0.5rem;">
                        `;
                        data.attachments.forEach(att => {
                            const size = formatFileSize(att.file_size);
                            html += `
                                <div style="margin-bottom: 0.5rem;">
                                    <a href="${att.file_path}" target="_blank" style="color: var(--primary); text-decoration: underline;">
                                        📎 ${escapeHtml(att.original_name)}
                                    </a>
                                    <span style="color: #666; font-size: 0.875rem; margin-left: 0.5rem;">(${size})</span>
                                </div>
                            `;
                        });
                        html += `</div></div>`;
                    }
                    
                    html += `</div>`;
                    
                    // Exibir respostas do suporte anteriores (se existirem)
                    if (data.responses && data.responses.length > 0) {
                        html += `
                            <div style="margin-top: 2rem; padding-top: 2rem; border-top: 2px solid #e5e7eb;">
                                <h3 style="margin: 0 0 1.5rem 0; font-size: 1.2rem; font-weight: 600; color: #1a1a1a;">Respostas do Suporte</h3>
                                <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                        `;
                        data.responses.forEach(response => {
                            html += `
                                <div style="padding: 1.5rem; background: #f9fafb; border-radius: 8px; border-left: 4px solid #3b82f6;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                        <div>
                                            <strong style="color: #1a1a1a; font-size: 0.95rem;">${escapeHtml(response.responder_name || 'Suporte')}</strong>
                                            <span style="color: #666; font-size: 0.875rem; margin-left: 0.5rem;">${formatDate(response.created_at)}</span>
                                        </div>
                                    </div>
                                    <div style="color: #1a1a1a; line-height: 1.6; white-space: pre-wrap; margin-bottom: 0.75rem;">${escapeHtml(response.response_text)}</div>
                            `;
                            
                            // Exibir anexos da resposta se existirem
                            if (response.attachments && response.attachments.length > 0) {
                                html += `<div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #e5e7eb;">`;
                                response.attachments.forEach(att => {
                                    const size = formatFileSize(att.file_size);
                                    html += `
                                        <div style="margin-bottom: 0.5rem;">
                                            <a href="${att.file_path}" target="_blank" style="color: var(--primary); text-decoration: underline;">
                                                📎 ${escapeHtml(att.original_name)}
                                            </a>
                                            <span style="color: #666; font-size: 0.875rem; margin-left: 0.5rem;">(${size})</span>
                                        </div>
                                    `;
                                });
                                html += `</div>`;
                            }
                            
                            html += `</div>`;
                        });
                        html += `</div></div>`;
                    }
                    
                    // Seção de Resposta do Suporte (apenas para admin e suporte)
                    const userRole = '<?= $user["role"]; ?>';
                    if (userRole === 'admin' || userRole === 'support') {
                        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
                        html += `
                            <div style="margin-top: 2rem; padding-top: 2rem; border-top: 2px solid #e5e7eb;">
                                <h3 style="margin: 0 0 1.5rem 0; font-size: 1.2rem; font-weight: 600; color: #1a1a1a;">${data.responses && data.responses.length > 0 ? 'Nova Resposta do Suporte' : 'Resposta do Suporte'}</h3>
                                
                                <form id="supportResponseForm" method="POST" action="dashboard.php" enctype="multipart/form-data" onsubmit="return saveSupportResponse(event, ${ticketId})">
                                    <input type="hidden" name="action" value="add_response">
                                    <input type="hidden" name="ticket_id" value="${ticketId}">
                                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                                    
                                    <div class="form-group">
                                        <label for="supportResponse" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1a1a1a;">Resposta do Suporte</label>
                                        <textarea 
                                            id="supportResponse" 
                                            name="response" 
                                            rows="6" 
                                            placeholder="Digite sua resposta para o usuário..."
                                            style="width: 100%; padding: 0.75rem; border: 2px solid #3b82f6; border-radius: 8px; font-size: 1rem; color: #1a1a1a; background-color: #fff; resize: vertical;"
                                            required
                                        ></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #1a1a1a;">Anexar Imagens</label>
                                        <div style="display: flex; align-items: center; gap: 1rem;">
                                            <input 
                                                type="file" 
                                                id="responseImages" 
                                                name="response_images[]" 
                                                multiple 
                                                accept="image/*"
                                                style="display: none;"
                                                onchange="updateFileLabel(this)"
                                            >
                                            <button 
                                                type="button" 
                                                onclick="document.getElementById('responseImages').click()"
                                                style="padding: 0.75rem 1.5rem; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 8px; cursor: pointer; font-weight: 500; color: #1a1a1a;"
                                            >
                                                Escolher arquivos
                                            </button>
                                            <span id="fileLabel" style="color: #666; font-size: 0.9rem;">Nenhum arquivo escolhido</span>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top: 1.5rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                                        <button type="submit" class="btn-primary" style="padding: 0.75rem 2rem;">Salvar Resposta</button>
                                        
                                        <div style="margin-left: auto; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                            <button type="button" class="btn-status" data-status="open" onclick="changeTicketStatus(${ticketId}, 'open')" style="padding: 0.5rem 1rem; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; color: #1a1a1a; font-size: 0.875rem;">Aberto</button>
                                            <button type="button" class="btn-status" data-status="in_progress" onclick="changeTicketStatus(${ticketId}, 'in_progress')" style="padding: 0.5rem 1rem; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; cursor: pointer; color: #92400e; font-size: 0.875rem;">Em andamento</button>
                                            <button type="button" class="btn-status" data-status="closed" onclick="changeTicketStatus(${ticketId}, 'closed')" style="padding: 0.5rem 1rem; background: #d1fae5; border: 1px solid #10b981; border-radius: 6px; cursor: pointer; color: #065f46; font-size: 0.875rem;">Fechado</button>
                                            ${data.can_delete ? `<button type="button" class="btn-danger" onclick="deleteTicket(${ticketId})" style="padding: 0.5rem 1rem; font-size: 0.875rem; background: #ef4444; color: #fff; border: none; border-radius: 6px; cursor: pointer;">Excluir</button>` : ''}
                                            <button type="button" class="btn-primary" onclick="closeTicketModal()" style="padding: 0.5rem 1rem; font-size: 0.875rem;">Fechar</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        `;
                    } else {
                        // Botões de ação para clientes
                        html += `
                            <div class="modal-actions" style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border); display: flex; gap: 1rem; justify-content: flex-end;">
                        `;
                        
                        if (data.can_edit) {
                            html += `
                                <button type="button" class="btn-primary" onclick="editTicket(${ticketId})">Editar</button>
                            `;
                        }
                        
                        if (data.can_delete) {
                            html += `
                                <button type="button" class="btn-danger" onclick="deleteTicket(${ticketId})">Excluir</button>
                            `;
                        }
                        
                        html += `
                                <button type="button" class="btn-outline" onclick="closeTicketModal()">Fechar</button>
                            </div>
                        `;
                    }
                    
                    modalBody.innerHTML = html;
                })
                .catch(error => {
                    console.error('Erro ao buscar detalhes:', error);
                    console.error('Stack:', error.stack);
                    modalBody.innerHTML = `<div class="alert alert-error">${escapeHtml(error.message || 'Erro ao carregar detalhes do chamado.')}</div><div style="margin-top: 1rem; padding: 1rem; background: #f5f5f5; border-radius: 8px; font-size: 0.875rem; color: #666;">Verifique o console do navegador (F12) para mais detalhes.</div><button type="button" class="btn-outline" onclick="closeTicketModal()" style="margin-top: 1rem;">Fechar</button>`;
                });
        }
        
        function closeTicketModal() {
            document.getElementById('ticketModal').style.display = 'none';
        }
        
        function editTicket(ticketId) {
            const modalBody = document.getElementById('modalBody');
            const title = document.getElementById('detailTitle').textContent;
            const category = document.getElementById('detailCategory').textContent;
            const description = document.getElementById('detailDescription').textContent;
            
            // Buscar dados completos para edição
            fetch(`api/ticket-details.php?id=${ticketId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    
                    const statusLabels = {
                        'open': 'Aberto',
                        'in_progress': 'Em andamento',
                        'resolved': 'Resolvido',
                        'closed': 'Fechado'
                    };
                    
                    const priorityLabels = {
                        'low': 'Baixa',
                        'medium': 'Média',
                        'high': 'Alta'
                    };
                    
                    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
                    
                    let html = `
                        <form id="editTicketForm" method="post" onsubmit="saveTicketEdit(event, ${ticketId})">
                            <input type="hidden" name="action" value="edit_ticket">
                            <input type="hidden" name="ticket_id" value="${ticketId}">
                            <input type="hidden" name="csrf_token" value="${csrfToken}">
                            
                            <div class="form-group">
                                <label for="editTitle">Título:</label>
                                <input type="text" id="editTitle" name="title" value="${escapeHtml(data.title)}" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="editCategory">Categoria:</label>
                                <input type="text" id="editCategory" name="category" value="${escapeHtml(data.category)}" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="editPriority">Prioridade:</label>
                                <select id="editPriority" name="priority" required>
                                    <option value="low" ${data.priority === 'low' ? 'selected' : ''}>Baixa</option>
                                    <option value="medium" ${data.priority === 'medium' ? 'selected' : ''}>Média</option>
                                    <option value="high" ${data.priority === 'high' ? 'selected' : ''}>Alta</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="editDescription">Descrição:</label>
                                <textarea id="editDescription" name="description" rows="6" required>${escapeHtml(data.description)}</textarea>
                            </div>
                            
                            <div class="modal-actions" style="margin-top: 1.5rem; display: flex; gap: 1rem; justify-content: flex-end;">
                                <button type="button" class="btn-outline" onclick="openTicketModal(${ticketId})">Cancelar</button>
                                <button type="submit" class="btn-primary">Salvar</button>
                            </div>
                        </form>
                    `;
                    
                    modalBody.innerHTML = html;
                })
                .catch(error => {
                    alert('Erro ao carregar dados para edição.');
                    console.error('Erro:', error);
                });
        }
        
        function saveTicketEdit(event, ticketId) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            
            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.redirected) {
                    window.location.href = response.url;
                } else {
                    return response.text();
                }
            })
            .catch(error => {
                alert('Erro ao salvar alterações.');
                console.error('Erro:', error);
            });
        }
        
        function deleteTicket(ticketId) {
            if (!confirm('Tem certeza que deseja excluir este chamado? Esta ação não pode ser desfeita.')) {
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'dashboard.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_ticket';
            form.appendChild(actionInput);
            
            const ticketIdInput = document.createElement('input');
            ticketIdInput.type = 'hidden';
            ticketIdInput.name = 'ticket_id';
            ticketIdInput.value = ticketId;
            form.appendChild(ticketIdInput);
            
            const csrfToken = document.querySelector('input[name="csrf_token"]');
            if (csrfToken) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = csrfToken.value;
                form.appendChild(csrfInput);
            }
            
            document.body.appendChild(form);
            form.submit();
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleString('pt-BR', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }
        
        function updateFileLabel(input) {
            const label = document.getElementById('fileLabel');
            if (input.files && input.files.length > 0) {
                const count = input.files.length;
                label.textContent = count === 1 ? input.files[0].name : count + ' arquivos selecionados';
                label.style.color = '#1a1a1a';
            } else {
                label.textContent = 'Nenhum arquivo escolhido';
                label.style.color = '#666';
            }
        }
        
        function saveSupportResponse(event, ticketId) {
            const form = event.target;
            
            // Validar se há texto na resposta
            const responseTextarea = form.querySelector('textarea[name="response"]');
            if (!responseTextarea || !responseTextarea.value || responseTextarea.value.trim() === '') {
                event.preventDefault();
                alert('Por favor, digite uma resposta antes de salvar.');
                return false;
            }
            
            // Mostrar loading
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Salvando...';
            }
            
            // Permitir que o formulário seja enviado normalmente
            // O servidor vai processar e redirecionar, mostrando mensagem de sucesso
            return true;
        }
        
        function changeTicketStatus(ticketId, status) {
            if (!confirm('Tem certeza que deseja alterar o status do chamado?')) {
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'dashboard.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'update_status';
            form.appendChild(actionInput);
            
            const ticketIdInput = document.createElement('input');
            ticketIdInput.type = 'hidden';
            ticketIdInput.name = 'ticket_id';
            ticketIdInput.value = ticketId;
            form.appendChild(ticketIdInput);
            
            const statusInput = document.createElement('input');
            statusInput.type = 'hidden';
            statusInput.name = 'status';
            statusInput.value = status;
            form.appendChild(statusInput);
            
            const csrfToken = document.querySelector('input[name="csrf_token"]');
            if (csrfToken) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = csrfToken.value;
                form.appendChild(csrfInput);
            }
            
            document.body.appendChild(form);
            form.submit();
        }
        
        // Fechar modal ao clicar fora
        window.onclick = function(event) {
            const modal = document.getElementById('ticketModal');
            if (event.target === modal) {
                closeTicketModal();
            }
        }
        
        // Filtros de chamados
        document.addEventListener('DOMContentLoaded', function() {
            const applyFiltersBtn = document.getElementById('applyFilters');
            const filterId = document.getElementById('filterId');
            const filterStatus = document.getElementById('filterStatus');
            const filterPriority = document.getElementById('filterPriority');
            const filterUser = document.getElementById('filterUser');
            const ticketFilter = document.getElementById('ticketFilter');
            const table = document.getElementById('operationalTable');
            
            if (!applyFiltersBtn || !table) return;
            
            function applyFilters() {
                const rows = table.querySelectorAll('tbody tr');
                const idValue = filterId?.value.trim().toLowerCase() || '';
                const statusValue = filterStatus?.value || '';
                const priorityValue = filterPriority?.value || '';
                const userValue = filterUser?.value.trim().toLowerCase() || '';
                
                rows.forEach(row => {
                    let show = true;
                    
                    // Filtro por ID
                    if (idValue) {
                        const ticketId = row.cells[0]?.textContent.trim() || '';
                        if (!ticketId.toLowerCase().includes(idValue)) {
                            show = false;
                        }
                    }
                    
                    // Filtro por Status
                    if (statusValue) {
                        const rowStatus = row.getAttribute('data-status') || '';
                        if (rowStatus !== statusValue) {
                            show = false;
                        }
                    }
                    
                    // Filtro por Prioridade
                    if (priorityValue) {
                        const rowPriority = row.getAttribute('data-priority') || '';
                        if (rowPriority !== priorityValue) {
                            show = false;
                        }
                    }
                    
                    // Filtro por Usuário (solicitante ou responsável)
                    if (userValue) {
                        const requester = row.cells[2]?.textContent.trim().toLowerCase() || '';
                        const assignee = row.cells[3]?.textContent.trim().toLowerCase() || '';
                        if (!requester.includes(userValue) && !assignee.includes(userValue)) {
                            show = false;
                        }
                    }
                    
                    row.style.display = show ? '' : 'none';
                });
            }
            
            if (applyFiltersBtn) {
                applyFiltersBtn.addEventListener('click', applyFilters);
            }
            
            // Aplicar filtros ao pressionar Enter nos campos
            [filterId, filterUser].forEach(input => {
                if (input) {
                    input.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            applyFilters();
                        }
                    });
                }
            });
        });
    </script>

    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background-color: #fff;
            border-radius: 16px;
            padding: 0;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
            color: #1a1a1a;
            font-weight: 700;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 2rem;
            cursor: pointer;
            color: #333;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-close:hover {
            color: #000;
        }
        
        .modal-body {
            padding: 2rem;
            color: #1a1a1a;
        }
        
        .ticket-details {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .detail-row {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .detail-row label {
            font-weight: 600;
            color: #1a1a1a;
            font-size: 0.95rem;
        }
        
        .detail-row span,
        .detail-row div {
            color: #333;
            font-size: 1rem;
        }
        
        .detail-row > span:not(.tag) {
            color: #1a1a1a;
            font-weight: 500;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #1a1a1a;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            color: #1a1a1a;
            background-color: #fff;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .btn-view {
            padding: 0.5rem 1rem;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-view:hover {
            opacity: 0.9;
        }
        
        .btn-danger {
            padding: 0.75rem 1.5rem;
            background: #dc2626;
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-danger:hover {
            background: #b91c1c;
        }
        
        .btn-outline {
            padding: 0.75rem 1.5rem;
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: #fff;
        }
    </style>

    <!-- Modal de Edição de Usuário -->
    <?php if ($user['role'] === 'admin'): ?>
    <div id="userModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2>Editar Usuário</h2>
                <button onclick="closeUserModal()" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="userEditForm" method="post">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" id="userAction">
                    <input type="hidden" name="user_id" id="editUserId">
                    
                    <div id="editPasswordSection" class="form-group">
                        <label for="new_password">Nova Senha</label>
                        <input type="password" id="new_password" name="new_password" minlength="8" placeholder="Mínimo 8 caracteres">
                        <small style="color: #666; font-size: 0.85rem;">Deixe em branco para não alterar</small>
                    </div>
                    
                    <div id="editRoleSection" class="form-group">
                        <label for="new_role">Nível</label>
                        <select id="new_role" name="new_role">
                            <option value="client">Cliente</option>
                            <option value="support">Suporte</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                    
                    <div class="modal-footer" style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                        <button type="button" onclick="closeUserModal()" class="btn-outline">Cancelar</button>
                        <button type="submit" class="btn-primary">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Funções para gerenciar usuários
        function openUserModal(userId, userName, userEmail, userRole) {
            const modal = document.getElementById('userModal');
            const form = document.getElementById('userEditForm');
            const passwordSection = document.getElementById('editPasswordSection');
            const roleSection = document.getElementById('editRoleSection');
            
            document.getElementById('editUserId').value = userId;
            document.getElementById('new_role').value = userRole;
            document.getElementById('new_password').value = '';
            
            // Mostrar ambas as seções
            passwordSection.style.display = 'block';
            roleSection.style.display = 'block';
            
            // Permitir alterar senha e nível
            form.onsubmit = function(e) {
                e.preventDefault();
                const formData = new FormData(form);
                const password = formData.get('new_password');
                const role = formData.get('new_role');
                const originalRole = userRole;
                
                // Se há senha, alterar senha
                if (password && password.trim() !== '') {
                    const passwordForm = new FormData();
                    passwordForm.append('csrf_token', formData.get('csrf_token'));
                    passwordForm.append('action', 'update_user_password');
                    passwordForm.append('user_id', formData.get('user_id'));
                    passwordForm.append('new_password', password);
                    submitUserAction(passwordForm);
                }
                
                // Se o nível mudou, alterar nível
                if (role !== originalRole) {
                    const roleForm = new FormData();
                    roleForm.append('csrf_token', formData.get('csrf_token'));
                    roleForm.append('action', 'update_user_role');
                    roleForm.append('user_id', formData.get('user_id'));
                    roleForm.append('new_role', role);
                    submitUserAction(roleForm);
                } else if (!password || password.trim() === '') {
                    closeUserModal();
                }
            };
            
            modal.style.display = 'flex';
        }
        
        function submitUserAction(formData) {
            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.redirected) {
                    window.location.href = response.url;
                } else {
                    return response.text();
                }
            })
            .then(data => {
                if (data) {
                    // Tentar fazer parse do JSON se houver
                    try {
                        const json = JSON.parse(data);
                        if (json.error) {
                            alert('Erro: ' + json.error);
                        }
                    } catch (e) {
                        // Se não for JSON, recarregar a página
                        window.location.reload();
                    }
                } else {
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                alert('Erro ao processar solicitação.');
            });
        }
        
        function closeUserModal() {
            document.getElementById('userModal').style.display = 'none';
        }
        
        function confirmDeleteUser(userId, userName) {
            if (confirm('Tem certeza que deseja excluir o usuário "' + userName + '"?\n\nEsta ação não pode ser desfeita.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'dashboard.php';
                
                const csrf = document.createElement('input');
                csrf.type = 'hidden';
                csrf.name = 'csrf_token';
                csrf.value = document.querySelector('input[name="csrf_token"]')?.value || '';
                form.appendChild(csrf);
                
                const action = document.createElement('input');
                action.type = 'hidden';
                action.name = 'action';
                action.value = 'delete_user';
                form.appendChild(action);
                
                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'user_id';
                userIdInput.value = userId;
                form.appendChild(userIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Fechar modal ao clicar fora
        window.onclick = function(event) {
            const modal = document.getElementById('userModal');
            if (event.target === modal) {
                closeUserModal();
            }
        }
    </script>
</body>
</html>

