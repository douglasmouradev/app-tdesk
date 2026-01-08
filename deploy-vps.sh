#!/bin/bash

# Script de Deploy Automatizado para VPS
# Execute este script na VPS após fazer upload dos arquivos

set -e

echo "🚀 TDesk Solutions - Script de Deploy"
echo "======================================"
echo ""

# Verificar se está como root ou com sudo
if [ "$EUID" -ne 0 ]; then 
    echo "⚠️  Execute com sudo: sudo ./deploy-vps.sh"
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

echo "📁 Configurando permissões..."
chown -R www-data:www-data $APP_DIR
chmod -R 755 $APP_DIR
chmod -R 775 $APP_DIR/public/uploads 2>/dev/null || mkdir -p $APP_DIR/public/uploads && chmod -R 775 $APP_DIR/public/uploads

# Verificar .env
if [ ! -f .env ]; then
    echo ""
    echo "⚠️  Arquivo .env não encontrado!"
    if [ -f .env.example ]; then
        echo "   Copiando .env.example..."
        cp .env.example .env
        chmod 600 .env
        echo ""
        echo "   ⚠️  Configure o arquivo .env antes de continuar!"
        echo "   nano $APP_DIR/.env"
        echo ""
        echo "   Variáveis obrigatórias:"
        echo "   - DB_HOST"
        echo "   - DB_NAME"
        echo "   - DB_USERNAME"
        echo "   - DB_PASSWORD"
        echo ""
        exit 1
    else
        echo "   ❌ Arquivo .env.example também não encontrado!"
        exit 1
    fi
fi

chmod 600 .env

# Verificar PHP
echo "🔍 Verificando PHP..."
if ! command -v php &> /dev/null; then
    echo "❌ PHP não encontrado. Instale PHP 8.3+ primeiro"
    exit 1
fi

PHP_VERSION=$(php -r "echo PHP_VERSION;" | cut -d. -f1,2)
echo "   ✅ PHP $PHP_VERSION encontrado"

# Verificar extensões PHP
echo "🔍 Verificando extensões PHP..."
REQUIRED_EXTENSIONS=("pdo_mysql" "openssl" "mbstring")
MISSING_EXTENSIONS=()

for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if php -m | grep -q "^${ext}$"; then
        echo "   ✅ $ext"
    else
        echo "   ❌ $ext não encontrada"
        MISSING_EXTENSIONS+=("$ext")
    fi
done

if [ ${#MISSING_EXTENSIONS[@]} -gt 0 ]; then
    echo ""
    echo "⚠️  Instale as extensões faltantes:"
    echo "   sudo apt install php${PHP_VERSION}-${MISSING_EXTENSIONS[0]} php${PHP_VERSION}-${MISSING_EXTENSIONS[1]} ..."
    exit 1
fi

# Testar conexão com banco
echo ""
echo "🔍 Testando conexão com banco de dados..."
php -r "
require 'src/env.php';
require 'src/bootstrap.php';
try {
    \$db = db();
    echo '   ✅ Conexão com banco OK!' . PHP_EOL;
} catch (Exception \$e) {
    echo '   ❌ Erro: ' . \$e->getMessage() . PHP_EOL;
    exit(1);
}
" || exit 1

# Verificar se banco está vazio
echo ""
echo "🔍 Verificando estrutura do banco..."
TABLE_COUNT=$(php -r "
require 'src/bootstrap.php';
\$pdo = db();
\$stmt = \$pdo->query('SHOW TABLES');
echo \$stmt->rowCount();
" 2>/dev/null || echo "0")

if [ "$TABLE_COUNT" -eq "0" ]; then
    echo "   ⚠️  Banco de dados vazio"
    echo ""
    read -p "   Deseja importar a estrutura do banco? (s/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Ss]$ ]]; then
        echo "   📥 Importando estrutura do banco..."
        if [ -f "database/apptdesk.sql" ]; then
            DB_NAME=$(php -r "require 'src/env.php'; echo env('DB_NAME');")
            DB_USER=$(php -r "require 'src/env.php'; echo env('DB_USERNAME');")
            DB_PASS=$(php -r "require 'src/env.php'; echo env('DB_PASSWORD');")
            
            mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < database/apptdesk.sql 2>/dev/null || {
                echo "   ⚠️  Erro ao importar via mysql. Tentando via PHP..."
                php scripts/update-database.php || {
                    echo "   ❌ Erro ao importar banco de dados"
                    exit 1
                }
            }
            echo "   ✅ Estrutura do banco importada!"
        else
            echo "   ❌ Arquivo database/apptdesk.sql não encontrado"
            exit 1
        fi
    fi
else
    echo "   ✅ Banco de dados já possui $TABLE_COUNT tabela(s)"
fi

# Verificar permissões de upload
echo ""
echo "🔍 Verificando diretório de uploads..."
if [ ! -d "public/uploads/attachments" ]; then
    mkdir -p public/uploads/attachments
    echo "   ✅ Diretório criado"
fi
chmod -R 775 public/uploads
chown -R www-data:www-data public/uploads
echo "   ✅ Permissões configuradas"

# Verificar servidor web
echo ""
echo "🔍 Verificando servidor web..."
if systemctl is-active --quiet nginx; then
    echo "   ✅ Nginx está rodando"
    WEB_SERVER="nginx"
elif systemctl is-active --quiet apache2; then
    echo "   ✅ Apache está rodando"
    WEB_SERVER="apache2"
else
    echo "   ⚠️  Nenhum servidor web detectado (Nginx ou Apache)"
    WEB_SERVER="none"
fi

# Resumo
echo ""
echo "======================================"
echo "✅ Deploy concluído com sucesso!"
echo "======================================"
echo ""
echo "📋 Informações:"
echo "   Diretório: $APP_DIR"
echo "   PHP: $PHP_VERSION"
echo "   Servidor Web: $WEB_SERVER"
echo ""
echo "📝 Próximos passos:"
echo "   1. Configure seu servidor web (Nginx ou Apache)"
echo "   2. Configure SSL/HTTPS (Let's Encrypt)"
echo "   3. Configure firewall"
echo "   4. Acesse a aplicação no navegador"
echo ""
echo "📖 Consulte DEPLOY_VPS.md para instruções detalhadas"
echo ""

