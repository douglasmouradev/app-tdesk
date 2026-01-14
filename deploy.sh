#!/bin/bash

# Script de deploy para VPS Hostinger
# Execute no servidor após fazer upload dos arquivos

set -e

echo "🚀 Configurando TDesk Solutions na VPS..."
echo ""

# Verificar se está como root ou com sudo
if [ "$EUID" -ne 0 ]; then 
    echo "⚠️  Execute com sudo: sudo ./deploy.sh"
    exit 1
fi

# Diretório da aplicação
APP_DIR="/var/www/tdesk"

# Verificar se diretório existe
if [ ! -d "$APP_DIR" ]; then
    echo "❌ Diretório $APP_DIR não encontrado!"
    echo "   Faça upload dos arquivos primeiro"
    exit 1
fi

cd $APP_DIR

# Configurar Git para permitir o diretório (resolver "dubious ownership")
if [ -d ".git" ]; then
    echo "🔧 Configurando Git..."
    git config --global --add safe.directory $APP_DIR 2>/dev/null || true
fi

# Configurar permissões
echo "📁 Configurando permissões..."
chown -R www-data:www-data .
chmod -R 755 .
chmod -R 775 public/
chmod 600 .env 2>/dev/null || echo "   .env ainda não existe (será criado)"

# Criar diretório .well-known para Let's Encrypt (ACME Challenge)
echo "🔐 Criando diretório .well-known para SSL..."
mkdir -p public/.well-known/acme-challenge
chown -R www-data:www-data public/.well-known
chmod -R 755 public/.well-known
echo "   ✅ Diretório .well-known/acme-challenge criado"

# Verificar .env
if [ ! -f .env ]; then
    echo "⚠️  Arquivo .env não encontrado!"
    if [ -f "env.template" ]; then
        echo "   Copiando env.template..."
        cp env.template .env
    else
        echo "   Criando arquivo .env básico..."
        cat > .env << 'ENVFILE'
APP_NAME="TDesk Solutions"
APP_TIMEZONE="America/Sao_Paulo"
APP_URL="https://app.tdesksolutions.com.br"
APP_ENV="production"
APP_DEBUG="false"
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME="tdesk_solutions"
DB_USERNAME="root"
DB_PASSWORD=""
DB_CHARSET="utf8mb4"
SESSION_NAME="tdesk_session"
PASSWORD_ALGO="PASSWORD_DEFAULT"
APP_KEY=""
CSRF_TOKEN_EXPIRY="3600"
MAIL_FROM="no-reply@tdesksolutions.com.br"
MAIL_HOST=""
MAIL_PORT="587"
MAIL_USERNAME=""
MAIL_PASSWORD=""
MAIL_ENCRYPTION="tls"
ENVFILE
    fi
    chmod 600 .env
    echo "   ⚠️  Configure o arquivo .env antes de continuar!"
    echo "   nano $APP_DIR/.env"
    exit 1
fi

# Verificar PHP
echo "🔍 Verificando PHP..."
if ! command -v php &> /dev/null; then
    echo "❌ PHP não encontrado. Instale PHP 8.3+ primeiro"
    exit 1
fi

# Verificar extensões
REQUIRED_EXTENSIONS=("pdo_mysql" "openssl" "mbstring")
for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if ! php -m | grep -q "^${ext}$"; then
        echo "⚠️  Extensão $ext não encontrada"
    fi
done

# Testar conexão com banco
echo "🔍 Testando conexão com banco..."
php -r "
require 'src/env.php';
require 'src/bootstrap.php';
try {
    \$db = db();
    echo '✅ Conexão com banco OK!' . PHP_EOL;
} catch (Exception \$e) {
    echo '❌ Erro: ' . \$e->getMessage() . PHP_EOL;
    exit(1);
}
"

# Popular dados iniciais (se necessário)
echo ""
read -p "Deseja popular dados iniciais? (s/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Ss]$ ]]; then
    php scripts/seed.php
fi

echo ""
echo "✅ Configuração concluída!"
echo ""
echo "📋 Próximos passos:"
echo "   1. Configure o Nginx/Apache"
echo "   2. Configure SSL (HTTPS)"
echo "   3. Configure firewall"
echo "   4. Acesse: https://seu-dominio.com"
echo ""

