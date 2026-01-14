#!/bin/bash

# Script para fazer upload dos arquivos para a VPS via Git
# Execute na VPS após conectar via SSH

set -e

echo "📦 Fazendo upload dos arquivos da aplicação..."
echo ""

AAPANEL_DIR="/www/wwwroot/app.tdesksolutions.com.br"

# Verificar se está como root ou com sudo
if [ "$EUID" -ne 0 ]; then 
    echo "⚠️  Execute com sudo: sudo ./upload-arquivos-vps.sh"
    exit 1
fi

cd "$AAPANEL_DIR"

# Verificar se já existe .git
if [ -d ".git" ]; then
    echo "🔄 Atualizando repositório existente..."
    git pull origin main || {
        echo "⚠️  Erro ao fazer pull. Tentando fazer clone novamente..."
        cd ..
        rm -rf app.tdesksolutions.com.br
        mkdir -p app.tdesksolutions.com.br
        cd app.tdesksolutions.com.br
        git clone https://github.com/douglasmouradev/help-desk-tdesk.git .
    }
else
    echo "📥 Fazendo clone do repositório..."
    # Fazer backup dos arquivos padrão do aaPanel
    if [ -f "index.html" ] || [ -f ".htaccess" ]; then
        echo "   Fazendo backup dos arquivos padrão..."
        mkdir -p .backup-aapanel
        cp -f index.html .backup-aapanel/ 2>/dev/null || true
        cp -f .htaccess .backup-aapanel/ 2>/dev/null || true
        cp -f .user.ini .backup-aapanel/ 2>/dev/null || true
    fi
    
    # Fazer clone
    git clone https://github.com/douglasmouradev/help-desk-tdesk.git .
fi

echo ""
echo "✅ Arquivos baixados com sucesso!"
echo ""
echo "📋 Próximos passos:"
echo "   1. Execute: sudo ./deploy-aapanel.sh"
echo "   2. Configure o .env se necessário"
echo "   3. Importe o banco de dados"
echo ""
