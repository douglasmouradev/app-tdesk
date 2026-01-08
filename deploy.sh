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

# Configurar permissões
echo "📁 Configurando permissões..."
chown -R www-data:www-data .
chmod -R 755 .
chmod -R 775 public/
chmod 600 .env 2>/dev/null || echo "   .env ainda não existe (será criado)"

# Verificar .env
if [ ! -f .env ]; then
    echo "⚠️  Arquivo .env não encontrado!"
    echo "   Copiando .env.example..."
    cp .env.example .env
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

