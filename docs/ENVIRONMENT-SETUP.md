# Environment Configuration - Guia Completo

**SPRINT 7 - Item 1.10 - Environment Configuration**

## 📋 Visão Geral

O LimpVix Core utiliza um sistema de variáveis de ambiente para separar configuração de código, permitindo diferentes configurações para development, staging e production sem alterar o código-fonte.

### Benefícios

✅ **Segurança**: Credenciais não ficam hardcoded no código
✅ **Portabilidade**: Deploy simplificado em diferentes ambientes
✅ **Manutenibilidade**: Configurações centralizadas em um só lugar
✅ **Flexibilidade**: Fácil alteração de configurações sem modificar código
✅ **Backward Compatibility**: Fallback para wp_options se .env não existir

---

## 🚀 Quick Start (3 passos)

### 1. Copiar arquivo de exemplo

```bash
cd wp-content/plugins/limpvix-core
cp .env.example .env
```

### 2. Editar .env com suas credenciais

```bash
nano .env  # ou vim, ou qualquer editor
```

Exemplo mínimo:
```env
LIMPVIX_ENV=development
LIMPVIX_DEBUG=true
LIMPVIX_MP_ENVIRONMENT=sandbox
```

### 3. Verificar se funcionou

Acesse o WordPress Admin e verifique os logs:

```bash
tail -f wp-content/debug.log | grep "Environment"
```

Você deve ver:
```
[LimpVix Core] Environment loaded: development (Debug: YES)
```

---

## 📁 Estrutura de Arquivos

```
limpvix-core/
├── .env                # Suas configurações (NUNCA commitar!)
├── .env.example        # Template com valores de exemplo
├── .gitignore          # Garante que .env não é commitado
├── src/
│   └── Core/
│       ├── Environment.php  # Classe gerenciadora
│       └── Kernel.php       # Carrega Environment no boot
└── docs/
    └── ENVIRONMENT-SETUP.md  # Este arquivo
```

---

## ⚙️ Variáveis Disponíveis

### 🌍 Environment & Debug

#### `LIMPVIX_ENV`
**Tipo:** string
**Valores:** `development`, `staging`, `production`
**Default:** `development`
**Descrição:** Define o ambiente de execução

```env
# Development (local)
LIMPVIX_ENV=development

# Staging (servidor de testes)
LIMPVIX_ENV=staging

# Production (servidor final)
LIMPVIX_ENV=production
```

**Impacto:**
- `development`: Debug ativo, logs verbosos, validações relaxadas
- `staging`: Debug ativo, logs verbosos, validações normais
- `production`: Debug desativado, logs mínimos, validações estritas

#### `LIMPVIX_DEBUG`
**Tipo:** boolean
**Valores:** `true`, `false`
**Default:** `false`
**Descrição:** Ativa/desativa modo debug

```env
LIMPVIX_DEBUG=true   # Ativa logs detalhados
LIMPVIX_DEBUG=false  # Desativa logs (produção)
```

**IMPORTANTE:** Sempre `false` em produção!

#### `LIMPVIX_LOG_LEVEL`
**Tipo:** string
**Valores:** `error`, `warning`, `info`, `debug`
**Default:** `info`
**Descrição:** Nível de logging

```env
LIMPVIX_LOG_LEVEL=debug  # Todos os logs
LIMPVIX_LOG_LEVEL=error  # Apenas erros
```

---

### 💳 Mercado Pago (Payment Gateway)

#### `LIMPVIX_MP_ACCESS_TOKEN_PROD`
**Tipo:** string (secret)
**Descrição:** Access token de produção do Mercado Pago

```env
LIMPVIX_MP_ACCESS_TOKEN_PROD=APP-1234567890ABCDEF
```

#### `LIMPVIX_MP_ACCESS_TOKEN_TEST`
**Tipo:** string (secret)
**Descrição:** Access token de teste/sandbox do Mercado Pago

```env
LIMPVIX_MP_ACCESS_TOKEN_TEST=TEST-1234567890ABCDEF
```

#### `LIMPVIX_MP_PUBLIC_KEY_PROD`
**Tipo:** string
**Descrição:** Public key de produção do Mercado Pago

```env
LIMPVIX_MP_PUBLIC_KEY_PROD=APP-1234567890ABCDEF
```

#### `LIMPVIX_MP_PUBLIC_KEY_TEST`
**Tipo:** string
**Descrição:** Public key de teste do Mercado Pago

