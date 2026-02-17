# 🏗️ Arquitetura MercadoPago - LimpVix

**Data:** 2026-02-16
**Autor:** Claude Code Assistant

---

## 🎯 Visão Geral

O LimpVix utiliza **dois sistemas MercadoPago distintos** com propósitos diferentes:

1. **WooCommerce MercadoPago** - Pagamentos de clientes (checkout)
2. **LimpVix OAuth MercadoPago** - Payouts para profissionais (MP→MP)

---

## 🔄 SISTEMA 1: WooCommerce MercadoPago (Pagamentos de Clientes)

### **Propósito:**
Processar pagamentos de clientes que contratam serviços via WooCommerce.

### **Plugin Utilizado:**
- Nome: `WooCommerce Mercado Pago`
- Path: `woocommerce-mercadopago/woocommerce-mercadopago.php`
- Desenvolvedor: Mercado Pago (plugin oficial)

### **Credenciais (Armazenadas pelo WooCommerce):**
```php
// Produção
_mp_access_token_prod
_mp_public_key_prod

// Teste
_mp_access_token_test
_mp_public_key_test

// Outros
_mp_client_id
_site_id_v1
_collector_id_v1
```

### **Sincronização Automática:**

O LimpVix detecta e sincroniza automaticamente as credenciais do WooCommerce:

**Classe:** `LimpVix\Admin\Settings\MercadoPagoDetector`

**Método:** `syncCredentials()`

**Processo:**
1. Detecta se plugin WooCommerce MP está instalado e ativo
2. Verifica se tem credenciais configuradas (`_mp_access_token_prod/test`)
3. Sincroniza para opções LimpVix com prefixo `limpvix_mp_`:
   ```php
   limpvix_mp_access_token_prod  // de _mp_access_token_prod
   limpvix_mp_public_key_prod    // de _mp_public_key_prod
   limpvix_mp_access_token_test  // de _mp_access_token_test
   limpvix_mp_public_key_test    // de _mp_public_key_test
   limpvix_mp_client_id          // de _mp_client_id
   limpvix_mp_site_id            // de _site_id_v1
   limpvix_mp_collector_id       // de _collector_id_v1
   ```

4. Atualiza status:
   ```php
   limpvix_mp_status = [
       'connected' => true,
       'source' => 'official_plugin',
       'last_sync' => time(),
       'environment' => 'test' // ou 'production'
   ]
   ```

**Sincronização Automática:**
- Cron job a cada 5 minutos
- Sincronização manual via botão "Verificar Sincronização"

---

## 💰 SISTEMA 2: LimpVix OAuth MercadoPago (Payouts Profissionais)

### **Propósito:**
Permitir que profissionais conectem suas próprias contas MercadoPago para receber payouts automáticos (transferências MP→MP).

### **Fluxo OAuth:**

```
┌─────────────────────────────────────────────────────────────┐
│ 1. CONFIGURAÇÃO PLATAFORMA (Admin - Uma vez)               │
├─────────────────────────────────────────────────────────────┤
│ Admin configura em: Configurações > Conexões > MP OAuth    │
│ ├─ Client ID: APP_USR-...                                  │
│ ├─ Client Secret: (secret)                                 │
│ └─ Salva em:                                               │
│    - limpvix_mercadopago_client_id                         │
│    - limpvix_mercadopago_client_secret                     │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ 2. PROFISSIONAL CONECTA (App React Native - Cada um)       │
├─────────────────────────────────────────────────────────────┤
│ Profissional no app:                                       │
│ ├─ Configurações de Payout                                │
│ ├─ Escolhe "MercadoPago OAuth (Automático)"               │
│ ├─ Clica "Conectar MercadoPago"                           │
│ │                                                          │
│ ├─ API: GET /professionals/{id}/mercadopago/connect       │
│ │  └─ Retorna: authorization_url                          │
│ │                                                          │
│ ├─ Redireciona para OAuth MercadoPago                     │
│ ├─ Profissional autoriza aplicação                        │
│ │                                                          │
│ ├─ Callback: GET /oauth/mercadopago/callback?code=...     │
│ │  └─ Troca code por access_token                         │
│ │  └─ Salva token no profissional (criptografado)         │
│ │  └─ Redireciona: /professional/mercadopago/connected    │
│ │                                                          │
│ └─ ✅ MercadoPago Conectado!                               │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ 3. PAYOUT AUTOMÁTICO (Sistema)                             │
├─────────────────────────────────────────────────────────────┤
│ Serviço Concluído → Feedback 5★ → Payout criado            │
│ ├─ Sistema verifica: mp_oauth_status = 'connected'         │
│ ├─ Usa access_token do profissional                        │
│ ├─ API MercadoPago: POST /v1/advanced_payments             │
│ └─ Transferência: Plataforma MP → Profissional MP          │
└─────────────────────────────────────────────────────────────┘
```

### **Credenciais OAuth LimpVix:**

