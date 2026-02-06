# Configuração Firebase - LimpVix Core

## 📋 Pré-requisitos

1. Conta Firebase ativa
2. Projeto Firebase criado

---

## 🔧 Passo 1: Criar Projeto no Firebase Console

1. Acesse: https://console.firebase.google.com/
2. Clique em **"Adicionar Projeto"**
3. Nome do projeto: `limpvix-production` (ou outro nome de sua escolha)
4. Aceite os termos e crie o projeto

---

## 🔑 Passo 2: Ativar Firebase Authentication

1. No menu lateral, clique em **"Authentication"**
2. Clique em **"Começar"**
3. Na aba **"Sign-in method"**, ative:
   - ✅ **Phone** (SMS OTP)
4. Configure o número de telefone de teste (opcional para desenvolvimento)

---

## 🌐 Passo 3: Configurar Domínios Autorizados

1. Em **Authentication → Settings → Authorized domains**
2. Adicione seus domínios:
   - `limpvix.com.br`
   - `localhost` (para testes locais)
   - Outros domínios de staging/dev se necessário

---

## 📁 Passo 4: Obter Credenciais

### Project ID
1. No Firebase Console, clique no ícone de **engrenagem** → **Configurações do projeto**
2. Copie o **"Project ID"** (ex: `limpvix-xxxxx`)

### API Key (Web)
1. Na mesma página, vá até a seção **"Seus aplicativos"**
2. Se não houver app Web, clique em **"Adicionar app"** → **Web** (<//>)
3. Nome do app: `LimpVix Web`
4. Copie o **"apiKey"** do objeto `firebaseConfig`

---

## 🔒 Passo 5: Adicionar Constantes ao wp-config.php

Abra o arquivo `/var/www/html/wp-config.php` (dentro do container WordPress) ou o arquivo montado no host e adicione as seguintes linhas **ANTES** de `/* That's all, stop editing! */`:

```php
/**
 * Firebase Authentication Configuration
 * Para verificação de telefone via SMS OTP
 */
define('LIMPVIX_FIREBASE_PROJECT_ID', 'seu-project-id-aqui');
define('LIMPVIX_FIREBASE_API_KEY', 'sua-api-key-aqui');
```

### Exemplo:
```php
define('LIMPVIX_FIREBASE_PROJECT_ID', 'limpvix-ab1cd2');
define('LIMPVIX_FIREBASE_API_KEY', 'AIzaSyA1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q');
```

---

## ✅ Passo 6: Validar Configuração

Execute o seguinte comando no terminal do container:

```bash
docker exec limpvix_wordpress php -r "
require_once '/var/www/html/wp-config.php';
echo 'Firebase Project ID: ' . (defined('LIMPVIX_FIREBASE_PROJECT_ID') ? LIMPVIX_FIREBASE_PROJECT_ID : 'NÃO CONFIGURADO') . PHP_EOL;
echo 'Firebase API Key: ' . (defined('LIMPVIX_FIREBASE_API_KEY') ? substr(LIMPVIX_FIREBASE_API_KEY, 0, 10) . '...' : 'NÃO CONFIGURADO') . PHP_EOL;
"
```

**Saída esperada:**
```
Firebase Project ID: limpvix-ab1cd2
Firebase API Key: AIzaSyA1B2...
```

---

## 🚨 Segurança

- ❌ **NUNCA** commite o wp-config.php no Git
- ❌ **NUNCA** compartilhe API Keys publicamente
- ✅ Use variáveis de ambiente em produção
- ✅ Restrinja API Key no Firebase Console (por domínio)

---

## 📚 Próximos Passos

Após configurar o Firebase, o módulo Briefing poderá usar o `FirebaseAuthAdapter` para:
1. Enviar SMS OTP para o telefone do cliente
2. Validar o código OTP inserido
3. Confirmar o número de telefone antes do pagamento

Para mais informações, consulte:
- `/src/Infrastructure/Adapters/FirebaseAuthAdapter.php` (será criado na FASE 3)
- Documentação oficial: https://firebase.google.com/docs/auth/web/phone-auth