```env
LIMPVIX_MP_PUBLIC_KEY_TEST=TEST-1234567890ABCDEF
```

#### `LIMPVIX_MP_ENVIRONMENT`
**Tipo:** string
**Valores:** `sandbox`, `production`
**Default:** `sandbox`
**Descrição:** Qual ambiente do Mercado Pago usar

```env
# Testing
LIMPVIX_MP_ENVIRONMENT=sandbox

# Production
LIMPVIX_MP_ENVIRONMENT=production
```

**IMPORTANTE:**
- Sandbox usa `*_TEST` credentials
- Production usa `*_PROD` credentials
- Se vazio, busca de `wp_options` (sincronizado do WooCommerce MP)

---

### 📱 Twilio (SMS Notifications)

#### `LIMPVIX_TWILIO_ACCOUNT_SID`
**Tipo:** string (secret)
**Descrição:** Account SID do Twilio (inicia com `AC...`)

```env
LIMPVIX_TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

#### `LIMPVIX_TWILIO_AUTH_TOKEN`
**Tipo:** string (secret)
**Descrição:** Auth Token do Twilio

```env
LIMPVIX_TWILIO_AUTH_TOKEN=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

#### `LIMPVIX_TWILIO_FROM_NUMBER`
**Tipo:** string
**Formato:** `+5521999999999`
**Descrição:** Número de telefone Twilio para envio

```env
LIMPVIX_TWILIO_FROM_NUMBER=+5521999999999
```

#### `LIMPVIX_TWILIO_ENABLED`
**Tipo:** boolean
**Default:** `false`
**Descrição:** Ativa/desativa notificações via SMS

```env
LIMPVIX_TWILIO_ENABLED=true
```

---

### 💬 360Dialog (WhatsApp Business API)

#### `LIMPVIX_360DIALOG_API_KEY`
**Tipo:** string (secret)
**Descrição:** API Key do 360Dialog

```env
LIMPVIX_360DIALOG_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

#### `LIMPVIX_360DIALOG_PHONE_ID`
**Tipo:** string
**Descrição:** Phone Number ID do WhatsApp Business

```env
LIMPVIX_360DIALOG_PHONE_ID=123456789012345
```

#### `LIMPVIX_360DIALOG_ENABLED`
**Tipo:** boolean
**Default:** `false`
**Descrição:** Ativa/desativa notificações via WhatsApp

```env
LIMPVIX_360DIALOG_ENABLED=true
```

---

### ⭐ Google Meu Negócio (Reviews)

#### `LIMPVIX_GMB_PLACE_ID`
**Tipo:** string
**Descrição:** Place ID do Google Meu Negócio

```env
LIMPVIX_GMB_PLACE_ID=ChIJxxxxxxxxxxxxxx
```

#### `LIMPVIX_GMB_REVIEW_URL`
**Tipo:** string (URL)
**Descrição:** URL curta para reviews

```env
LIMPVIX_GMB_REVIEW_URL=https://g.page/limpvix/review
```

#### `LIMPVIX_GMB_ENABLED`
**Tipo:** boolean
**Default:** `false`
**Descrição:** Ativa/desativa integração GMB

```env
LIMPVIX_GMB_ENABLED=true
```

---

### 🎛️ Feature Flags

#### `LIMPVIX_FEATURE_AUTO_PAYOUTS`
**Tipo:** boolean
**Default:** `false`
**Descrição:** Ativa payouts automáticos

```env
# Development: sempre false (segurança)
LIMPVIX_FEATURE_AUTO_PAYOUTS=false

# Production: true após testes
LIMPVIX_FEATURE_AUTO_PAYOUTS=true
```

**ATENÇÃO:** Testar extensivamente antes de ativar em produção!

#### `LIMPVIX_FEATURE_RECURRING_CONTRACTS`
**Tipo:** boolean
**Default:** `false`
**Descrição:** Ativa contratos recorrentes

```env
LIMPVIX_FEATURE_RECURRING_CONTRACTS=true
```

#### `LIMPVIX_FEATURE_FEEDBACK_WINDOW`
**Tipo:** boolean
**Default:** `false`
**Descrição:** Ativa sistema de feedback window

```env
LIMPVIX_FEATURE_FEEDBACK_WINDOW=true
```

#### `LIMPVIX_FEATURE_ADMIN_NOTIFICATIONS`
**Tipo:** boolean
**Default:** `true`
**Descrição:** Notificações para admins

```env
LIMPVIX_FEATURE_ADMIN_NOTIFICATIONS=true
```

---

### ⏰ Cron Jobs

#### `LIMPVIX_CRON_ENABLED`
**Tipo:** boolean
**Default:** `true`
**Descrição:** Ativa WP Cron (desativar se usar system cron)

```env
# WP Cron (default)
LIMPVIX_CRON_ENABLED=true

