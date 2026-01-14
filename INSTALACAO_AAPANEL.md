# Instalação no aaPanel - Passo a Passo

## 1. Conectar na VPS via SSH
```bash
ssh root@62.72.63.161
```

## 2. Ir para o diretório do site
```bash
cd /www/wwwroot/app.tdesksolutions.com.br
```

## 3. Fazer clone do repositório
```bash
git clone https://github.com/douglasmouradev/help-desk-tdesk.git .
```

## 4. Executar o script de deploy
```bash
chmod +x deploy-aapanel.sh
sudo ./deploy-aapanel.sh
```

## 5. Configurar o .env (se necessário)
```bash
nano .env
```

Ajuste se necessário:
- `DB_PASSWORD` - se o MySQL root tem senha
- `APP_URL` - já deve estar correto: `https://app.tdesksolutions.com.br`
- `MAIL_FROM` - já deve estar correto: `no-reply@tdesksolutions.com.br`

Salve: `Ctrl+X`, depois `Y`, depois `Enter`

## 6. Importar banco de dados
```bash
mysql -u root -p tdesk_solutions < database/apptdesk.sql
```

(Se pedir senha, digite a senha do MySQL root, ou apenas Enter se não tiver senha)

## 7. Configurar SSL no aaPanel
- Vá em: **Site** → **app.tdesksolutions.com.br** → **SSL**
- Clique em **"Let's Encrypt"**
- Marque o domínio `app.tdesksolutions.com.br`
- Clique em **"Aplicar"**

## 8. Acessar a aplicação
Acesse: `https://app.tdesksolutions.com.br`

## Troubleshooting

### Se der erro de permissão:
```bash
chown -R www:www /www/wwwroot/app.tdesksolutions.com.br
chmod -R 755 /www/wwwroot/app.tdesksolutions.com.br
chmod -R 775 /www/wwwroot/app.tdesksolutions.com.br/public
```

### Se der erro de conexão com banco:
Verifique se o banco existe:
```bash
mysql -u root -p -e "SHOW DATABASES;"
```

Crie o banco se não existir:
```bash
mysql -u root -p -e "CREATE DATABASE tdesk_solutions CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### Verificar logs de erro:
```bash
tail -f /www/wwwlogs/app.tdesksolutions.com.br.error.log
```
