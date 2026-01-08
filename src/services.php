<?php

declare(strict_types=1);

const TDESK_STATUSES = ['open', 'in_progress', 'resolved', 'closed'];
const TDESK_PRIORITIES = ['low', 'medium', 'high'];

function find_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function update_user_password(int $userId, string $newPassword): void
{
    $algo = app_config()['security']['password_algo'];
    $hash = password_hash($newPassword, $algo);
    $stmt = db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
    $stmt->execute([
        'password_hash' => $hash,
        'id' => $userId,
    ]);
}

function cleanup_password_resets(): void
{
    db()->exec('DELETE FROM password_resets WHERE used_at IS NOT NULL OR expires_at < NOW()');
}

function create_password_reset(array $user, int $ttlMinutes = 60): array
{
    cleanup_password_resets();

    $pdo = db();
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = :user_id')
        ->execute(['user_id' => $user['id']]);

    $selector = bin2hex(random_bytes(8));
    $token = bin2hex(random_bytes(32));
    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
    $expiresAt = (new DateTimeImmutable("+{$ttlMinutes} minutes"))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO password_resets (user_id, selector, token_hash, expires_at) VALUES (:user_id, :selector, :token_hash, :expires_at)'
    );
    $stmt->execute([
        'user_id' => $user['id'],
        'selector' => $selector,
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
    ]);

    return [
        'selector' => $selector,
        'token' => $token,
        'expires_at' => $expiresAt,
    ];
}

function validate_password_reset(string $selector, string $token): ?array
{
    if ($selector === '' || $token === '') {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT pr.*, u.email, u.name
         FROM password_resets pr
         INNER JOIN users u ON u.id = pr.user_id
         WHERE pr.selector = :selector
         LIMIT 1'
    );
    $stmt->execute(['selector' => $selector]);
    $reset = $stmt->fetch();

    if (!$reset) {
        return null;
    }

    if ($reset['used_at'] !== null) {
        return null;
    }

    if (new DateTimeImmutable($reset['expires_at']) < new DateTimeImmutable()) {
        return null;
    }

    if (!password_verify($token, $reset['token_hash'])) {
        return null;
    }

    return $reset;
}

function mark_password_reset_used(int $resetId): void
{
    $stmt = db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id');
    $stmt->execute(['id' => $resetId]);
}

function send_password_reset_email(array $user, string $selector, string $token): void
{
    $config = app_config();
    $baseUrl = app_base_url();
    $link = sprintf(
        '%s/reset.php?selector=%s&token=%s',
        rtrim($baseUrl, '/'),
        urlencode($selector),
        urlencode($token)
    );

    $subject = sprintf('%s • Recuperação de acesso', $config['app']['name']);
    $message = <<<BODY
Olá {$user['name']},

Recebemos um pedido para redefinir a sua senha no {$config['app']['name']}.
Basta clicar no link abaixo (válido por 60 minutos):
{$link}

Se você não fez esta solicitação, ignore esta mensagem.

Equipe {$config['app']['name']}
BODY;

    $from = $config['mail']['from'] ?? 'no-reply@tdesk.local';
    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $from,
        'Content-Type: text/plain; charset=UTF-8',
    ];

    $sent = @mail($user['email'], $subject, $message, implode("\r\n", $headers));
    if (!$sent) {
        error_log(sprintf('[TDesk] Link de redefinição para %s: %s', $user['email'], $link));
    }
}

function create_user(array $payload): int
{
    $pdo = db();
    $algo = app_config()['security']['password_algo'];
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)'
    );
    $stmt->execute([
        'name' => $payload['name'],
        'email' => $payload['email'],
        'password_hash' => password_hash($payload['password'], $algo),
        'role' => $payload['role'],
    ]);

    return (int)$pdo->lastInsertId();
}

