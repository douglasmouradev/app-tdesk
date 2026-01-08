# 🚀 Guia de Deploy - TDesk Solutions em VPS

Este guia mostra como hospedar a aplicação TDesk Solutions em uma VPS (Virtual Private Server).

## 📍 Informações do Servidor

- **IP da VPS:** `62.72.63.161`
- **Domínio:** `app.tdesksolutions.com.br`
- **Painel:** aaPanel (se aplicável)

📖 **Para configuração rápida específica desta VPS, consulte:** [CONFIGURACAO_VPS.md](CONFIGURACAO_VPS.md)

## 📋 Pré-requisitos

- VPS com acesso SSH (root ou usuário com sudo)
- Domínio configurado (opcional, mas recomendado)
- Conhecimento básico de Linux

## 🔧 Passo 1: Conectar à VPS

```bash
ssh root@seu-ip-vps
# ou
ssh usuario@seu-ip-vps
```

## 📦 Passo 2: Instalar Dependências

### Ubuntu/Debian

```bash
# Atualizar sistema
sudo apt update && sudo apt upgrade -y

# Instalar PHP 8.3+ e extensões necessárias
sudo apt install -y php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd

# Instalar MySQL
sudo apt install -y mysql-server

# Instalar Nginx (recomendado) ou Apache
sudo apt install -y nginx
# OU
sudo apt install -y apache2 libapache2-mod-php8.3

# Instalar ferramentas úteis
sudo apt install -y git unzip curl
```

### CentOS/RHEL/Rocky Linux

```bash
# Atualizar sistema
sudo yum update -y

# Instalar EPEL e Remi repositories
sudo yum install -y epel-release
sudo yum install -y https://rpms.remirepo.net/enterprise/remi-release-8.rpm

# Instalar PHP 8.3
sudo yum install -y php83 php83-php-fpm php83-php-mysqlnd php83-php-mbstring php83-php-xml php83-php-curl php83-php-zip php83-php-gd

# Instalar MySQL
sudo yum install -y mysql-server

# Instalar Nginx
sudo yum install -y nginx

# Iniciar serviços
sudo systemctl enable --now mysqld
sudo systemctl enable --now nginx
sudo systemctl enable --now php83-php-fpm
```

## 🗄️ Passo 3: Configurar MySQL

```bash
# Configurar segurança do MySQL
sudo mysql_secure_installation

# Criar banco de dados e usuário
sudo mysql -u root -p
```

No MySQL, execute:

```sql
CREATE DATABASE tdesk_solutions CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tdesk_user'@'localhost' IDENTIFIED BY 'SUA_SENHA_FORTE_AQUI';
GRANT ALL PRIVILEGES ON tdesk_solutions.* TO 'tdesk_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

**⚠️ IMPORTANTE:** Anote o usuário e senha criados, você precisará deles no arquivo `.env`.

## 📁 Passo 4: Fazer Upload dos Arquivos

### Opção A: Via SCP (do seu computador local)

```bash
# No seu computador local, navegue até a pasta do projeto
cd /caminho/para/app\ tdesk

# Fazer upload para a VPS
scp -r * root@seu-ip-vps:/var/www/tdesk/
```

### Opção B: Via Git (recomendado)

```bash
# Na VPS
cd /var/www
sudo git clone https://seu-repositorio.git tdesk
# ou
sudo git clone https://github.com/seu-usuario/tdesk.git tdesk
```

### Opção C: Via FTP/SFTP

Use um cliente FTP como FileZilla, WinSCP ou Cyberduck para fazer upload dos arquivos.

## ⚙️ Passo 5: Configurar a Aplicação

```bash
# Navegar até o diretório
cd /var/www/tdesk

# Configurar permissões
sudo chown -R www-data:www-data /var/www/tdesk
sudo chmod -R 755 /var/www/tdesk
sudo chmod -R 775 /var/www/tdesk/public/uploads

# Criar arquivo .env
sudo cp .env.example .env
sudo nano .env
```

Configure o arquivo `.env` com suas credenciais:

```env
# Aplicação
APP_NAME="TDesk Solutions"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seudominio.com.br
APP_TIMEZONE=America/Sao_Paulo

# Banco de Dados
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=tdesk_solutions
DB_USERNAME=tdesk_user
DB_PASSWORD=SUA_SENHA_FORTE_AQUI
DB_CHARSET=utf8mb4

# Segurança
SESSION_NAME=tdesk_session
APP_KEY=GERE_UMA_CHAVE_ALEATORIA_AQUI
CSRF_TOKEN_EXPIRY=3600
PASSWORD_ALGO=PASSWORD_DEFAULT

