-- Script para criar usuário admin: douglas@tdesksolutions.com.br
-- Execute: mysql -u root -p tdesk_solutions < scripts/create-douglas-admin.sql

USE tdesk_solutions;

-- Verificar se usuário já existe e remover se necessário
DELETE FROM users WHERE email = 'douglas@tdesksolutions.com.br';

-- Criar usuário admin
-- Senha: Titanium@10 (hash gerado)
INSERT INTO users (name, email, password_hash, role) 
VALUES (
    'Douglas',
    'douglas@tdesksolutions.com.br',
    '$2y$12$vBpCsK6eQ5NqErarRsEx9emUmYXj.FrjuBJvcrVfu8AegQ1b2r6Ei', -- Hash de 'Titanium@10'
    'admin'
);

-- Verificar criação
SELECT id, name, email, role, created_at FROM users WHERE email = 'douglas@tdesksolutions.com.br';