**Configuração da Plataforma (Admin):**
```php
limpvix_mercadopago_client_id     // Client ID da aplicação OAuth
limpvix_mercadopago_client_secret // Client Secret da aplicação OAuth
```

**Por Profissional (Armazenado na tabela wp_limpvix_professionals):**
```sql
mp_access_token       VARCHAR(500)  -- Token OAuth do profissional (criptografado)
mp_refresh_token      VARCHAR(500)  -- Refresh token (criptografado)
mp_user_id            VARCHAR(100)  -- User ID do profissional no MP
mp_public_key         VARCHAR(200)  -- Public key do profissional
mp_oauth_connected_at DATETIME      -- Quando conectou
mp_oauth_expires_at   DATETIME      -- Quando token expira
mp_oauth_status       ENUM('connected', 'expired', 'revoked', 'not_connected')
```

---

## 📊 Comparação: Sistema 1 vs Sistema 2

| Aspecto | WooCommerce MP (Sistema 1) | LimpVix OAuth (Sistema 2) |
|---------|----------------------------|---------------------------|
| **Propósito** | Pagamentos de clientes | Payouts para profissionais |
| **Fluxo** | Cliente → Plataforma MP | Plataforma MP → Profissional MP |
| **Credenciais** | Access Token + Public Key da **plataforma** | Access Token do **profissional** (OAuth) |
| **Armazenamento** | `_mp_*` (WooCommerce) → `limpvix_mp_*` (sincronizado) | `limpvix_mercadopago_*` (Client ID/Secret) + Tabela professionals (tokens) |
| **Configuração** | Uma vez (admin configura no WooCommerce) | Por profissional (cada um conecta sua conta) |
| **Quem usa** | Plugin WooCommerce MP | LimpVix Core (REST API OAuth) |
| **Ambientes** | Test + Production (toggle) | Test + Production (por profissional) |

---

## 🔧 getMercadoPagoConfigStatus() - Método Corrigido

### **O que verifica:**

```php
private function getMercadoPagoConfigStatus(): array
{
    // 1. Verifica WooCommerce MercadoPago (Sistema 1)
    $wcMPConnected = MercadoPagoDetector::isOfficialPluginConnected();

    // 2. Verifica credenciais sincronizadas
    $environment = get_option('limpvix_mp_status')['environment'] ?? 'test';
    $accessToken = get_option("limpvix_mp_access_token_{$environment}");
    $publicKey = get_option("limpvix_mp_public_key_{$environment}");

    $platformConfigured = $wcMPConnected || (!empty($accessToken) && !empty($publicKey));

    // 3. Verifica OAuth LimpVix para profissionais (Sistema 2)
    $clientId = get_option('limpvix_mercadopago_client_id');
    $clientSecret = get_option('limpvix_mercadopago_client_secret');

    $oauthConfigured = !empty($clientId) && !empty($clientSecret);

    // 4. Status final
    $fullyConfigured = $platformConfigured && $oauthConfigured;

    return [
        'platform_configured' => $platformConfigured,  // Sistema 1 OK?
        'oauth_configured' => $oauthConfigured,        // Sistema 2 OK?
        'fully_configured' => $fullyConfigured,        // Ambos OK?
        'wc_mp_connected' => $wcMPConnected,           // WooCommerce MP ativo?
        'status_text' => $fullyConfigured
            ? 'Configurado e Ativo'
            : ($platformConfigured
                ? 'Configuração Parcial (Falta OAuth para Profissionais)'
                : 'Conecte WooCommerce MercadoPago'),
        'missing' => [...], // Lista credenciais faltando
    ];
}
```

### **Estados Possíveis:**

| WooCommerce MP | OAuth Profissionais | Status | Mensagem |
|----------------|---------------------|--------|----------|
| ❌ Não conectado | ❌ Não configurado | ⚠️ Pendente | "Conecte WooCommerce MercadoPago" |
| ✅ Conectado | ❌ Não configurado | ⚠️ Parcial | "Configuração Parcial (Falta OAuth para Profissionais)" |
| ❌ Não conectado | ✅ Configurado | ⚠️ Parcial | "WooCommerce MP não conectado" |
| ✅ Conectado | ✅ Configurado | ✅ Completo | "Configurado e Ativo" |

---

## 📋 Checklist de Configuração

### **Para Pagamentos de Clientes (Sistema 1):**
- [ ] Instalar plugin WooCommerce Mercado Pago
- [ ] Ativar plugin
- [ ] Conectar conta MercadoPago no WooCommerce
- [ ] Aguardar sincronização automática (5 min)
- [ ] Verificar em Settings > Pagamentos: "Status da Integração Mercado Pago: Conectado"

### **Para Payouts de Profissionais (Sistema 2):**
- [ ] Acessar: Configurações > Conexões > MercadoPago OAuth
- [ ] Configurar Client ID (da aplicação OAuth LimpVix)
- [ ] Configurar Client Secret
- [ ] Salvar
- [ ] Profissionais conectam no app React Native
- [ ] Verificar em Settings > Pagamentos: Status mostra "Configurado e Ativo"

