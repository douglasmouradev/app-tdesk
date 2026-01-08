# TDesk Solutions

Plataforma web para gestão de chamados construída em PHP 8.5 e MySQL 9.4+.

## Requisitos

- PHP 8.3+ (recomendado 8.5+) com extensões `pdo_mysql`, `openssl`, `mbstring`
- MySQL 8.1+ (recomendado 9.4+)

## Instalação Rápida

### 1. Configurar variáveis de ambiente

Copie o arquivo de exemplo e configure:

```bash
cp .env.example .env
nano .env  # Edite com suas credenciais
```

**Configure pelo menos:**
- `DB_PASSWORD` - Sua senha do MySQL
- `DB_USERNAME` - Seu usuário MySQL (padrão: root)

### 2. Criar o banco de dados

```bash
mysql -u root -p < database/apptdesk.sql
```

### 3. Popular dados iniciais

```bash
php scripts/seed.php
```

### 4. Iniciar servidor

```bash
php -S localhost:8080 -t public
```

### 5. Acessar

Abra `http://localhost:8080` no navegador.

📖 **Para guia completo de testes, veja:** [TESTE_LOCAL.md](TESTE_LOCAL.md)

## Credenciais Padrão

| Perfil | E-mail | Senha |
|--------|--------|-------|
| Admin | `admin@tdesk.local` | `Admin@123` |
| Suporte | `suporte@tdesk.local` | `Suporte@123` |
| Cliente | `cliente@tdesk.local` | `Cliente@123` |

## Setup Automático

Execute o script de setup:

```bash
./setup.sh
```

## Estrutura do Projeto

```
config/            # Configurações
database/          # Scripts SQL
public/            # Raiz pública (login, dashboard, APIs, assets)
src/               # Helpers, bootstrap e serviços
scripts/           # Scripts utilitários
```

## Recursos

- Autenticação com níveis de permissão (admin, suporte, cliente)
- Painel com indicadores em tempo real e gráficos interativos
- Gestão completa de chamados (criar, atualizar, atribuir)
- Registro de atividades e trilha de auditoria
- Exportação de relatórios (Excel e PDF)
- Proteções de segurança (CSRF, rate limiting, validações)

## Segurança

A aplicação inclui:
- Proteção CSRF em todos os formulários
- Rate limiting para autenticação
- Validação de entrada rigorosa
- Sanitização de dados
- Headers de segurança HTTP
- Sessões seguras

## Deploy em Produção

### VPS (Virtual Private Server)

Para hospedar em uma VPS, consulte os guias:

📖 **[CONFIGURACAO_VPS.md](CONFIGURACAO_VPS.md)** - Configuração rápida para VPS `62.72.63.161`  
📖 **[DEPLOY_VPS.md](DEPLOY_VPS.md)** - Guia completo passo a passo  
📖 **[TROUBLESHOOTING_SSL.md](TROUBLESHOOTING_SSL.md)** - Solução de problemas SSL

**Resumo rápido:**
1. Conecte via SSH à VPS
2. Instale PHP 8.3+, MySQL e Nginx/Apache
3. Faça upload dos arquivos para `/var/www/tdesk`
4. Configure `.env` com credenciais de produção
5. Execute `sudo ./deploy-vps.sh` no servidor
6. Configure Nginx/Apache
7. Configure SSL (HTTPS) com Let's Encrypt
8. Configure firewall

**Scripts disponíveis:**
- `deploy-vps.sh` - Script automatizado de deploy
- `DEPLOY_VPS.md` - Guia detalhado passo a passo

