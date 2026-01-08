# 🚀 Configuração Rápida - VPS TDesk Solutions

## 📍 Informações do Servidor

- **IP da VPS:** `62.72.63.161`
- **Domínio:** `app.tdesksolutions.com.br`
- **Painel:** aaPanel (acessível em `https://62.72.63.161:27268`)

## 🔧 Configuração Inicial

### 1. Conectar à VPS

```bash
ssh root@62.72.63.161
# ou
ssh usuario@62.72.63.161
```

### 2. Verificar DNS

Certifique-se de que o domínio está apontando para o IP:

```bash
# Verificar DNS
dig app.tdesksolutions.com.br
# ou
nslookup app.tdesksolutions.com.br
```

**Deve retornar:** `62.72.63.161`

**No seu provedor de DNS, configure:**
```
Tipo: A
Nome: app (ou @)
Valor: 62.72.63.161
TTL: 3600
```

### 3. Verificar Serviços

```bash
# Verificar se Nginx/Apache está rodando
sudo systemctl status nginx
# ou
sudo systemctl status apache2

# Verificar PHP-FPM
sudo systemctl status php8.3-fpm
# ou
sudo systemctl status php-fpm

# Verificar MySQL
sudo systemctl status mysql
```

### 4. Configurar Diretório da Aplicação

```bash
# Navegar até o diretório
cd /var/www/tdesk

# Verificar se os arquivos estão lá
ls -la

# Verificar permissões
sudo chown -R www-data:www-data /var/www/tdesk
sudo chmod -R 755 /var/www/tdesk
sudo chmod -R 775 /var/www/tdesk/public/uploads
```

### 5. Configurar .env

```bash
cd /var/www/tdesk
sudo nano .env
```

Configure com:
```env
APP_URL=https://app.tdesksolutions.com.br
DB_HOST=127.0.0.1
DB_NAME=tdesk_solutions
DB_USERNAME=tdesk_user
DB_PASSWORD=SUA_SENHA_AQUI
```

### 6. Configurar Site no aaPanel

1. Acesse: `https://62.72.63.161:27268`
2. Vá em **"Site"** → **"Adicionar Site"** (ou edite o existente)
3. Configure:
   - **Domínio:** `app.tdesksolutions.com.br`
   - **Document Root:** `/var/www/tdesk/public`
   - **PHP Version:** 8.3 (ou superior)
   - **Run Dir:** `/var/www/tdesk/public`

### 7. Configurar SSL no aaPanel

**IMPORTANTE:** Antes de configurar SSL, faça:

```bash
# Criar diretório de verificação
sudo mkdir -p /var/www/tdesk/public/.well-known/acme-challenge
sudo chown -R www-data:www-data /var/www/tdesk/public/.well-known
sudo chmod -R 755 /var/www/tdesk/public/.well-known

# Verificar se o site responde em HTTP
curl -I http://app.tdesksolutions.com.br
```

**No aaPanel:**

1. Vá em **"Site"** → Selecione `app.tdesksolutions.com.br`
2. Clique em **"SSL"**
3. Escolha **"Let's Encrypt"**
4. Marque apenas **`app.tdesksolutions.com.br`** (não marque www se não configurou)
5. Clique em **"Aplicar"**

**Se der erro 404:**

1. Verifique se o DNS está correto (passo 2)
2. Verifique se o site está acessível: `curl http://app.tdesksolutions.com.br`
3. Verifique firewall: `sudo ufw allow 80/tcp`
4. Consulte: [TROUBLESHOOTING_SSL.md](TROUBLESHOOTING_SSL.md)

### 8. Configurar Firewall

```bash
# Verificar status
sudo ufw status

# Permitir portas necessárias
sudo ufw allow 22/tcp    # SSH
sudo ufw allow 80/tcp    # HTTP
sudo ufw allow 443/tcp   # HTTPS
sudo ufw allow 27268/tcp # aaPanel (se necessário)

# Ativar firewall
sudo ufw enable
```

### 9. Testar Aplicação

```bash
# Testar conexão com banco
cd /var/www/tdesk
php -r "require 'src/bootstrap.php'; \$db = db(); echo 'Conexão OK!';"

# Testar acesso HTTP
curl -I http://app.tdesksolutions.com.br

# Testar acesso HTTPS (após configurar SSL)
curl -I https://app.tdesksolutions.com.br
```

## 🔍 Comandos Úteis

### Verificar Logs

```bash
# Logs do Nginx
sudo tail -f /var/log/nginx/error.log
sudo tail -f /var/log/nginx/access.log

# Logs do Apache
sudo tail -f /var/log/apache2/error.log
sudo tail -f /var/log/apache2/access.log

# Logs do PHP
sudo tail -f /var/log/php8.3-fpm.log

# Logs da aplicação (se houver)
tail -f /var/www/tdesk/storage/logs/app.log
```

### Reiniciar Serviços

```bash
# Nginx
sudo systemctl restart nginx
sudo systemctl reload nginx

# Apache
sudo systemctl restart apache2

# PHP-FPM
sudo systemctl restart php8.3-fpm

# MySQL
sudo systemctl restart mysql
```

### Verificar Permissões

```bash
# Verificar propriedade dos arquivos
ls -la /var/www/tdesk

# Corrigir permissões
sudo chown -R www-data:www-data /var/www/tdesk
sudo chmod -R 755 /var/www/tdesk
sudo chmod -R 775 /var/www/tdesk/public/uploads
sudo chmod 600 /var/www/tdesk/.env
```

## 🚨 Troubleshooting Rápido

### Site não carrega

1. Verificar se o servidor web está rodando
2. Verificar logs de erro
3. Verificar permissões
4. Verificar se o `.env` está configurado

### Erro 502 Bad Gateway

1. Verificar PHP-FPM: `sudo systemctl status php8.3-fpm`
2. Verificar socket: `ls -la /var/run/php/php8.3-fpm.sock`
3. Reiniciar PHP-FPM: `sudo systemctl restart php8.3-fpm`

### Erro de conexão com banco

1. Verificar se MySQL está rodando: `sudo systemctl status mysql`
2. Verificar credenciais no `.env`
3. Testar conexão: `mysql -u tdesk_user -p tdesk_solutions`

### SSL não funciona

1. Verificar DNS: `dig app.tdesksolutions.com.br`
2. Verificar se site responde em HTTP
3. Criar diretório `.well-known`
4. Verificar firewall
5. Consulte: [TROUBLESHOOTING_SSL.md](TROUBLESHOOTING_SSL.md)

## 📝 Checklist de Deploy

- [ ] DNS configurado e apontando para `62.72.63.161`
- [ ] Arquivos da aplicação em `/var/www/tdesk`
- [ ] Arquivo `.env` configurado
- [ ] Banco de dados criado e importado
- [ ] Site configurado no aaPanel
- [ ] Document Root: `/var/www/tdesk/public`
- [ ] PHP habilitado no site
- [ ] Permissões corretas
- [ ] Site acessível via HTTP
- [ ] SSL configurado e funcionando
- [ ] Firewall configurado
- [ ] Aplicação funcionando corretamente

## 🔗 Links Úteis

- **Acesso aaPanel:** `https://62.72.63.161:27268`
- **Aplicação (HTTP):** `http://app.tdesksolutions.com.br`
- **Aplicação (HTTPS):** `https://app.tdesksolutions.com.br` (após configurar SSL)

## 📚 Documentação Completa

- [DEPLOY_VPS.md](DEPLOY_VPS.md) - Guia completo de deploy
- [TROUBLESHOOTING_SSL.md](TROUBLESHOOTING_SSL.md) - Solução de problemas SSL

