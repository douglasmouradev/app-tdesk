#!/bin/bash

# Script para fazer upload do projeto para VPS
# Uso: ./upload-vps.sh

set -e

echo "🚀 Upload TDesk Solutions para VPS"
echo ""

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Solicitar informações da VPS
read -p "IP ou hostname da VPS: " VPS_HOST
read -p "Usuário SSH (ex: root ou ubuntu): " VPS_USER
read -p "Diretório de destino na VPS (ex: /var/www/tdesk): " VPS_DIR
read -p "Porta SSH (padrão 22): " VPS_PORT
VPS_PORT=${VPS_PORT:-22}

echo ""
echo "Escolha o método de upload:"
echo "1) Git Clone (recomendado - requer Git na VPS)"
echo "2) SCP/SFTP (upload direto)"
echo "3) rsync (sincronização eficiente)"
read -p "Opção (1/2/3): " UPLOAD_METHOD

case $UPLOAD_METHOD in
    1)
        echo ""
        echo "${GREEN}📦 Método: Git Clone${NC}"
        echo ""
        echo "Executando na VPS..."
        ssh -p $VPS_PORT $VPS_USER@$VPS_HOST << EOF
            set -e
            echo "📁 Criando diretório..."
            sudo mkdir -p $VPS_DIR
            cd $VPS_DIR
            
            if [ -d ".git" ]; then
                echo "🔄 Atualizando repositório existente..."
                sudo git pull origin main
            else
                echo "📥 Clonando repositório..."
                sudo rm -rf * .* 2>/dev/null || true
                sudo git clone https://github.com/douglasmouradev/app-tdesk.git .
            fi
            
            echo "✅ Upload concluído!"
            echo ""
            echo "📋 Próximos passos:"
            echo "   1. Execute: cd $VPS_DIR && sudo ./deploy.sh"
            echo "   2. Configure o arquivo .env"
            echo "   3. Configure o banco de dados"
EOF
        ;;
    2)
        echo ""
        echo "${GREEN}📤 Método: SCP${NC}"
        echo ""
        
        # Criar arquivo temporário com lista de exclusões
        EXCLUDE_FILE=$(mktemp)
        cat > $EXCLUDE_FILE << 'EXCLUDES'
.git/
.gitignore
*.md
.DS_Store
node_modules/
vendor/
.env
.env.local
*.log
public/uploads/*
!public/uploads/.gitkeep
EXCLUDES
        
        echo "📤 Enviando arquivos..."
        scp -P $VPS_PORT -r \
            --exclude-from=$EXCLUDE_FILE \
            . $VPS_USER@$VPS_HOST:$VPS_DIR
        
        rm $EXCLUDE_FILE
        
        echo ""
        echo "✅ Upload concluído!"
        echo ""
        echo "📋 Próximos passos:"
        echo "   1. SSH na VPS: ssh -p $VPS_PORT $VPS_USER@$VPS_HOST"
        echo "   2. Execute: cd $VPS_DIR && sudo ./deploy.sh"
        ;;
    3)
        echo ""
        echo "${GREEN}🔄 Método: rsync${NC}"
        echo ""
        
        # Verificar se rsync está instalado
        if ! command -v rsync &> /dev/null; then
            echo "${RED}❌ rsync não encontrado. Instale primeiro:${NC}"
            echo "   macOS: brew install rsync"
            echo "   Linux: sudo apt-get install rsync"
            exit 1
        fi
        
        echo "🔄 Sincronizando arquivos..."
        rsync -avz --progress \
            --exclude='.git/' \
            --exclude='*.md' \
            --exclude='.DS_Store' \
            --exclude='node_modules/' \
            --exclude='vendor/' \
            --exclude='.env' \
            --exclude='.env.local' \
            --exclude='*.log' \
            --exclude='public/uploads/*' \
            -e "ssh -p $VPS_PORT" \
            ./ $VPS_USER@$VPS_HOST:$VPS_DIR/
        
        echo ""
        echo "✅ Sincronização concluída!"
        echo ""
        echo "📋 Próximos passos:"
        echo "   1. SSH na VPS: ssh -p $VPS_PORT $VPS_USER@$VPS_HOST"
        echo "   2. Execute: cd $VPS_DIR && sudo ./deploy.sh"
        ;;
    *)
        echo "${RED}❌ Opção inválida!${NC}"
        exit 1
        ;;
esac

echo ""
echo "${GREEN}✅ Processo concluído!${NC}"