function fetch_support_agents(): array
{
    $stmt = db()->prepare("SELECT id, name FROM users WHERE role IN ('admin', 'support') ORDER BY name ASC");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Busca todos os usuários do sistema (apenas para admin e suporte)
 */
function fetch_all_users(string $filterRole = '', string $searchTerm = ''): array
{
    $where = [];
    $params = [];
    
    if ($filterRole !== '' && in_array($filterRole, ['admin', 'support', 'client'], true)) {
        $where[] = 'role = :role';
        $params['role'] = $filterRole;
    }
    
    if ($searchTerm !== '') {
        $where[] = '(name LIKE :search OR email LIKE :search)';
        $params['search'] = '%' . $searchTerm . '%';
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $sql = "SELECT id, name, email, role, created_at FROM users {$whereClause} ORDER BY created_at DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Atualiza o nível/role de um usuário
 */
function update_user_role(array $currentUser, int $userId, string $newRole): void
{
    if ($currentUser['role'] !== 'admin') {
        throw new RuntimeException('Apenas administradores podem alterar níveis de usuários.');
    }
    
    if (!in_array($newRole, ['admin', 'support', 'client'], true)) {
        throw new InvalidArgumentException('Nível inválido.');
    }
    
    // Não permitir alterar o próprio nível
    if ($userId == $currentUser['id']) {
        throw new RuntimeException('Você não pode alterar seu próprio nível.');
    }
    
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    if (!$stmt->fetch()) {
        throw new RuntimeException('Usuário não encontrado.');
    }
    
    $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
    $stmt->execute([
        'role' => $newRole,
        'id' => $userId,
    ]);
}

/**
 * Exclui um usuário do sistema
 */
function delete_user(array $currentUser, int $userId): void
{
    if ($currentUser['role'] !== 'admin') {
        throw new RuntimeException('Apenas administradores podem excluir usuários.');
    }
    
    // Não permitir excluir a si mesmo
    if ($userId == $currentUser['id']) {
        throw new RuntimeException('Você não pode excluir sua própria conta.');
    }
    
    $pdo = db();
    
    // Verificar se o usuário existe
    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $userToDelete = $stmt->fetch();
    
    if (!$userToDelete) {
        throw new RuntimeException('Usuário não encontrado.');
    }
    
    // Verificar se há chamados associados
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE user_id = :user_id OR assigned_to = :user_id');
    $stmt->execute(['user_id' => $userId]);
    $ticketCount = (int)$stmt->fetchColumn();
    
    if ($ticketCount > 0) {
        throw new RuntimeException('Não é possível excluir usuário que possui chamados associados. Primeiro transfira ou feche os chamados.');
    }
    
    $pdo->beginTransaction();
    
    try {
        // Excluir tokens de reset de senha
        $stmt = $pdo->prepare('DELETE FROM password_resets WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        
        // Excluir o usuário (CASCADE vai excluir atividades relacionadas)
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Erro ao excluir usuário.');
        }
        
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function fetch_ticket_stats(array $user): array
{
    [$where, $params] = build_ticket_scope($user);
    $sql = <<<SQL
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS progress_count,
            SUM(CASE WHEN status IN ('resolved', 'closed') THEN 1 ELSE 0 END) AS done_count,
            SUM(CASE WHEN priority = 'high' AND status != 'resolved' THEN 1 ELSE 0 END) AS alert_count
        FROM tickets t
        {$where}
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $stats = $stmt->fetch() ?: [];

    return [
        'total' => (int)($stats['total'] ?? 0),
        'open' => (int)($stats['open_count'] ?? 0),
        'in_progress' => (int)($stats['progress_count'] ?? 0),
        'completed' => (int)($stats['done_count'] ?? 0),
        'alerts' => (int)($stats['alert_count'] ?? 0),
    ];
}

function fetch_priority_distribution(array $user): array
{
    [$where, $params] = build_ticket_scope($user);
    $sql = <<<SQL
        SELECT priority, COUNT(*) AS total
        FROM tickets t
        {$where}
        GROUP BY priority
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $base = ['low' => 0, 'medium' => 0, 'high' => 0];
    foreach ($rows as $row) {
        $priority = $row['priority'];
        if (array_key_exists($priority, $base)) {
            $base[$priority] = (int)$row['total'];
        }
    }

    return [
        'labels' => ['Baixa', 'Média', 'Alta'],
        'slugs' => array_keys($base),
        'data' => array_values($base),
    ];
}

function fetch_status_distribution(array $user): array
{
    [$where, $params] = build_ticket_scope($user);
    $sql = <<<SQL
        SELECT status, COUNT(*) AS total
        FROM tickets t
        {$where}
        GROUP BY status
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $base = [
        'open' => 0,
        'in_progress' => 0,
        'resolved' => 0,
        'closed' => 0,
    ];

    foreach ($rows as $row) {
        $status = $row['status'];
        if (array_key_exists($status, $base)) {
            $base[$status] = (int)$row['total'];
        }
    }

    return $base;
}

function fetch_ticket_list(array $user): array
{
    [$where, $params] = build_ticket_scope($user);
    $sql = <<<SQL
        SELECT
            t.id,
            t.title,
            t.category,
            t.priority,
            t.status,
            t.updated_at,
            t.created_at,
            creator.name AS requester,
            assignee.name AS assignee
        FROM tickets t
        INNER JOIN users creator ON creator.id = t.user_id
        LEFT JOIN users assignee ON assignee.id = t.assigned_to
        {$where}
        ORDER BY t.updated_at DESC
        LIMIT 50
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetch_operational_board(array $user, int $limit = 200): array
{
    [$where, $params] = build_ticket_scope($user);
    $limit = max(50, min(500, $limit));
    $sql = <<<SQL
        SELECT
            t.id,
            t.user_id,
            t.title,
            t.category,
            t.priority,
            t.status,
            t.updated_at,
            t.created_at,
            creator.name AS requester,
            creator.email AS requester_email,
            assignee.name AS assignee,
            assignee.email AS assignee_email
        FROM tickets t
        INNER JOIN users creator ON creator.id = t.user_id
        LEFT JOIN users assignee ON assignee.id = t.assigned_to
        {$where}
        ORDER BY t.created_at DESC
        LIMIT :limit_rows
    SQL;

    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function fetch_closed_tickets(array $user): array
{
    [$where, $params] = build_ticket_scope($user);
    $statusClause = $where === ''
        ? "WHERE t.status IN ('resolved','closed')"
        : $where . " AND t.status IN ('resolved','closed')";

    $sql = <<<SQL
        SELECT
            t.id,
            t.title,
            t.category,
            t.priority,
            t.status,
            t.updated_at,
            creator.name AS requester,
            assignee.name AS assignee
        FROM tickets t
        INNER JOIN users creator ON creator.id = t.user_id
        LEFT JOIN users assignee ON assignee.id = t.assigned_to
        {$statusClause}
        ORDER BY t.updated_at DESC
        LIMIT 200
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetch_recent_activity(array $user, int $limit = 6): array
{
    [$where, $params] = build_ticket_scope($user, 'ti');
    $sql = <<<SQL
        SELECT
            a.id,
            a.action,
            a.details,
            a.from_status,
            a.to_status,
            a.created_at,
            ti.title,
            actor.name AS actor_name
        FROM ticket_activity a
        INNER JOIN tickets ti ON ti.id = a.ticket_id
        LEFT JOIN users actor ON actor.id = a.actor_id
        {$where}
        ORDER BY a.created_at DESC
        LIMIT :limit_rows
    SQL;

    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function fetch_chart_series(array $user, int $days = 14): array
{
    [$where, $params] = build_ticket_scope($user);
    $startDate = (new DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d 00:00:00');
    $params[':start_date'] = $startDate;

    $dateClause = $where === ''
        ? 'WHERE t.created_at >= :start_date'
        : $where . ' AND t.created_at >= :start_date';

    $sql = <<<SQL
        SELECT
            DATE(t.created_at) AS bucket_day,
            SUM(CASE WHEN t.status IN ('open','in_progress') THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN t.status IN ('resolved','closed') THEN 1 ELSE 0 END) AS completed
        FROM tickets t
        {$dateClause}
        GROUP BY DATE(t.created_at)
        ORDER BY bucket_day ASC
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $labels = [];
    $active = [];
    $completed = [];

    foreach ($rows as $row) {
        $labels[] = $row['bucket_day'];
        $active[] = (int)$row['active'];
        $completed[] = (int)$row['completed'];
    }

    return [
        'labels' => $labels,
        'datasets' => [
            'active' => $active,
            'completed' => $completed,
        ],
    ];
}

/**
 * Valida e processa upload de arquivo
 * 
 * @param array $file Array $_FILES['attachments']
 * @param string $subfolder Subpasta dentro de uploads/attachments (opcional)
 * @return array|null Array com informações do arquivo ou null se inválido
 */
function validate_and_process_upload(array $file, string $subfolder = ''): ?array
{
    // Verificar se houve erro no upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return null; // Nenhum arquivo enviado é válido
        }
        throw new RuntimeException('Erro no upload do arquivo.');
    }

    // Validar tamanho máximo (10MB)
    $maxSize = 10 * 1024 * 1024; // 10MB em bytes
    if ($file['size'] > $maxSize) {
        throw new InvalidArgumentException('Arquivo muito grande. Tamanho máximo: 10MB.');
    }

    // Validar extensão
    $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'zip', 'rar'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new InvalidArgumentException('Tipo de arquivo não permitido. Extensões permitidas: ' . implode(', ', $allowedExtensions));
    }

    // Validar MIME type
    $mimeType = $file['type'] ?? '';
    $detectedMime = null;
    
    // Se fileinfo estiver disponível, usar para validação mais precisa
    if (function_exists('finfo_open') && file_exists($file['tmp_name'])) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
    }

    // Usar o MIME type detectado se disponível, senão usar o informado pelo navegador
    $finalMimeType = $detectedMime ?: $mimeType;

    $allowedMimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/zip',
        'application/x-rar-compressed',
        'application/x-zip-compressed',
        'application/octet-stream', // Para alguns arquivos que não têm MIME type específico
    ];

    // Validar MIME type apenas se tiver sido detectado ou informado
    // Se não tiver MIME type, confiar apenas na extensão (menos seguro, mas mais compatível)
    if ($finalMimeType && !in_array($finalMimeType, $allowedMimes, true)) {
        // Se o MIME type não estiver na lista, mas a extensão estiver permitida, 
        // permitir o upload (alguns navegadores enviam MIME types incorretos)
        // Mas logar para monitoramento
        error_log(sprintf('[TDesk Upload] MIME type não permitido mas extensão OK: %s (arquivo: %s)', $finalMimeType, $file['name']));
    }

    // Gerar nome único para o arquivo
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    $baseDir = __DIR__ . '/../public/uploads/attachments/';
    $uploadDir = $subfolder ? $baseDir . trim($subfolder, '/') . '/' : $baseDir;
    
    // Criar diretório se não existir
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new RuntimeException('Não foi possível criar o diretório de uploads. Verifique as permissões.');
        }
    }

    // Verificar se o diretório é gravável
    if (!is_writable($uploadDir)) {
        throw new RuntimeException('O diretório de uploads não tem permissão de escrita. Verifique as permissões.');
    }

    $filePath = $uploadDir . $storedName;

    // Verificar se o arquivo temporário existe
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Arquivo temporário inválido ou não encontrado.');
    }

    // Mover arquivo para o diretório de uploads
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new RuntimeException('Erro ao salvar arquivo. Verifique as permissões do diretório de uploads.');
    }

    $relativePath = 'uploads/attachments/' . ($subfolder ? trim($subfolder, '/') . '/' : '') . $storedName;

    return [
        'original_name' => $file['name'],
        'stored_name' => $storedName,
        'file_path' => $relativePath,
        'file_size' => $file['size'],
        'mime_type' => $finalMimeType ?: $mimeType,
    ];
}

/**
 * Salva anexos de um chamado no banco de dados
 * 
 * @param PDO $pdo Conexão com banco
 * @param int $ticketId ID do chamado
 * @param array $attachments Array de informações dos arquivos
 */
function save_ticket_attachments(PDO $pdo, int $ticketId, array $attachments): void
{
    if (empty($attachments)) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO ticket_attachments (ticket_id, original_name, stored_name, file_path, file_size, mime_type)
             VALUES (:ticket_id, :original_name, :stored_name, :file_path, :file_size, :mime_type)'
        );

        foreach ($attachments as $attachment) {
            $stmt->execute([
                'ticket_id' => $ticketId,
                'original_name' => $attachment['original_name'],
                'stored_name' => $attachment['stored_name'],
                'file_path' => $attachment['file_path'],
                'file_size' => $attachment['file_size'],
                'mime_type' => $attachment['mime_type'],
            ]);
        }
    } catch (PDOException $e) {
        // Se a tabela não existir, apenas logar o erro mas não falhar a criação do chamado
        if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Table") !== false) {
            error_log(sprintf('[TDesk Warning] Tabela ticket_attachments não encontrada. Anexos não foram salvos no banco. Ticket ID: %d', $ticketId));
            // Não relançar a exceção - o chamado foi criado, apenas os anexos não foram salvos no banco
        } else {
            // Outros erros de banco devem ser relançados
            throw $e;
        }
    }
}

