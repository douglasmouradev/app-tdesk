#!/bin/bash

# Script rápido de upload para VPS
# IP: 62.72.63.161

VPS_HOST="62.72.63.161"
VPS_USER="root"
VPS_DIR="/var/www/tdesk"
VPS_PORT="22"

echo "🚀 Upload para VPS: $VPS_HOST"
echo ""
echo "Escolha o método:"
echo "1) Git Clone (recomendado)"
echo "2) rsync"
read -p "Opção (1/2): " OPCAO

case $OPCAO in
    1)
        echo ""
        echo "📦 Executando Git Clone na VPS..."
        echo "⚠️  Você precisará digitar a senha SSH"
        echo ""
        ssh -p $VPS_PORT $VPS_USER@$VPS_HOST << 'ENDSSH'
            sudo mkdir -p /var/www/tdesk
            cd /var/www/tdesk
            if [ -d ".git" ]; then
                echo "🔄 Atualizando repositório..."
                sudo git pull origin main
            else
                echo "📥 Clonando repositório..."
                sudo rm -rf * .* 2>/dev/null || true
                sudo git clone https://github.com/douglasmouradev/app-tdesk.git .
            fi
            echo "✅ Upload concluído!"
            echo ""
            echo "📋 Próximos passos:"
            echo "   1. cd /var/www/tdesk"
            echo "   2. sudo ./deploy.sh"
            echo "   3. Configure o .env"
ENDSSH
        ;;
    2)
        echo ""
        echo "🔄 Executando rsync..."
        echo "⚠️  Você precisará digitar a senha SSH"
        echo ""
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
        echo "✅ Upload concluído!"
        echo ""
        echo "📋 Próximos passos:"
        echo "   1. ssh -p $VPS_PORT $VPS_USER@$VPS_HOST"
        echo "   2. cd $VPS_DIR"
        echo "   3. sudo ./deploy.sh"
        ;;
    *)
        echo "❌ Opção inválida!"
        exit 1
        ;;
esac

