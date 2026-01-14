#!/bin/bash

# Script de deploy para aaPanel
# Execute no servidor após criar o site no aaPanel

set -e

echo "🚀 Configurando TDesk Solutions no aaPanel..."
echo ""

# Verificar se está como root ou com sudo
if [ "$EUID" -ne 0 ]; then 
    echo "⚠️  Execute com sudo: sudo ./deploy-aapanel.sh"
    exit 1
fi

# Diretório padrão do aaPanel
AAPANEL_DIR="/www/wwwroot/app.tdesksolutions.com.br"
SOURCE_DIR="/var/www/tdesk"

# Verificar se o diretório do aaPanel existe
if [ ! -d "$AAPANEL_DIR" ]; then
    echo "❌ Diretório $AAPANEL_DIR não encontrado!"
    echo "   Crie o site no aaPanel primeiro:"
    echo "   - Nome: app.tdesksolutions.com.br"
    echo "   - Domínio: app.tdesksolutions.com.br"
    echo "   - Document Root: $AAPANEL_DIR/public"
    echo "   - PHP: 7.4 ou superior"
    exit 1
fi

# Se os arquivos estão em /var/www/tdesk, copiar para o aaPanel
if [ -d "$SOURCE_DIR" ] && [ "$SOURCE_DIR" != "$AAPANEL_DIR" ]; then
    echo "📦 Copiando arquivos de $SOURCE_DIR para $AAPANEL_DIR..."
    rsync -av --exclude='.git' --exclude='node_modules' "$SOURCE_DIR/" "$AAPANEL_DIR/"
    echo "   ✅ Arquivos copiados"
fi

cd $AAPANEL_DIR

# Se não tem arquivos, fazer clone do GitHub
if [ ! -f "dashboard.php" ] && [ ! -f "public/index.php" ]; then
    echo "📥 Fazendo clone do repositório..."
    if [ -d ".git" ]; then
        git pull origin main || true
    else
        git clone https://github.com/douglasmouradev/help-desk-tdesk.git . || {
            echo "❌ Erro ao fazer clone. Verifique se o repositório existe."
            exit 1
        }
    fi
fi

# Configurar Git para permitir o diretório (resolver "dubious ownership")
if [ -d ".git" ]; then
    echo "🔧 Configurando Git..."
    git config --global --add safe.directory $AAPANEL_DIR 2>/dev/null || true
fi

# Configurar permissões (aaPanel usa www:www)
echo "📁 Configurando permissões..."
chown -R www:www .
chmod -R 755 .
chmod -R 775 public/
chmod 600 .env 2>/dev/null || echo "   .env ainda não existe (será criado)"

# Criar diretório .well-known para Let's Encrypt (ACME Challenge)
echo "🔐 Criando diretório .well-known para SSL..."
mkdir -p public/.well-known/acme-challenge
chown -R www:www public/.well-known
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
    echo "   nano $AAPANEL_DIR/.env"
    echo ""
    echo "   IMPORTANTE: Configure DB_PASSWORD se o root do MySQL tem senha"
    exit 1
fi

# Verificar PHP
echo "🔍 Verificando PHP..."
if ! command -v php &> /dev/null; then
    echo "❌ PHP não encontrado. Instale PHP 7.4+ no aaPanel primeiro"
    exit 1
fi

# Verificar extensões
REQUIRED_EXTENSIONS=("pdo_mysql" "openssl" "mbstring")
for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if ! php -m | grep -q "^${ext}$"; then
        echo "⚠️  Extensão $ext não encontrada. Instale no aaPanel: Site → PHP → Extensões"
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
    echo '   Verifique DB_HOST, DB_NAME, DB_USERNAME e DB_PASSWORD no .env' . PHP_EOL;
    exit(1);
}
"

# Importar banco de dados se não existir
echo ""
read -p "Deseja importar o banco de dados? (s/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Ss]$ ]]; then
    if [ -f "database/apptdesk.sql" ]; then
        echo "📥 Importando banco de dados..."
        mysql -u root tdesk_solutions < database/apptdesk.sql 2>/dev/null || {
            echo "⚠️  Erro ao importar. Tente manualmente:"
            echo "   mysql -u root -p tdesk_solutions < database/apptdesk.sql"
        }
        echo "   ✅ Banco importado"
    else
        echo "⚠️  Arquivo database/apptdesk.sql não encontrado"
    fi
fi

echo ""
echo "✅ Configuração concluída!"
echo ""
echo "📋 Próximos passos:"
echo "   1. Configure SSL no aaPanel: Site → app.tdesksolutions.com.br → SSL"
echo "   2. Acesse: https://app.tdesksolutions.com.br"
echo "   3. Faça login com usuário admin criado no banco"
echo ""
