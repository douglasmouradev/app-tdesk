# 🔒 Troubleshooting SSL - Erro de Verificação Let's Encrypt

## Erro Comum: "Verification failed, domain name resolution error or verification URL cannot be accessed!"

Este erro ocorre quando o Let's Encrypt não consegue acessar o arquivo de verificação em:
```
http://seudominio.com.br/.well-known/acme-challenge/[token]
```

## ✅ Soluções Passo a Passo

### 1. Verificar DNS

O domínio deve estar apontando corretamente para o IP da VPS.

```bash
# Verificar se o DNS está correto
dig app.tdesksolutions.com.br
# ou
nslookup app.tdesksolutions.com.br

# Verificar ambos IPv4 e IPv6
dig A app.tdesksolutions.com.br
dig AAAA app.tdesksolutions.com.br
```

**O que verificar:**
- O registro A deve apontar para o IP da sua VPS
- O registro AAAA (IPv6) também deve estar correto (se usar IPv6)
- Aguarde propagação DNS (pode levar até 48h, mas geralmente é rápido)

**No seu provedor de DNS, configure:**
```
Tipo: A
Nome: app (ou @)
Valor: IP_DA_SUA_VPS
TTL: 3600
```

### 2. Verificar se o Site Está Funcionando

Antes de configurar SSL, o site deve estar acessível via HTTP:

```bash
# Testar se o site responde
curl -I http://app.tdesksolutions.com.br

# Deve retornar HTTP 200 ou 301/302
```

Se não responder, configure o servidor web primeiro.

### 3. Configurar Nginx para Permitir Verificação Let's Encrypt

Se estiver usando Nginx, adicione esta configuração **ANTES** de tentar obter o SSL:

```bash
sudo nano /etc/nginx/sites-available/tdesk
```

Adicione ou verifique esta seção no bloco `server`:

```nginx
server {
    listen 80;
    server_name app.tdesksolutions.com.br;
    
    root /var/www/tdesk/public;
    index index.php index.html;

    # PERMITIR VERIFICAÇÃO LET'S ENCRYPT
    location /.well-known/acme-challenge/ {
        root /var/www/tdesk/public;
        allow all;
    }

    # Resto da configuração...
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

**Importante:** O diretório `.well-known` deve estar acessível via HTTP (porta 80) durante a verificação.

Teste e reinicie:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

### 4. Configurar Apache para Permitir Verificação Let's Encrypt

Se estiver usando Apache:

```bash
sudo nano /etc/apache2/sites-available/tdesk.conf
```

Adicione ou verifique:

```apache
<VirtualHost *:80>
    ServerName app.tdesksolutions.com.br
    DocumentRoot /var/www/tdesk/public

    # PERMITIR VERIFICAÇÃO LET'S ENCRYPT
    <Directory /var/www/tdesk/public/.well-known>
        Options None
        AllowOverride None
        Require all granted
    </Directory>

    <Directory /var/www/tdesk/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Ativar e reiniciar:
```bash
sudo a2ensite tdesk.conf
sudo systemctl reload apache2
```

### 5. Criar Diretório Manualmente

Crie o diretório de verificação:

```bash
sudo mkdir -p /var/www/tdesk/public/.well-known/acme-challenge
sudo chown -R www-data:www-data /var/www/tdesk/public/.well-known
sudo chmod -R 755 /var/www/tdesk/public/.well-known
```

### 6. Verificar Firewall

Certifique-se de que a porta 80 (HTTP) está aberta:

```bash
# UFW
sudo ufw status
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Firewalld
sudo firewall-cmd --list-ports
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload

# iptables (verificar)
sudo iptables -L -n | grep 80
```

### 7. Testar Acesso Manual ao Arquivo de Verificação

Quando o aaPanel tentar verificar, ele criará um arquivo temporário. Teste manualmente:

```bash
# Criar arquivo de teste
echo "test-verification" | sudo tee /var/www/tdesk/public/.well-known/acme-challenge/test.txt

# Testar acesso
curl http://app.tdesksolutions.com.br/.well-known/acme-challenge/test.txt

# Deve retornar: test-verification
```

Se não funcionar, há problema na configuração do servidor web.

### 8. Verificar Logs