# System Cron (via crontab)
LIMPVIX_CRON_ENABLED=false
```

#### `LIMPVIX_CRON_TIMEOUT`
**Tipo:** int (seconds)
**Default:** `300`
**Descrição:** Timeout máximo para cron jobs

```env
LIMPVIX_CRON_TIMEOUT=300  # 5 minutos
```

#### `LIMPVIX_CRON_MAX_AGE_DAILY`
**Tipo:** int (hours)
**Default:** `25`
**Descrição:** Idade máxima para considerar daily jobs healthy

```env
LIMPVIX_CRON_MAX_AGE_DAILY=25  # 25 horas (grace period)
```

---

### 🔐 Security

#### `LIMPVIX_JWT_SECRET`
**Tipo:** string (secret)
**Descrição:** Secret para JWT tokens (se API autenticada)

```env
LIMPVIX_JWT_SECRET=your-super-secret-key-min-32-chars
```

**Gerar secret seguro:**
```bash
openssl rand -base64 32
```

#### `LIMPVIX_CORS_ENABLED`
**Tipo:** boolean
**Default:** `false`
**Descrição:** Ativa CORS para API REST

```env
LIMPVIX_CORS_ENABLED=false  # Desativado por padrão (segurança)
```

**ATENÇÃO:** Só ativar se API for acessada de domínios externos!

#### `LIMPVIX_CORS_ORIGINS`
**Tipo:** string (comma-separated)
**Descrição:** Origens permitidas para CORS

```env
LIMPVIX_CORS_ORIGINS=https://app.limpvix.com.br,https://admin.limpvix.com.br
```

---

### 🧪 Testing

#### `LIMPVIX_TEST_MODE`
**Tipo:** boolean
**Default:** `false`
**Descrição:** Ativa modo de teste (bypassa validações)

```env
LIMPVIX_TEST_MODE=true  # NUNCA ativar em produção!
```

**ATENÇÃO:** NUNCA ativar em produção! Apenas para testes automatizados.

#### `LIMPVIX_SEED_TEST_DATA`
**Tipo:** boolean
**Default:** `false`
**Descrição:** Seed database com dados de teste

```env
LIMPVIX_SEED_TEST_DATA=true  # Apenas em development
```

---

## 🎓 Como Usar no Código

### Obtendo Valores

```php
use LimpVix\Core\Environment;

// Obter string (exception se não existir)
$apiKey = Environment::get('LIMPVIX_MP_ACCESS_TOKEN_PROD');

// Obter com default
$timeout = Environment::get('LIMPVIX_CRON_TIMEOUT', '300');

// Verificar se existe
if (Environment::has('LIMPVIX_SLACK_WEBHOOK_URL')) {
    $webhookUrl = Environment::get('LIMPVIX_SLACK_WEBHOOK_URL');
    // Enviar notificação...
}
```

### Type Casting

```php
// Boolean
$isDebug = Environment::getBool('LIMPVIX_DEBUG');  // true/false

// Integer
$timeout = Environment::getInt('LIMPVIX_CRON_TIMEOUT', 300);  // 300

// Float
$rate = Environment::getFloat('LIMPVIX_COMMISSION_RATE', 0.15);  // 0.15

// Array (comma-separated)
$origins = Environment::getArray('LIMPVIX_CORS_ORIGINS');
// ['https://app.limpvix.com.br', 'https://admin.limpvix.com.br']
```

### Helpers de Ambiente

```php
// Verificar ambiente
$env = Environment::getEnvironment();  // 'development', 'staging', 'production'

// Helpers booleanos
if (Environment::isDevelopment()) {
    // Código apenas para dev
}

if (Environment::isProduction()) {
    // Código apenas para produção
}

if (Environment::isDebug()) {
    error_log('Debug message');
}

