-- MySQL 9.4+ schema for TDesk Solutions (compatível com MySQL 8.1+)
-- Script completo: cria banco, tabelas e dados iniciais

-- ============================================
-- PARTE 1: CRIAR BANCO DE DADOS
-- ============================================
CREATE DATABASE IF NOT EXISTS tdesk_solutions
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE tdesk_solutions;

-- ============================================
-- PARTE 2: CRIAR TABELAS
-- ============================================

-- Desabilitar verificação de foreign keys temporariamente para evitar problemas
SET FOREIGN_KEY_CHECKS = 0;

-- Remover tabelas se existirem (para recriação limpa)
DROP TABLE IF EXISTS ticket_responses;
DROP TABLE IF EXISTS ticket_response_attachments;
DROP TABLE IF EXISTS ticket_attachments;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS ticket_activity;
DROP TABLE IF EXISTS tickets;
DROP TABLE IF EXISTS users;

-- Criar tabela users primeiro (sem dependências)
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'support', 'client') NOT NULL DEFAULT 'client',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Criar tabela tickets (depende de users)
CREATE TABLE tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    assigned_to BIGINT UNSIGNED NULL,
    title VARCHAR(160) NOT NULL,
    category VARCHAR(100) NOT NULL,
    priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    status ENUM('open', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'open',
    description TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tickets_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Criar tabela ticket_activity (depende de tickets e users)
CREATE TABLE ticket_activity (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(60) NOT NULL,
    from_status ENUM('open', 'in_progress', 'resolved', 'closed') NULL,
    to_status ENUM('open', 'in_progress', 'resolved', 'closed') NULL,
    details VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_activity_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Criar tabela password_resets (depende de users)
CREATE TABLE password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    selector CHAR(32) NOT NULL UNIQUE,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Criar tabela ticket_attachments (depende de tickets)
CREATE TABLE ticket_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attachment_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Criar tabela ticket_responses (depende de tickets e users)
CREATE TABLE ticket_responses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    response_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_response_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_response_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Criar tabela ticket_response_attachments (depende de ticket_responses)
CREATE TABLE ticket_response_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    response_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_response_attachment_response FOREIGN KEY (response_id) REFERENCES ticket_responses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reabilitar verificação de foreign keys
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- PARTE 3: DADOS INICIAIS (USUÁRIOS PADRÃO)
-- ============================================

-- Inserir usuários padrão para testes
-- Senhas: Admin@123, Cliente@123, Suporte@123
-- Nota: Os hashes abaixo são placeholders. Execute o script seed.php para criar usuários com senhas corretas,
-- ou gere novos hashes com: php -r "echo password_hash('SENHA', PASSWORD_DEFAULT);"

-- Remover usuários padrão se já existirem (para recriação limpa)
DELETE FROM users WHERE email IN ('admin@tdesk.local', 'cliente@tdesk.local', 'suporte@tdesk.local', 'douglas@tdesksolutions.com.br');

-- Inserir usuários padrão
-- Nota: Os hashes são gerados dinamicamente. Para garantir senhas corretas, 
-- execute: php scripts/seed.php após criar o banco, ou gere novos hashes com:
-- php -r "echo password_hash('SENHA', PASSWORD_DEFAULT);"

INSERT INTO users (name, email, password_hash, role) VALUES
('Administrador TDesk', 'admin@tdesk.local', '$2y$12$s4yOTeJzhIZ52pfQUJDluuQ1aFPZAFSwgLRyFZSkTTdBOaA5hxBbK', 'admin'),
('Cliente TDesk', 'cliente@tdesk.local', '$2y$12$N64LiExKVVbgfG8A9IL2rOEE0FiZBEpCNAEcREIjR9aC2yK84QYfu', 'client'),
('Suporte Nível 1', 'suporte@tdesk.local', '$2y$12$t.NjVi/BqmuVETKpiCV5iuaXDQMGBxwmp8AUv7kCbKvlED35xK5am', 'support'),
('Douglas', 'douglas@tdesksolutions.com.br', '$2y$12$vBpCsK6eQ5NqErarRsEx9emUmYXj.FrjuBJvcrVfu8AegQ1b2r6Ei', 'admin');

-- ============================================
-- FIM DO SCRIPT
-- ============================================
-- 
-- Para executar este script:
-- mysql -u root -p < database/apptdesk.sql
-- 
-- OU se não precisar de senha:
-- mysql -u root < database/apptdesk.sql
-- 
-- Credenciais padrão após execução:
-- Admin: admin@tdesk.local / Admin@123
-- Cliente: cliente@tdesk.local / Cliente@123
-- Suporte: suporte@tdesk.local / Suporte@123
