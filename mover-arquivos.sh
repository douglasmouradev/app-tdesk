#!/bin/bash

# Script para mover arquivos da subpasta help-desk-tdesk para o diretório raiz
# Execute na VPS: cd /www/wwwroot/app.tdesksolutions.com.br && bash mover-arquivos.sh

set -e

echo "📦 Movendo arquivos da subpasta para o diretório raiz..."
echo ""

CURRENT_DIR="/www/wwwroot/app.tdesksolutions.com.br"
SUBDIR="$CURRENT_DIR/help-desk-tdesk"

if [ ! -d "$SUBDIR" ]; then
    echo "❌ Subpasta help-desk-tdesk não encontrada!"
    echo "   Verifique se o clone foi feito corretamente"
    exit 1
fi

cd "$CURRENT_DIR"

echo "📁 Movendo arquivos..."
# Mover todos os arquivos da subpasta para o diretório atual
mv "$SUBDIR"/* . 2>/dev/null || true
mv "$SUBDIR"/.* . 2>/dev/null || true

# Remover a subpasta vazia
rmdir "$SUBDIR" 2>/dev/null || rm -rf "$SUBDIR"

echo "✅ Arquivos movidos com sucesso!"
echo ""

# Verificar se os arquivos principais existem
if [ ! -f "dashboard.php" ] && [ ! -f "public/index.php" ]; then
    echo "⚠️  Arquivos principais não encontrados!"
    echo "   Verifique se o clone foi feito corretamente"
    exit 1
fi

echo "🔐 Configurando permissões..."
chown -R www:www .
chmod -R 755 .
chmod -R 775 public/ 2>/dev/null || echo "   ⚠️  Diretório public/ não encontrado"

# Criar diretório .well-known se não existir
mkdir -p public/.well-known/acme-challenge
chown -R www:www public/.well-known
chmod -R 755 public/.well-known

echo ""
echo "✅ Configuração concluída!"
echo ""
echo "📋 Próximos passos:"
echo "   1. Execute: sudo ./deploy-aapanel.sh"
echo "   2. Configure o .env se necessário"
echo "   3. Importe o banco de dados"
echo ""