if (Environment::isTestMode()) {
    // ATENÇÃO: Nunca deve ser true em produção!
}
```

### Validação de Variáveis Obrigatórias

```php
try {
    Environment::validate([
        'LIMPVIX_MP_ACCESS_TOKEN_PROD',
        'LIMPVIX_MP_PUBLIC_KEY_PROD',
    ]);
    // Todas variáveis existem
} catch (\RuntimeException $e) {
    // Variáveis faltando
    error_log('Missing environment variables: ' . $e->getMessage());
}
```

---

## 🔧 Configurações por Ambiente

### Development (Local)

```env
# .env (development)
LIMPVIX_ENV=development
LIMPVIX_DEBUG=true
LIMPVIX_LOG_LEVEL=debug
LIMPVIX_LOG_QUERIES=true

# MercadoPago: sandbox
LIMPVIX_MP_ENVIRONMENT=sandbox
LIMPVIX_MP_ACCESS_TOKEN_TEST=TEST-xxxxxxxxxx

# Features: experimentais ativadas
LIMPVIX_FEATURE_AUTO_PAYOUTS=false  # Segurança: sempre false
LIMPVIX_FEATURE_RECURRING_CONTRACTS=true
LIMPVIX_FEATURE_FEEDBACK_WINDOW=true

# Testing
LIMPVIX_TEST_MODE=true  # OK em dev
LIMPVIX_SEED_TEST_DATA=true
```

### Staging (Servidor de Testes)

```env
# .env (staging)
LIMPVIX_ENV=staging
LIMPVIX_DEBUG=true
LIMPVIX_LOG_LEVEL=info
LIMPVIX_LOG_QUERIES=false

# MercadoPago: ainda sandbox
LIMPVIX_MP_ENVIRONMENT=sandbox
LIMPVIX_MP_ACCESS_TOKEN_TEST=TEST-xxxxxxxxxx

# Features: mesmas de produção (para testar)
LIMPVIX_FEATURE_AUTO_PAYOUTS=false  # Testar antes de ativar
LIMPVIX_FEATURE_RECURRING_CONTRACTS=true
LIMPVIX_FEATURE_FEEDBACK_WINDOW=true

# Testing
LIMPVIX_TEST_MODE=false  # Desativado (simular produção)
LIMPVIX_SEED_TEST_DATA=false
```

### Production (Servidor Final)

```env
# .env (production)
LIMPVIX_ENV=production
LIMPVIX_DEBUG=false  # CRÍTICO: sempre false
LIMPVIX_LOG_LEVEL=error
LIMPVIX_LOG_QUERIES=false

# MercadoPago: production credentials
LIMPVIX_MP_ENVIRONMENT=production
LIMPVIX_MP_ACCESS_TOKEN_PROD=APP-xxxxxxxxxx
LIMPVIX_MP_PUBLIC_KEY_PROD=APP-xxxxxxxxxx

# Features: apenas testadas e aprovadas
LIMPVIX_FEATURE_AUTO_PAYOUTS=true  # Após testes extensivos
LIMPVIX_FEATURE_RECURRING_CONTRACTS=true
LIMPVIX_FEATURE_FEEDBACK_WINDOW=true
LIMPVIX_FEATURE_ADMIN_NOTIFICATIONS=true

# Cron: monitoramento externo
LIMPVIX_HEALTHCHECKS_PING_URL=https://hc-ping.com/xxxxxx
LIMPVIX_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/xxx

# Testing
LIMPVIX_TEST_MODE=false  # CRÍTICO: sempre false
LIMPVIX_SEED_TEST_DATA=false  # CRÍTICO: sempre false

# Security
LIMPVIX_JWT_SECRET=your-production-secret-here
LIMPVIX_CORS_ENABLED=false
```

---

## 🛡️ Segurança - Best Practices

### ✅ DO (Fazer)

1. **Sempre adicionar .env ao .gitignore**
   ```gitignore
   .env
   .env.local
   .env.*.local
   ```

2. **Usar credenciais diferentes por ambiente**
   - Development: Test credentials
   - Staging: Test credentials (diferentes de dev)
   - Production: Production credentials (únicas)

3. **Rotacionar secrets regularmente**
   - API keys: a cada 90 dias
   - Tokens: a cada 30 dias

4. **Usar secret managers em produção**
   - AWS Secrets Manager
   - Google Cloud Secret Manager
   - HashiCorp Vault

5. **Validar valores em produção**
   ```php
   if (Environment::isProduction()) {
       Environment::validate(['CRITICAL_VAR']);
   }
   ```

6. **Gerar secrets aleatórios**
   ```bash
   # Gerar JWT secret
   openssl rand -base64 32

   # Gerar API key
   openssl rand -hex 32
   ```

### ❌ DON'T (Não Fazer)

1. **NUNCA commitar .env**
   - Usar `.gitignore` para prevenir

2. **NUNCA compartilhar .env**
   - Cada desenvolvedor tem seu próprio
   - Cada ambiente tem seu próprio

3. **NUNCA usar credenciais de produção em dev/staging**
   - Sempre usar credentials separadas

4. **NUNCA logar valores sensíveis**
   ```php
   // ERRADO:
   error_log('API Key: ' . $apiKey);

   // CORRETO:
   error_log('API Key configured: ' . (empty($apiKey) ? 'NO' : 'YES'));
   ```

5. **NUNCA habilitar LIMPVIX_TEST_MODE em produção**
   - Bypass de validações é perigoso

6. **NUNCA desabilitar LIMPVIX_DB_TRANSACTIONS em produção**
   - Perda de consistência de dados

---

## 🔄 Hierarchy & Fallback

A busca de variáveis segue esta hierarquia (priority order):

```
1. .env file              (highest priority)
   ↓