---

## 🚀 REST API OAuth (Sistema 2)

### **Endpoints Implementados:**

**Controller:** `LimpVix\Infrastructure\API\ProfessionalOAuthController`

```
GET  /limpvix/v1/professionals/{id}/mercadopago/connect
     → Retorna authorization URL para profissional conectar

GET  /limpvix/v1/oauth/mercadopago/callback?code=...&state=...
     → Recebe callback OAuth, troca code por token, salva

POST /limpvix/v1/professionals/{id}/mercadopago/disconnect
     → Desconecta MercadoPago OAuth do profissional

GET  /limpvix/v1/professionals/{id}/payout-method
     → Retorna método de payout atual (mp_oauth ou pix_manual)

PUT  /limpvix/v1/professionals/{id}/payout-method
     → Altera método de payout do profissional
```

### **Fluxo no React Native:**

```javascript
// 1. Profissional clica "Conectar MercadoPago"
const response = await api.get(`/professionals/${profId}/mercadopago/connect`);
const authUrl = response.data.authorization_url;

// 2. Redireciona para OAuth
window.location.href = authUrl;

// 3. MercadoPago redireciona para callback
// GET /oauth/mercadopago/callback?code=XXX&state=YYY
// Backend processa e salva token

// 4. App verifica status
const status = await api.get(`/professionals/${profId}/payout-method`);
if (status.data.is_connected) {
    // ✅ MercadoPago conectado - Payouts automáticos!
}
```

---

## ⚠️ Correção Aplicada (2026-02-16)

### **Problema Identificado:**

O método `getMercadoPagoConfigStatus()` estava verificando credenciais erradas:

```php
// ❌ ANTES (ERRADO)
$accessToken = get_option('limpvix_mercadopago_access_token');  // Não existe!
$publicKey = get_option('limpvix_mercadopago_public_key');      // Não existe!
```

Isso causava:
- Status sempre mostrava "⚠ Configuração Pendente"
- Mesmo com WooCommerce MP conectado
- Ignorava credenciais sincronizadas

### **Solução Aplicada:**

```php
// ✅ DEPOIS (CORRETO)
$wcMPConnected = MercadoPagoDetector::isOfficialPluginConnected();
$environment = get_option('limpvix_mp_status')['environment'] ?? 'test';
$accessToken = get_option("limpvix_mp_access_token_{$environment}");
$publicKey = get_option("limpvix_mp_public_key_{$environment}");

$platformConfigured = $wcMPConnected || (!empty($accessToken) && !empty($publicKey));
```

Agora:
- ✅ Detecta WooCommerce MP conectado
- ✅ Usa credenciais sincronizadas corretas
- ✅ Diferencia Sistema 1 (plataforma) vs Sistema 2 (OAuth)
- ✅ Status preciso e informativo

---

## 📊 Arquivos Envolvidos

### **Sistema 1 (WooCommerce MP):**
```
src/Admin/Settings/MercadoPagoDetector.php
  └─ isOfficialPluginConnected()
  └─ getOfficialPluginCredentials()
  └─ syncCredentials()

src/Admin/Settings/MercadoPagoSettings.php
  └─ render() - Renderiza card "Status da Integração Mercado Pago"
  └─ renderConnectedDashboard() - Dashboard quando conectado
```

### **Sistema 2 (LimpVix OAuth):**
```
src/Infrastructure/API/ProfessionalOAuthController.php
  └─ getMercadoPagoConnectUrl()
  └─ handleMercadoPagoCallback()
  └─ disconnectMercadoPago()

src/Core/ProfessionalBootstrap.php
  └─ Registra ProfessionalOAuthController

src/Domain/Professional/Professional.php
  └─ connectMercadoPago()
  └─ disconnectMercadoPago()
  └─ isMercadoPagoConnected()
```

### **Verificação de Status (Aba Pagamentos):**
```
src/Admin/Bootstrap/AdminBootstrap.php
  └─ getMercadoPagoConfigStatus() - Método corrigido
  └─ renderPagamentosTab() - Usa método para renderizar status
```

---

## ✅ Conclusão

**Arquitetura Correta:**
1. **WooCommerce MercadoPago** → Pagamentos de clientes (plataforma)
2. **LimpVix OAuth MercadoPago** → Payouts profissionais (MP→MP)
3. **Sincronização Automática** → WooCommerce → LimpVix (a cada 5 min)
4. **Verificação Unificada** → `getMercadoPagoConfigStatus()` verifica ambos

**Status Final:**
- ✅ Método corrigido detecta WooCommerce MP corretamente
- ✅ Diferencia Sistema 1 (plataforma) vs Sistema 2 (OAuth)
- ✅ Mensagens claras sobre o que falta configurar
- ✅ 100% dinâmico e preciso

---

**Documentado por:** Claude Code Assistant
**Data:** 2026-02-16