function create_ticket_record(array $user, array $input): int
{
    $pdo = db();
    validate_priority($input['priority']);
    
    // Validar que o user_id existe na tabela users
    $userId = isset($user['id']) ? (int)$user['id'] : null;
    if ($userId === null || $userId <= 0) {
        error_log(sprintf('[TDesk Error] create_ticket_record: user_id inválido. User array: %s', json_encode($user)));
        throw new RuntimeException('ID de usuário inválido. Faça login novamente.');
    }
    
    // Verificar se o usuário existe no banco
    // Se não existir, tentar buscar pelo email da sessão
    try {
        $checkStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $checkStmt->execute(['id' => $userId]);
        $userExists = $checkStmt->fetch();
        if (!$userExists) {
            error_log(sprintf('[TDesk Warning] create_ticket_record: usuário com ID %d não encontrado. Tentando buscar por email...', $userId));
            // Tentar buscar pelo email se disponível
            if (isset($user['email']) && !empty($user['email'])) {
                $emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $emailStmt->execute(['email' => $user['email']]);
                $userByEmail = $emailStmt->fetch();
                if ($userByEmail) {
                    $userId = (int)$userByEmail['id'];
                    error_log(sprintf('[TDesk Info] create_ticket_record: user_id corrigido para %d usando email', $userId));
                } else {
                    error_log(sprintf('[TDesk Error] create_ticket_record: usuário não encontrado por ID nem por email. User: %s', json_encode($user)));
                    throw new RuntimeException('Usuário não encontrado no banco de dados. Faça login novamente.');
                }
            } else {
                error_log(sprintf('[TDesk Error] create_ticket_record: usuário não encontrado e email não disponível. User ID: %d', $userId));
                throw new RuntimeException('Usuário não encontrado no banco de dados. Faça login novamente.');
            }
        }
    } catch (PDOException $e) {
        error_log(sprintf('[TDesk Error] create_ticket_record: erro ao verificar usuário. Erro: %s, User ID: %d', $e->getMessage(), $userId));
        // Se for erro de SQL, tentar continuar (a foreign key vai validar)
        if (strpos($e->getMessage(), 'SQLSTATE') !== false) {
            error_log('[TDesk Warning] create_ticket_record: erro SQL ao verificar usuário, mas continuando...');
        } else {
            throw new RuntimeException('Erro ao verificar usuário. Tente novamente.');
        }
    } catch (RuntimeException $e) {
        // Re-lançar exceções de RuntimeException
        throw $e;
    }
    
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO tickets (user_id, title, category, priority, status, description)
             VALUES (:user_id, :title, :category, :priority, :status, :description)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'title' => $input['title'],
            'category' => $input['category'],
            'priority' => $input['priority'],
            'status' => 'open',
            'description' => $input['description'],
        ]);

        $ticketId = (int)$pdo->lastInsertId();
        $actorId = isset($user['id']) ? (int)$user['id'] : null;
        log_ticket_activity($pdo, $ticketId, $actorId, 'created', null, 'open', 'Chamado criado');

        // Processar anexos se existirem
        if (!empty($input['attachments'])) {
            save_ticket_attachments($pdo, $ticketId, $input['attachments']);
        }

        $pdo->commit();
        return $ticketId;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function update_ticket_status(array $user, int $ticketId, string $newStatus, string $note = ''): void
{
    if (!in_array($user['role'], ['admin', 'support'], true)) {
        throw new RuntimeException('Você não tem permissão para atualizar chamados. Apenas administradores e suporte podem alterar status.');
    }

    $newStatus = strtolower(trim($newStatus));
    if (!in_array($newStatus, TDESK_STATUSES, true)) {
        throw new InvalidArgumentException('Status informado é inválido. Status válidos: ' . implode(', ', TDESK_STATUSES));
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT status FROM tickets WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $ticketId]);
        $current = $stmt->fetch();

        if (!$current) {
            throw new RuntimeException('Chamado não encontrado.');
        }

        // Se o status já é o mesmo, não fazer nada
        if ($current['status'] === $newStatus) {
            $pdo->rollBack();
            return;
        }

        $stmt = $pdo->prepare('UPDATE tickets SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['status' => $newStatus, 'id' => $ticketId]);

        // Garantir que o ID do usuário é um inteiro válido
        $actorId = isset($user['id']) ? (int)$user['id'] : null;
        
        log_ticket_activity(
            $pdo,
            $ticketId,
            $actorId,
            'status_update',
            $current['status'],
            $newStatus,
            $note ?: 'Status atualizado de ' . $current['status'] . ' para ' . $newStatus
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function assign_ticket_to_agent(array $user, int $ticketId, int $agentId): void
{
    if ($user['role'] !== 'admin') {
        throw new RuntimeException('Somente administradores podem atribuir chamados.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id AND role IN (\'admin\', \'support\')');
        $stmt->execute(['id' => $agentId]);
        if (!$stmt->fetch()) {
            throw new RuntimeException('Técnico inválido.');
        }

        $stmt = $pdo->prepare('UPDATE tickets SET assigned_to = :assigned_to, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['assigned_to' => $agentId, 'id' => $ticketId]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Chamado não encontrado.');
        }

        $actorId = isset($user['id']) ? (int)$user['id'] : null;
        log_ticket_activity($pdo, $ticketId, $actorId, 'assignment', null, null, 'Chamado atribuído');

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function build_ticket_scope(array $user, string $alias = 't'): array
{
    switch ($user['role']) {
        case 'admin':
            return ['', []];
        case 'support':
            return [
                "WHERE ({$alias}.assigned_to IS NULL OR {$alias}.assigned_to = :assigned_id)",
                [':assigned_id' => $user['id']],
            ];
        default:
            return [
                "WHERE {$alias}.user_id = :owner_id",
                [':owner_id' => $user['id']],
            ];
    }
}

function validate_priority(string $priority): void
{
    if (!in_array($priority, TDESK_PRIORITIES, true)) {
        throw new InvalidArgumentException('Prioridade inválida.');
    }
}

/**
 * Busca detalhes completos de um chamado
 */
function fetch_ticket_details(array $user, int $ticketId): ?array
{
    $pdo = db();
    
    try {
        // Verificar permissão de acesso
        [$where, $params] = build_ticket_scope($user);
        
        // Garantir que $params é um array
        if (!is_array($params)) {
            $params = [];
        }
        
        // Adicionar o ID do chamado aos parâmetros
        $params[':ticket_id'] = $ticketId;
        
        // Construir a cláusula WHERE corretamente
        if ($where === '') {
            $scopeClause = 'WHERE t.id = :ticket_id';
        } else {
            // Se já tem WHERE, adicionar AND
            $scopeClause = $where . ' AND t.id = :ticket_id';
        }
        
        $sql = <<<SQL
            SELECT
                t.id,
                t.user_id,
                t.assigned_to,
                t.title,
                t.category,
                t.priority,
                t.status,
                t.description,
                t.created_at,
                t.updated_at,
                creator.name AS requester,
                creator.email AS requester_email,
                assignee.name AS assignee_name,
                assignee.email AS assignee_email
            FROM tickets t
            INNER JOIN users creator ON creator.id = t.user_id
            LEFT JOIN users assignee ON assignee.id = t.assigned_to
            {$scopeClause}
            LIMIT 1
        SQL;
        
        $stmt = $pdo->prepare($sql);
        
        // Executar com os parâmetros corretos
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        
        $stmt->execute();
        $ticket = $stmt->fetch();
        
        if (!$ticket) {
            return null;
        }
        
        // Buscar anexos (se a tabela existir)
        try {
            $stmt = $pdo->prepare('SELECT id, original_name, stored_name, file_path, file_size, mime_type, created_at FROM ticket_attachments WHERE ticket_id = :ticket_id ORDER BY created_at ASC');
            $stmt->execute(['ticket_id' => $ticketId]);
            $ticket['attachments'] = $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            // Se a tabela não existir, retornar array vazio
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                $ticket['attachments'] = [];
            } else {
                throw $e;
            }
        }
        
        // Buscar atividades
        $stmt = $pdo->prepare('SELECT a.*, actor.name AS actor_name FROM ticket_activity a LEFT JOIN users actor ON actor.id = a.actor_id WHERE a.ticket_id = :ticket_id ORDER BY a.created_at DESC LIMIT 20');
        $stmt->execute(['ticket_id' => $ticketId]);
        $ticket['activities'] = $stmt->fetchAll() ?: [];
        
        // Buscar respostas do suporte (se a tabela existir)
        try {
            $stmt = $pdo->prepare(
                'SELECT r.id, r.response_text, r.created_at, u.name AS responder_name, u.email AS responder_email
                 FROM ticket_responses r
                 INNER JOIN users u ON u.id = r.user_id
                 WHERE r.ticket_id = :ticket_id
                 ORDER BY r.created_at ASC'
            );
            $stmt->execute(['ticket_id' => $ticketId]);
            $ticket['responses'] = $stmt->fetchAll() ?: [];
            
            // Buscar anexos das respostas
            if (!empty($ticket['responses'])) {
                foreach ($ticket['responses'] as &$response) {
                    try {
                        $stmt = $pdo->prepare(
                            'SELECT id, original_name, stored_name, file_path, file_size, mime_type, created_at
                             FROM ticket_response_attachments
                             WHERE response_id = :response_id
                             ORDER BY created_at ASC'
                        );
                        $stmt->execute(['response_id' => $response['id']]);
                        $response['attachments'] = $stmt->fetchAll() ?: [];
                    } catch (PDOException $e) {
                        $response['attachments'] = [];
                    }
                }
                unset($response);
            }
        } catch (PDOException $e) {
            // Se a tabela não existir, retornar array vazio
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                $ticket['responses'] = [];
            } else {
                error_log(sprintf('[TDesk Warning] Erro ao buscar respostas: %s', $e->getMessage()));
                $ticket['responses'] = [];
            }
        }
        
        return $ticket;
    } catch (PDOException $e) {
        error_log(sprintf('[TDesk SQL Error] %s - SQL: %s - Params: %s', $e->getMessage(), $sql ?? 'N/A', json_encode($params ?? [])));
        throw new RuntimeException('Erro ao buscar detalhes do chamado: ' . $e->getMessage());
    } catch (Throwable $e) {
        error_log(sprintf('[TDesk Error] %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        throw $e;
    }
}

/**
 * Atualiza um chamado
 */
function update_ticket(array $user, int $ticketId, array $data): void
{
    $pdo = db();
    
    // Verificar permissão
    $ticket = fetch_ticket_details($user, $ticketId);
    if (!$ticket) {
        throw new RuntimeException('Chamado não encontrado ou você não tem permissão para editá-lo.');
    }
    
    // Verificar se pode editar (admin, suporte atribuído, ou dono do chamado)
    $canEdit = false;
    if ($user['role'] === 'admin') {
        $canEdit = true;
    } elseif ($user['role'] === 'support' && $ticket['assigned_to'] == $user['id']) {
        $canEdit = true;
    } elseif ($ticket['user_id'] == $user['id']) {
        $canEdit = true;
    }
    
    if (!$canEdit) {
        throw new RuntimeException('Você não tem permissão para editar este chamado.');
    }
    
    $pdo->beginTransaction();
    
    try {
        $updates = [];
        $params = [':id' => $ticketId];
        
        if (isset($data['title'])) {
            $updates[] = 'title = :title';
            $params[':title'] = sanitize($data['title']);
        }
        
        if (isset($data['category'])) {
            $updates[] = 'category = :category';
            $params[':category'] = sanitize($data['category']);
        }
        
        if (isset($data['priority'])) {
            validate_priority($data['priority']);
            $updates[] = 'priority = :priority';
            $params[':priority'] = $data['priority'];
        }
        
        if (isset($data['description'])) {
            $updates[] = 'description = :description';
            $params[':description'] = sanitize(trim($data['description']));
        }
        
        if (empty($updates)) {
            throw new InvalidArgumentException('Nenhum campo para atualizar.');
        }
        
        $updates[] = 'updated_at = NOW()';
        
        $sql = 'UPDATE tickets SET ' . implode(', ', $updates) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $actorId = isset($user['id']) ? (int)$user['id'] : null;
        log_ticket_activity($pdo, $ticketId, $actorId, 'updated', null, null, 'Chamado atualizado');
        
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * Exclui um chamado
 */
function delete_ticket(array $user, int $ticketId): void
{
    $pdo = db();
    
    // Verificar permissão
    $ticket = fetch_ticket_details($user, $ticketId);
    if (!$ticket) {
        throw new RuntimeException('Chamado não encontrado ou você não tem permissão para excluí-lo.');
    }
    
    // Apenas admin ou dono do chamado pode excluir
    if ($user['role'] !== 'admin' && $ticket['user_id'] != $user['id']) {
        throw new RuntimeException('Você não tem permissão para excluir este chamado.');
    }
    
    $pdo->beginTransaction();
    
    try {
        // Excluir anexos físicos
        $stmt = $pdo->prepare('SELECT file_path FROM ticket_attachments WHERE ticket_id = :ticket_id');
        $stmt->execute(['ticket_id' => $ticketId]);
        $attachments = $stmt->fetchAll();
        
        foreach ($attachments as $attachment) {
            $filePath = __DIR__ . '/../public/' . $attachment['file_path'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        
        // Excluir do banco (CASCADE vai excluir atividades e anexos automaticamente)
        $stmt = $pdo->prepare('DELETE FROM tickets WHERE id = :id');
        $stmt->execute(['id' => $ticketId]);
        
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

/**
 * Adiciona uma resposta do suporte a um chamado
 */
function add_ticket_response(array $user, int $ticketId, string $responseText, array $attachments = []): void
{
    if (!in_array($user['role'], ['admin', 'support'], true)) {
        throw new RuntimeException('Apenas administradores e suporte podem adicionar respostas.');
    }
    
    // Validar que o user_id existe
    $userId = isset($user['id']) ? (int)$user['id'] : null;
    if ($userId === null || $userId <= 0) {
        throw new RuntimeException('ID de usuário inválido. Faça login novamente.');
    }
    
    $pdo = db();
    
    // Verificar se o chamado existe
    $stmt = $pdo->prepare('SELECT id FROM tickets WHERE id = :id');
    $stmt->execute(['id' => $ticketId]);
    if (!$stmt->fetch()) {
        throw new RuntimeException('Chamado não encontrado.');
    }
    
    // Verificar se o usuário existe no banco
    // Se não existir, tentar buscar pelo email da sessão
    try {
        $checkStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $checkStmt->execute(['id' => $userId]);
        $userExists = $checkStmt->fetch();
        if (!$userExists) {
            error_log(sprintf('[TDesk Warning] add_ticket_response: usuário com ID %d não encontrado. Tentando buscar por email...', $userId));
            // Tentar buscar pelo email se disponível
            if (isset($user['email']) && !empty($user['email'])) {
                $emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $emailStmt->execute(['email' => $user['email']]);
                $userByEmail = $emailStmt->fetch();
                if ($userByEmail) {
                    $userId = (int)$userByEmail['id'];
                    error_log(sprintf('[TDesk Info] add_ticket_response: user_id corrigido para %d usando email', $userId));
                } else {
                    error_log(sprintf('[TDesk Error] add_ticket_response: usuário não encontrado por ID nem por email. User: %s', json_encode($user)));
                    throw new RuntimeException('Usuário não encontrado no banco de dados. Faça login novamente.');
                }
            } else {
                error_log(sprintf('[TDesk Error] add_ticket_response: usuário não encontrado e email não disponível. User ID: %d', $userId));
                throw new RuntimeException('Usuário não encontrado no banco de dados. Faça login novamente.');
            }
        }
    } catch (PDOException $e) {
        error_log(sprintf('[TDesk Error] add_ticket_response: erro ao verificar usuário. Erro: %s, User ID: %d', $e->getMessage(), $userId));
        // Se for erro de SQL, tentar continuar (a foreign key vai validar)
        if (strpos($e->getMessage(), 'SQLSTATE') !== false) {
            error_log('[TDesk Warning] add_ticket_response: erro SQL ao verificar usuário, mas continuando...');
        } else {
            throw new RuntimeException('Erro ao verificar usuário. Tente novamente.');
        }
    } catch (RuntimeException $e) {
        // Re-lançar exceções de RuntimeException
        throw $e;
    }
    
    $pdo->beginTransaction();
    
    try {
        // Inserir resposta
        $responseTextClean = sanitize(trim($responseText));
        error_log(sprintf('[TDesk Debug] add_ticket_response: inserindo resposta - ticket_id: %d, user_id: %d, response length: %d', 
            $ticketId, $userId, strlen($responseTextClean)));
        
        $stmt = $pdo->prepare(
            'INSERT INTO ticket_responses (ticket_id, user_id, response_text)
             VALUES (:ticket_id, :user_id, :response_text)'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'response_text' => $responseTextClean,
        ]);
        
        $responseId = (int)$pdo->lastInsertId();
        error_log(sprintf('[TDesk Debug] add_ticket_response: resposta inserida com sucesso - response_id: %d', $responseId));
        
        // Salvar anexos da resposta se existirem
        if (!empty($attachments)) {
            $stmt = $pdo->prepare(
                'INSERT INTO ticket_response_attachments (response_id, original_name, stored_name, file_path, file_size, mime_type)
                 VALUES (:response_id, :original_name, :stored_name, :file_path, :file_size, :mime_type)'
            );
            
            foreach ($attachments as $attachment) {
                $stmt->execute([
                    'response_id' => $responseId,
                    'original_name' => $attachment['original_name'],
                    'stored_name' => $attachment['stored_name'],
                    'file_path' => $attachment['file_path'],
                    'file_size' => $attachment['file_size'],
                    'mime_type' => $attachment['mime_type'],
                ]);
            }
        }
        
        // Atualizar timestamp do chamado
        $stmt = $pdo->prepare('UPDATE tickets SET updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $ticketId]);
        
        // Registrar atividade
        $actorId = isset($user['id']) ? (int)$user['id'] : null;
        log_ticket_activity($pdo, $ticketId, $actorId, 'response_added', null, null, 'Resposta do suporte adicionada');
        
        $pdo->commit();
        error_log(sprintf('[TDesk Debug] add_ticket_response: transação commitada com sucesso - response_id: %d', $responseId));
    } catch (Throwable $exception) {
        $pdo->rollBack();
        error_log(sprintf('[TDesk Error] add_ticket_response: erro ao salvar resposta - %s: %s em %s:%d', 
            get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));
        throw $exception;
    }
}

function validate_password_strength(string $password): array
{
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'A senha deve ter pelo menos 8 caracteres.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'A senha deve conter pelo menos uma letra minúscula.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'A senha deve conter pelo menos uma letra maiúscula.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'A senha deve conter pelo menos um número.';
    }
    return $errors;
}

function validate_name(string $name): bool
{
    return (bool)preg_match('/^[a-zA-ZÀ-ÿ\s]{3,100}$/u', trim($name));
}

function can_modify_ticket(array $user, int $ticketId): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }
    
    $stmt = db()->prepare('SELECT user_id, assigned_to FROM tickets WHERE id = :id');
    $stmt->execute(['id' => $ticketId]);
    $ticket = $stmt->fetch();
    
    if (!$ticket) {
        return false;
    }
    
    return (int)$ticket['user_id'] === $user['id'] 
        || ($user['role'] === 'support' && (int)$ticket['assigned_to'] === $user['id']);
}

function log_ticket_activity(PDO $pdo, int $ticketId, ?int $actorId, string $action, ?string $fromStatus, ?string $toStatus, ?string $details = null): void
{
    try {
        // Verificar se o actor_id existe na tabela users (se não for NULL)
        $validActorId = null;
        if ($actorId !== null && $actorId > 0) {
            $checkStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
            $checkStmt->execute(['id' => $actorId]);
            $userExists = $checkStmt->fetch();
            if ($userExists) {
                $validActorId = $actorId;
            } else {
                // Se o usuário não existir, usar NULL e logar aviso
                error_log(sprintf('[TDesk Warning] Usuário com ID %d não encontrado ao registrar atividade. Ticket ID: %d, Action: %s', $actorId, $ticketId, $action));
                $validActorId = null;
            }
        }
        
        $stmt = $pdo->prepare(
            'INSERT INTO ticket_activity (ticket_id, actor_id, action, from_status, to_status, details)
             VALUES (:ticket_id, :actor_id, :action, :from_status, :to_status, :details)'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'actor_id' => $validActorId,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'details' => $details,
        ]);
    } catch (PDOException $e) {
        // Se a tabela não existir, apenas logar o erro mas não falhar a operação principal
        if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Table") !== false) {
            error_log(sprintf('[TDesk Warning] Tabela ticket_activity não encontrada. Atividade não foi registrada. Ticket ID: %d, Action: %s', $ticketId, $action));
            // Não relançar a exceção - a operação principal foi bem-sucedida, apenas o log não foi salvo
        } elseif (strpos($e->getMessage(), "foreign key constraint") !== false || strpos($e->getMessage(), "1452") !== false) {
            // Erro de chave estrangeira - tentar novamente com actor_id NULL
            error_log(sprintf('[TDesk Warning] Erro de chave estrangeira ao registrar atividade. Tentando com actor_id NULL. Ticket ID: %d, Action: %s, Actor ID: %s', $ticketId, $action, $actorId ?? 'NULL'));
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO ticket_activity (ticket_id, actor_id, action, from_status, to_status, details)
                     VALUES (:ticket_id, :actor_id, :action, :from_status, :to_status, :details)'
                );
                $stmt->execute([
                    'ticket_id' => $ticketId,
                    'actor_id' => null,
                    'action' => $action,
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'details' => $details,
                ]);
            } catch (PDOException $e2) {
                error_log(sprintf('[TDesk Error] Falha ao registrar atividade mesmo com actor_id NULL. Ticket ID: %d, Action: %s, Erro: %s', $ticketId, $action, $e2->getMessage()));
                // Não relançar - a operação principal foi bem-sucedida
            }
        } else {
            // Outros erros de banco devem ser relançados
            throw $e;
        }
    }
}