2. PHP Constants          (LIMPVIX_*)
   ↓
3. wp_options             (fallback - backward compatibility)
   ↓
4. Default value          (se fornecido)
   ↓
5. Exception              (se nada encontrado e sem default)
```

### Exemplo Prático

```php
// Cenário: buscar LIMPVIX_MP_ACCESS_TOKEN_PROD

// 1. Busca no .env:
//    LIMPVIX_MP_ACCESS_TOKEN_PROD=APP-from-env
//    ✅ ENCONTRADO → retorna "APP-from-env"

// Se não existir no .env:

// 2. Busca em PHP constant:
//    define('LIMPVIX_MP_ACCESS_TOKEN_PROD', 'APP-from-constant');
//    ✅ ENCONTRADO → retorna "APP-from-constant"

// Se não existir como constant:

// 3. Busca em wp_options:
//    get_option('limpvix_mp_access_token_prod'); // 'APP-from-db'
//    ✅ ENCONTRADO → retorna "APP-from-db"

// Se não existir em wp_options:

// 4. Usa default (se fornecido):
//    Environment::get('LIMPVIX_MP_ACCESS_TOKEN_PROD', 'default-value');
//    ✅ retorna "default-value"

// 5. Se não há default:
//    Environment::get('LIMPVIX_MP_ACCESS_TOKEN_PROD');
//    ❌ EXCEPTION: "Environment variable not found"
```

**Vantagem:** Backward compatibility sem quebrar sistemas existentes!

---

## 🧪 Testing

### Verificar se .env está carregando

```bash
# Ativar WP_DEBUG
wp config set WP_DEBUG true --raw

# Ver logs
tail -f wp-content/debug.log | grep "Environment"
```

Saída esperada:
```
[LimpVix Core] Environment loaded: development (Debug: YES)
[LimpVix Core] Environment initialized
```

### Testar via WP-CLI

```bash
# Verificar ambiente
wp eval 'echo \LimpVix\Core\Environment::getEnvironment();'
# Output: development

# Verificar debug
wp eval 'echo \LimpVix\Core\Environment::isDebug() ? "YES" : "NO";'
# Output: YES

# Verificar variável
wp eval 'echo \LimpVix\Core\Environment::get("LIMPVIX_MP_ENVIRONMENT", "not-set");'
# Output: sandbox (ou "not-set" se não configurado)
```

### Testar via código

```php
// Adicionar em qualquer arquivo temporário
add_action('init', function() {
    error_log('Environment: ' . \LimpVix\Core\Environment::getEnvironment());
    error_log('Debug: ' . (\LimpVix\Core\Environment::isDebug() ? 'YES' : 'NO'));

    $vars = \LimpVix\Core\Environment::all();
    error_log('Loaded ' . count($vars) . ' variables from .env');
}, 1);
```

---

## 🐛 Troubleshooting

### Problema: .env não está sendo carregado

**Sintomas:**
- Variáveis retornam defaults
- Logs não mencionam "Environment loaded"

**Soluções:**

1. **Verificar se .env existe**
   ```bash
   ls -la wp-content/plugins/limpvix-core/.env
   ```

2. **Verificar permissões**
   ```bash
   chmod 644 wp-content/plugins/limpvix-core/.env
   ```

3. **Verificar path no Environment::load()**
   - Default: `plugin_root/.env`
   - Se mudou estrutura, ajustar path

4. **Verificar syntax do .env**
   - Cada linha: `KEY=VALUE`
   - Sem espaços extras: `KEY=VALUE` (não `KEY = VALUE`)
   - Comentários com `#`

