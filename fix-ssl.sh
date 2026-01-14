#!/bin/bash

# Script para corrigir problema de SSL (ACME Challenge 404)
# Execute na VPS: sudo ./fix-ssl.sh

set -e

echo "🔧 Corrigindo problema de SSL (ACME Challenge)..."
echo ""

# Verificar se está como root ou com sudo
if [ "$EUID" -ne 0 ]; then 
    echo "⚠️  Execute com sudo: sudo ./fix-ssl.sh"
    exit 1
fi

AAPANEL_DIR="/www/wwwroot/app.tdesksolutions.com.br"
WELL_KNOWN_DIR="$AAPANEL_DIR/public/.well-known/acme-challenge"

echo "📁 Verificando diretório .well-known..."
if [ ! -d "$WELL_KNOWN_DIR" ]; then
    echo "   Criando diretório .well-known/acme-challenge..."
    mkdir -p "$WELL_KNOWN_DIR"
    echo "   ✅ Diretório criado"
else
    echo "   ✅ Diretório já existe"
fi

echo ""
echo "🔐 Configurando permissões..."
chown -R www:www "$AAPANEL_DIR/public/.well-known"
chmod -R 755 "$AAPANEL_DIR/public/.well-known"
chmod -R 755 "$WELL_KNOWN_DIR"
echo "   ✅ Permissões configuradas"

echo ""
echo "📝 Verificando .htaccess em public/..."
HTACCESS_FILE="$AAPANEL_DIR/public/.htaccess"
if [ -f "$HTACCESS_FILE" ]; then
    if ! grep -q ".well-known" "$HTACCESS_FILE"; then
        echo "   Adicionando regras para .well-known no .htaccess..."
        # Adicionar no início do arquivo (prioridade)
        cat > "$HTACCESS_FILE.tmp" << 'HTACCESS'
# Permitir .well-known para Let's Encrypt (ACME Challenge) - PRIORIDADE MÁXIMA
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} ^/.well-known/acme-challenge/
    RewriteRule ^ - [L]
</IfModule>

<DirectoryMatch "^.*/\.well-known/">
    Require all granted
    Order allow,deny
    Allow from all
</DirectoryMatch>

HTACCESS
        cat "$HTACCESS_FILE" >> "$HTACCESS_FILE.tmp"
        mv "$HTACCESS_FILE.tmp" "$HTACCESS_FILE"
        echo "   ✅ Regras adicionadas"
    else
        echo "   ✅ .htaccess já tem regras para .well-known"
    fi
else
    echo "   Criando .htaccess com regras para .well-known..."
    cat > "$HTACCESS_FILE" << 'HTACCESS'
# Permitir .well-known para Let's Encrypt (ACME Challenge) - PRIORIDADE MÁXIMA
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} ^/.well-known/acme-challenge/
    RewriteRule ^ - [L]
</IfModule>

<DirectoryMatch "^.*/\.well-known/">
    Require all granted
    Order allow,deny
    Allow from all
</DirectoryMatch>
HTACCESS
    chown www:www "$HTACCESS_FILE"
    chmod 644 "$HTACCESS_FILE"
    echo "   ✅ .htaccess criado"
fi

echo ""
echo "🧪 Criando arquivo de teste..."
TEST_FILE="$WELL_KNOWN_DIR/test.txt"
echo "test" > "$TEST_FILE"
chown www:www "$TEST_FILE"
chmod 644 "$TEST_FILE"
echo "   ✅ Arquivo de teste criado: $TEST_FILE"

echo ""
echo "✅ Correções aplicadas!"
echo ""
echo "📋 Próximos passos:"
echo "   1. Teste o acesso: http://app.tdesksolutions.com.br/.well-known/acme-challenge/test.txt"
echo "   2. Se funcionar, tente gerar o SSL novamente no aaPanel"
echo "   3. Se ainda não funcionar, verifique se o Document Root está como:"
echo "      $AAPANEL_DIR/public"
echo ""
echo "🔍 Verificando Document Root no aaPanel..."
echo "   Vá em: Site → app.tdesksolutions.com.br → Configuração"
echo "   O Document Root DEVE ser: $AAPANEL_DIR/public"
echo ""