Verifique os logs para entender o erro:

```bash
# Nginx
sudo tail -f /var/log/nginx/error.log
sudo tail -f /var/log/nginx/access.log

# Apache
sudo tail -f /var/log/apache2/error.log
sudo tail -f /var/log/apache2/access.log

# Tentar obter SSL novamente e observar os logs
```

### 9. Configuração Específica para aaPanel

Se estiver usando aaPanel, verifique:

1. **Configuração do Site:**
   - O diretório raiz deve ser: `/var/www/tdesk/public`
   - PHP deve estar habilitado
   - O site deve estar funcionando em HTTP

2. **Verificar Configuração do Site no aaPanel:**
   - Vá em "Site" → Selecione seu site
   - Verifique se o "Document Root" está correto
   - Verifique se o PHP está habilitado

3. **Tentar SSL Manualmente via Certbot:**
   ```bash
   # Instalar certbot
   sudo apt install certbot python3-certbot-nginx
   # ou para Apache
   sudo apt install certbot python3-certbot-apache

   # Obter certificado manualmente
   sudo certbot --nginx -d app.tdesksolutions.com.br
   # ou
   sudo certbot --apache -d app.tdesksolutions.com.br
   ```

### 10. Solução Alternativa: Verificação via DNS

Se a verificação HTTP não funcionar, use verificação via DNS:

1. No aaPanel, ao solicitar SSL, escolha a opção de verificação DNS
2. Adicione o registro TXT no seu provedor de DNS conforme instruções
3. Aguarde propagação (alguns minutos)
4. Complete a verificação

## 🔍 Checklist de Diagnóstico

Execute estes comandos para diagnosticar:

```bash
# 1. DNS está correto?
dig +short app.tdesksolutions.com.br

# 2. Site responde em HTTP?
curl -I http://app.tdesksolutions.com.br

# 3. Porta 80 está aberta?
sudo netstat -tlnp | grep :80

# 4. Servidor web está rodando?
sudo systemctl status nginx
# ou
sudo systemctl status apache2

# 5. Diretório .well-known existe e tem permissões corretas?
ls -la /var/www/tdesk/public/.well-known/

# 6. Arquivo de teste é acessível?
curl http://app.tdesksolutions.com.br/.well-known/acme-challenge/test.txt
```

## ⚠️ Problemas Comuns

### Problema: "404 Not Found" no arquivo de verificação

**Causa:** Servidor web não está servindo o diretório `.well-known`

**Solução:** 
- Verifique a configuração do Nginx/Apache (passos 3 ou 4)
- Certifique-se de que o `root` está apontando para `/var/www/tdesk/public`

### Problema: "Connection refused" ou timeout

**Causa:** Firewall bloqueando ou DNS incorreto

**Solução:**
- Verifique firewall (passo 6)
- Verifique DNS (passo 1)
- Teste: `curl http://IP_DA_VPS` (deve funcionar)

### Problema: "Domain name resolution error"

**Causa:** DNS não propagou ou está incorreto

**Solução:**
- Aguarde propagação DNS (pode levar até 48h)
- Verifique se o registro A está correto
- Use ferramentas online como `whatsmydns.net` para verificar propagação

### Problema: Funciona localmente mas não externamente

**Causa:** Firewall ou configuração de rede

**Solução:**
- Verifique regras de firewall
- Verifique se a VPS aceita conexões na porta 80
- Teste de outro local/rede

## 🎯 Solução Rápida (aaPanel)

1. **No aaPanel, vá em "Site" → Seu site → "Configuração"**
2. **Verifique se o "Document Root" está como:** `/var/www/tdesk/public`
3. **Salve as alterações**
4. **Vá em "SSL" → "Let's Encrypt"**
5. **Marque apenas o domínio principal** (não www se não configurou)
6. **Clique em "Aplicar"**
7. **Se ainda falhar, use a opção de verificação DNS**

## 📞 Ainda com Problemas?

Se nenhuma solução funcionar:

1. Verifique os logs do servidor web
2. Teste manualmente com Certbot (passo 9)
3. Use verificação DNS ao invés de HTTP
4. Verifique se há algum proxy/CDN na frente (Cloudflare, etc.) que possa estar interferindo