---

### Problema: Variáveis não estão sendo lidas

**Sintomas:**
- Exception: "Environment variable not found"
- Fallback para wp_options mesmo com .env

**Soluções:**

1. **Verificar nome exato da variável**
   ```php
   // ERRADO:
   Environment::get('LIMPVIX_mp_access_token');  // lowercase

   // CORRETO:
   Environment::get('LIMPVIX_MP_ACCESS_TOKEN_PROD');  // exact match
   ```

2. **Verificar se variável está no .env**
   ```bash
   grep "LIMPVIX_MP" wp-content/plugins/limpvix-core/.env
   ```

3. **Recarregar Environment**
   ```php
   Environment::clearCache();
   Environment::load();
   ```

---

### Problema: Debug não desativa em produção

**Sintomas:**
- Logs aparecem mesmo com `LIMPVIX_DEBUG=false`
- Performance ruim

**Soluções:**

1. **Verificar WP_DEBUG no wp-config.php**
   ```php
   // Em wp-config.php, adicionar:
   define('WP_DEBUG', false);  // Produção
   ```

2. **Verificar Environment::isDebug()**
   - Prioriza WP_DEBUG se definido
   - Depois verifica LIMPVIX_DEBUG

3. **Limpar caches**
   ```bash
   wp cache flush
   wp opcache clear  # Se OPcache ativo
   ```

---

### Problema: MercadoPago não usa credenciais do .env

**Sintomas:**
- Ainda usa credenciais de wp_options
- Logs mostram "Using token from wp_options"

**Soluções:**

1. **Verificar nome exato da variável**
   ```env
   # CORRETO:
   LIMPVIX_MP_ACCESS_TOKEN_PROD=APP-xxxxx
   LIMPVIX_MP_ACCESS_TOKEN_TEST=TEST-xxxxx

   # ERRADO:
   LIMPVIX_MERCADOPAGO_ACCESS_TOKEN=xxx  # nome diferente
   ```

2. **Verificar se MercadoPagoPayoutProvider foi refatorado**
   - Deve usar `Environment::get()` internamente

3. **Ver logs específicos**
   ```bash
   tail -f wp-content/debug.log | grep "MercadoPago"
   ```

   Procurar por:
   ```
   [LimpVix] Using MercadoPago token from .env (LIMPVIX_MP_ACCESS_TOKEN_PROD)
   ```

---

## 📚 Referências

- [The Twelve-Factor App - III. Config](https://12factor.net/config)
- [WordPress wp-config.php](https://developer.wordpress.org/apis/wp-config-php/)
- [PHP dotenv library](https://github.com/vlucas/phpdotenv)

---

## ✅ Checklist de Go-Live

Antes de ir para produção, verificar:

### .env Configuration
- [ ] Arquivo `.env` existe e está populado
- [ ] Variável `LIMPVIX_ENV=production`
- [ ] Variável `LIMPVIX_DEBUG=false`
- [ ] Variável `LIMPVIX_TEST_MODE=false`
- [ ] Credenciais de produção configuradas (MP, Twilio, etc)
- [ ] `.env` NÃO está commitado no git
- [ ] `.env` tem permissões corretas (`chmod 644`)

### Security
- [ ] Secrets rotacionados (não usar credentials de staging)
- [ ] JWT secret gerado (min 32 chars)
- [ ] CORS desabilitado (ou apenas origens necessárias)
- [ ] WP_DEBUG false no wp-config.php
- [ ] Error display desabilitado (wp-config.php)

### Testing
- [ ] Testar carregamento de .env (ver logs)
- [ ] Testar cada integração (MP, Twilio, etc)
- [ ] Verificar logs não expõem secrets
- [ ] Performance OK (Environment cached)

### Monitoring
- [ ] Health check endpoint funcionando
- [ ] Monitoring externo configurado (UptimeRobot/Pingdom)
- [ ] Alertas configurados (email/Slack)
- [ ] Cron jobs monitorados

### Documentation
- [ ] Team sabe onde está `.env` em produção
- [ ] Processo de atualização de secrets documentado
- [ ] Backup de .env em local seguro (secret manager)

---

**Fim do Guia de Configuração**

**SPRINT 7 - Item 1.10 Completo ✅**
**Data:** 2026-02-10
**Autor:** Claude Code + LimpVix Development Team