# E-mail (opcional)
MAIL_FROM=noreply@seudominio.com.br
MAIL_HOST=smtp.seudominio.com.br
MAIL_PORT=587
MAIL_USERNAME=noreply@seudominio.com.br
MAIL_PASSWORD=senha_do_email
MAIL_ENCRYPTION=tls
```

**Gerar APP_KEY:**
```bash
php -r "echo bin2hex(random_bytes(32));"
```

## 🗄️ Passo 6: Configurar Banco de Dados

```bash
# Importar estrutura do banco
cd /var/www/tdesk
sudo mysql -u tdesk_user -p tdesk_solutions < database/apptdesk.sql

# OU usar o script PHP
php scripts/update-database.php
```

## 🌐 Passo 7: Configurar Nginx

Crie o arquivo de configuração do Nginx:

```bash
sudo nano /etc/nginx/sites-available/tdesk
```

Cole o seguinte conteúdo:

```nginx
server {
    listen 80;
    server_name seudominio.com.br www.seudominio.com.br;
    
    # Redirecionar HTTP para HTTPS (após configurar SSL)
    # return 301 https://$server_name$request_uri;
    
    root /var/www/tdesk/public;
    index index.php index.html;

    # Logs
    access_log /var/log/nginx/tdesk_access.log;
    error_log /var/log/nginx/tdesk_error.log;

    # Tamanho máximo de upload (para anexos)
    client_max_body_size 20M;

    # PERMITIR VERIFICAÇÃO LET'S ENCRYPT (IMPORTANTE PARA SSL)
    location /.well-known/acme-challenge/ {
        root /var/www/tdesk/public;
        allow all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        
        # Timeout para uploads grandes
        fastcgi_read_timeout 300;
    }

    # Bloquear acesso a arquivos sensíveis
    location ~ /\. {
        deny all;
    }

    location ~ \.(env|log|sql)$ {
        deny all;
    }

    # Cache para assets estáticos
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

Ativar o site:

```bash
# Criar link simbólico
sudo ln -s /etc/nginx/sites-available/tdesk /etc/nginx/sites-enabled/

# Remover site padrão (opcional)
sudo rm /etc/nginx/sites-enabled/default

# Testar configuração
sudo nginx -t

# Reiniciar Nginx
sudo systemctl restart nginx
```

## 🌐 Passo 7 (Alternativo): Configurar Apache

Se preferir usar Apache:

```bash
sudo nano /etc/apache2/sites-available/tdesk.conf
```

Cole o seguinte conteúdo:

```apache
<VirtualHost *:80>
    ServerName seudominio.com.br
    ServerAlias www.seudominio.com.br
    
    DocumentRoot /var/www/tdesk/public

    # PERMITIR VERIFICAÇÃO LET'S ENCRYPT (IMPORTANTE PARA SSL)
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

    # Tamanho máximo de upload
    php_value upload_max_filesize 20M
    php_value post_max_size 20M

    # Logs
    ErrorLog ${APACHE_LOG_DIR}/tdesk_error.log
    CustomLog ${APACHE_LOG_DIR}/tdesk_access.log combined
</VirtualHost>
```

Ativar o site:

```bash
# Ativar módulos necessários
sudo a2enmod rewrite
sudo a2enmod php8.3

# Ativar site
sudo a2ensite tdesk.conf

# Desativar site padrão (opcional)
sudo a2dissite 000-default.conf

# Reiniciar Apache
sudo systemctl restart apache2
```

## 🔒 Passo 8: Configurar SSL (HTTPS) com Let's Encrypt

**⚠️ IMPORTANTE:** Antes de configurar SSL, certifique-se de que:
1. O DNS está apontando corretamente para o IP da VPS
2. O site está acessível via HTTP (porta 80)
3. O diretório `.well-known` está configurado no servidor web (já incluído nas configurações acima)

```bash
# Criar diretório de verificação (se ainda não existe)
sudo mkdir -p /var/www/tdesk/public/.well-known/acme-challenge
sudo chown -R www-data:www-data /var/www/tdesk/public/.well-known
sudo chmod -R 755 /var/www/tdesk/public/.well-known

# Instalar Certbot
sudo apt install -y certbot python3-certbot-nginx
# OU para Apache
sudo apt install -y certbot python3-certbot-apache

# Obter certificado SSL
sudo certbot --nginx -d seudominio.com.br -d www.seudominio.com.br
# OU para Apache
sudo certbot --apache -d seudominio.com.br -d www.seudominio.com.br

# Renovação automática (já configurada automaticamente)
sudo certbot renew --dry-run
```

**Se estiver usando aaPanel:**
1. Vá em "Site" → Seu site → "SSL"
2. Clique em "Let's Encrypt"
3. Marque apenas o domínio principal
4. Clique em "Aplicar"

**Se der erro de verificação:**
📖 Consulte o guia completo: [TROUBLESHOOTING_SSL.md](TROUBLESHOOTING_SSL.md)

## 🔐 Passo 9: Configurar Firewall

```bash
# UFW (Ubuntu/Debian)
sudo ufw allow 22/tcp    # SSH
sudo ufw allow 80/tcp    # HTTP
sudo ufw allow 443/tcp   # HTTPS
sudo ufw enable

# Firewalld (CentOS/RHEL)
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --reload
```

## ✅ Passo 10: Verificar Instalação

1. **Testar conexão com banco:**
```bash
cd /var/www/tdesk
php -r "require 'src/bootstrap.php'; \$db = db(); echo 'Conexão OK!';"
```

2. **Verificar permissões:**
```bash
ls -la /var/www/tdesk/public/uploads
```

3. **Verificar logs:**
```bash
# Nginx
sudo tail -f /var/log/nginx/tdesk_error.log

# PHP-FPM
sudo tail -f /var/log/php8.3-fpm.log

# Apache
sudo tail -f /var/log/apache2/tdesk_error.log
```

4. **Acessar no navegador:**
   - `http://seu-ip-vps` ou `https://seudominio.com.br`

## 🔧 Configurações Adicionais

### Ajustar PHP-FPM (se necessário)

```bash
sudo nano /etc/php/8.3/fpm/php.ini
```

Ajustar:
```ini
upload_max_filesize = 20M
post_max_size = 20M
memory_limit = 256M
max_execution_time = 300
```

Reiniciar:
```bash
sudo systemctl restart php8.3-fpm
```

### Configurar Cron Jobs (opcional)

Para tarefas agendadas (limpeza de tokens expirados, etc.):

```bash
sudo crontab -e
```

Adicionar:
```cron
# Limpar tokens de reset de senha expirados (diariamente às 2h)
0 2 * * * cd /var/www/tdesk && php -r "require 'src/bootstrap.php'; require 'src/services.php'; cleanup_password_resets();"
```

## 🚨 Troubleshooting

### Erro SSL - Verificação Let's Encrypt Falhou

Se você receber erro "Verification failed" ao configurar SSL:

📖 **Consulte o guia completo:** [TROUBLESHOOTING_SSL.md](TROUBLESHOOTING_SSL.md)

**Soluções rápidas:**
1. Verificar se DNS está apontando corretamente: `dig app.tdesksolutions.com.br`
2. Verificar se site responde em HTTP: `curl -I http://seudominio.com.br`
3. Criar diretório de verificação: `sudo mkdir -p /var/www/tdesk/public/.well-known/acme-challenge`
4. Configurar Nginx/Apache para permitir acesso ao `.well-known` (ver guia completo)
5. Verificar firewall: `sudo ufw allow 80/tcp`

### Erro 502 Bad Gateway
- Verificar se PHP-FPM está rodando: `sudo systemctl status php8.3-fpm`
- Verificar socket: `ls -la /var/run/php/php8.3-fpm.sock`

### Erro de permissão
```bash
sudo chown -R www-data:www-data /var/www/tdesk
sudo chmod -R 755 /var/www/tdesk
sudo chmod -R 775 /var/www/tdesk/public/uploads
```

### Erro de conexão com banco
- Verificar credenciais no `.env`
- Verificar se MySQL está rodando: `sudo systemctl status mysql`
- Testar conexão: `mysql -u tdesk_user -p tdesk_solutions`

### Página em branco
- Verificar logs de erro do PHP
- Verificar se `APP_DEBUG=true` temporariamente no `.env`
- Verificar permissões do arquivo `.env`: `chmod 600 .env`

## 📝 Checklist Final

- [ ] PHP 8.3+ instalado com extensões necessárias
- [ ] MySQL instalado e banco criado
- [ ] Arquivos da aplicação no `/var/www/tdesk`
- [ ] Arquivo `.env` configurado
- [ ] Banco de dados importado
- [ ] Nginx/Apache configurado
- [ ] SSL/HTTPS configurado
- [ ] Firewall configurado
- [ ] Permissões corretas
- [ ] Aplicação acessível via navegador
- [ ] Login funcionando

## 🎉 Pronto!

Sua aplicação TDesk Solutions está hospedada e pronta para uso!

**Credenciais padrão (após importar banco):**
- Admin: `admin@tdesk.local` / `Admin@123`
- Suporte: `suporte@tdesk.local` / `Suporte@123`
- Cliente: `cliente@tdesk.local` / `Cliente@123`

**⚠️ IMPORTANTE:** Altere as senhas padrão após o primeiro login!

