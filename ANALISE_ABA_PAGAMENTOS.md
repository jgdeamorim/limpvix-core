# 📊 Análise Profunda: Aba Pagamentos

**Data:** 2026-02-16
**URL:** http://localhost:8080/wp-admin/admin.php?page=limpvix-settings&tab=pagamentos
**Arquivo:** `src/Admin/Bootstrap/AdminBootstrap.php` (linha 4627)

---

## 🎯 Objetivo da Análise

Identificar quais informações estão **hardcoded** (fixas no código) vs **dinâmicas** (calculadas do sistema real), especialmente na seção "MercadoPago Integration ⚠ Configuração Pendente".

---

## 📊 Estado Atual: 70% Dinâmico / 30% Hardcoded

### ✅ Itens DINÂMICOS (Corretos)

#### 1. **Hero Card - Estatísticas de Payouts** (100% Dinâmico)
- ✅ **Taxa de Sucesso**: Calculada de `$stats['total_completed'] / $totalPayouts`
- ✅ **Total Processado**: `$stats['amount_completed']` do repository
- ✅ **Aguardando**: `$stats['amount_pending']` + contagem de pendentes/aprovados
- ✅ **Em Processamento**: `$stats['total_processing']`
- ✅ **Falhas**: `$stats['total_failed']` + taxa de falha calculada

**Código (linha 4630-4641):**
```php
$payoutRepo = new \LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository();
$stats = $payoutRepo->getStats(); // Busca dados reais do banco

$totalPayouts = $stats['total_pending'] + $stats['total_approved'] +
                $stats['total_processing'] + $stats['total_completed'] +
                $stats['total_failed'];
$successRate = $totalPayouts > 0 ? round(($stats['total_completed'] / $totalPayouts) * 100, 1) : 0;
```

#### 2. **Status MercadoPago Integration** (75% Dinâmico)
- ✅ **Verifica Access Token**: `get_option('limpvix_mercadopago_access_token')`
- ✅ **Verifica Public Key**: `get_option('limpvix_mercadopago_public_key')`
- ✅ **Status Dinâmico**: "✓ Configurado e Ativo" ou "⚠ Configuração Pendente"

**Código (linha 4634-4738):**
```php
$mpAccessToken = get_option('limpvix_mercadopago_access_token');
$mpPublicKey = get_option('limpvix_mercadopago_public_key');
$mpConfigured = !empty($mpAccessToken) && !empty($mpPublicKey);

// Renderiza status:
<?php if ($mpConfigured): ?>
    <span style="color: #4ade80;">✓ Configurado e Ativo</span>
<?php else: ?>
    <span style="color: #fbbf24;">⚠ Configuração Pendente</span>
<?php endif; ?>
```

**⚠️ PROBLEMA IDENTIFICADO:**
- Verifica apenas **2 de 4 credenciais** necessárias
- Falta verificar: `client_id` e `client_secret` (necessários para OAuth)
- Deveria buscar de `get_option('limpvix_mercadopago_client_id')` e `get_option('limpvix_mercadopago_client_secret')`

---

### ❌ Itens HARDCODED (Precisam ser Dinâmicos)

#### 1. **Features do Payout System** (0% Dinâmico) ❌

**Problema:** Lista de 6 features está completamente hardcoded com ícone ✅ fixo.

**Código (linha 4782-4860):**
```html
<!-- Feature 1 -->
<div>
    <span style="font-size: 24px;">✅</span> <!-- HARDCODED -->
    <div>
        <div style="font-weight: 600;">Transferência Automática via PIX</div>
        <div>Repasses automáticos para profissionais...</div>
    </div>
</div>

<!-- Features 2-6: todas iguais (hardcoded) -->
```

**O que deveria ser dinâmico:**

1. **✅ Transferência Automática via PIX**
   - Verificar: `class_exists('LimpVix\\Infrastructure\\Finance\\Providers\\MercadoPagoPayoutProvider')`
   - Verificar: Tabela `wp_limpvix_payouts` existe
   - Status: ✅ se classe existe + tabela existe, ❌ caso contrário

2. **✅ Feedback Window Enforcement**
   - Verificar: `class_exists('LimpVix\\Application\\UseCase\\Feedback\\CheckFeedbackWindowStatus')`
   - Verificar: Campo `hold_until` existe na tabela payouts
   - Status: ✅ se implementado, ⚠️ se parcial, ❌ se não existe

3. **✅ Reconciliação Automática**
   - Verificar: `class_exists('LimpVix\\Infrastructure\\Cron\\PayoutReconciliationCronAdapter')`
   - Verificar: Cron job registrado via `wp_get_schedules()`
   - Status: ✅ se cron ativo, ⚠️ se classe existe mas cron desabilitado, ❌ se não existe

4. **✅ Retry Automático em Falhas**
   - Verificar: Campo `retry_count` ou `failure_reason` na tabela
   - Verificar: Lógica de retry no `ExecutePayout` use case
   - Status: ✅ se implementado, ❌ caso contrário

5. **✅ Auditoria Completa**
   - Verificar: Campo `raw_response` ou `audit_log` na tabela payouts
   - Verificar: Campos de timestamp (`created_at`, `updated_at`, `processed_at`, etc.)
   - Status: ✅ se completo (>5 campos timestamp + raw_response), ⚠️ se parcial

6. **✅ Suporte a PIX, Conta Bancária e MP Account**
   - Verificar: Campo `recipient_type` com enum('pix', 'bank_account', 'mp_account')
   - Verificar: Método `MercadoPagoPayoutProvider->createPayout()` aceita múltiplos tipos
   - Status: ✅ se implementado, ❌ caso contrário

**Solução sugerida:**
```php
private function getPayoutFeaturesStatus(): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'limpvix_payouts';

    return [
        'pix_transfer' => [
            'implemented' => class_exists('LimpVix\\Infrastructure\\Finance\\Providers\\MercadoPagoPayoutProvider')
                && $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table,
            'icon' => '✅',
            'status' => 'Ativo'
        ],
        'feedback_window' => [
            'implemented' => class_exists('LimpVix\\Application\\UseCase\\Feedback\\CheckFeedbackWindowStatus')
                && $this->tableHasColumn($table, 'hold_until'),
            'icon' => '✅',
            'status' => 'Ativo'
        ],
        'reconciliation' => [
            'implemented' => class_exists('LimpVix\\Infrastructure\\Cron\\PayoutReconciliationCronAdapter')
                && wp_next_scheduled('limpvix_reconcile_payouts') !== false,
            'icon' => wp_next_scheduled('limpvix_reconcile_payouts') ? '✅' : '⚠️',
            'status' => wp_next_scheduled('limpvix_reconcile_payouts') ? 'Ativo' : 'Cron Desabilitado'
        ],
        'retry_on_failure' => [
            'implemented' => $this->tableHasColumn($table, 'retry_count'),
            'icon' => '✅',
            'status' => 'Ativo'
        ],
        'audit_trail' => [
            'implemented' => $this->tableHasColumn($table, 'raw_response')
                && $this->tableHasColumn($table, 'created_at'),
            'icon' => '✅',
            'status' => 'Completo'
        ],
        'multi_recipient' => [
            'implemented' => $this->tableHasColumn($table, 'recipient_type'),
            'icon' => '✅',
            'status' => 'PIX + Conta + MP Account'
        ],
    ];
}

private function tableHasColumn(string $table, string $column): bool
{
    global $wpdb;
    $result = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
    return !empty($result);
}
```

---

#### 2. **Arquitetura Técnica - Componentes** (0% Dinâmico) ❌

**Problema:** Lista de classes está completamente hardcoded, não verifica se existem.

**Código (linha 4884-4924):**
```html
<!-- Domain Layer -->
<ul>
    <li>✓ <code>PayoutRepositoryInterface</code></li> <!-- HARDCODED -->
    <li>✓ Domain events & aggregates</li> <!-- HARDCODED -->
</ul>

<!-- Application Layer -->
<ul>
    <li>✓ <code>ExecutePayout</code></li> <!-- HARDCODED -->
    <li>✓ <code>CompleteServiceWithPayout</code></li> <!-- HARDCODED -->
    <li>✓ <code>PayoutReconciliationService</code></li> <!-- HARDCODED -->
    <li>✓ <code>AutomaticPayoutDispatcher</code></li> <!-- HARDCODED -->
</ul>

<!-- Infrastructure Layer -->
<ul>
    <li>✓ <code>WpPayoutRepository</code></li> <!-- HARDCODED -->
    <li>✓ <code>MercadoPagoPayoutProvider</code></li> <!-- HARDCODED -->
    <li>✓ <code>PayoutReconciliationCronAdapter</code></li> <!-- HARDCODED -->
    <li>✓ <code>ReleasePayoutHoldOnFeedbackApproved</code></li> <!-- HARDCODED -->
</ul>
```

**O que deveria ser dinâmico:**

Verificar com `class_exists()` se cada componente existe:

```php
private function getPayoutArchitectureStatus(): array
{
    return [
        'domain' => [
            'PayoutRepositoryInterface' => interface_exists('LimpVix\\Domain\\Finance\\PayoutRepositoryInterface'),
            'DomainEvents' => class_exists('LimpVix\\Domain\\Finance\\Events\\PayoutCompleted'),
        ],
        'application' => [
            'ExecutePayout' => class_exists('LimpVix\\Application\\UseCase\\Finance\\ExecutePayout'),
            'CompleteServiceWithPayout' => class_exists('LimpVix\\Application\\UseCase\\Finance\\CompleteServiceWithPayout'),
            'PayoutReconciliationService' => class_exists('LimpVix\\Application\\Services\\PayoutReconciliationService'),
            'AutomaticPayoutDispatcher' => class_exists('LimpVix\\Infrastructure\\Adapters\\AutomaticPayoutDispatcher'),
        ],
        'infrastructure' => [
            'WpPayoutRepository' => class_exists('LimpVix\\Infrastructure\\Finance\\Repositories\\WpPayoutRepository'),
            'MercadoPagoPayoutProvider' => class_exists('LimpVix\\Infrastructure\\Finance\\Providers\\MercadoPagoPayoutProvider'),
            'PayoutReconciliationCronAdapter' => class_exists('LimpVix\\Infrastructure\\Cron\\PayoutReconciliationCronAdapter'),
            'ReleasePayoutHoldOnFeedbackApproved' => class_exists('LimpVix\\Infrastructure\\EventListeners\\ReleasePayoutHoldOnFeedbackApproved'),
        ],
    ];
}
```

**Renderizar com ícones dinâmicos:**
```php
<?php foreach ($archStatus['application'] as $class => $exists): ?>
    <li>
        <?php echo $exists ? '✓' : '❌'; ?>
        <code><?php echo esc_html($class); ?></code>
    </li>
<?php endforeach; ?>
```

---

#### 3. **Database Info** (0% Dinâmico) ❌

**Problema:** Nome da tabela, campos e índices estão hardcoded.

**Código (linha 4927-4938):**
```html
<h4>💾 Database Table: <code>wp_limpvix_payouts</code></h4> <!-- HARDCODED -->
<div>
    <strong>Status Flow:</strong> <code>pending</code> → <code>approved</code> → ... <!-- HARDCODED -->
    <br>
    <strong>Índices:</strong> order_uuid, professional_id, status, ... <!-- HARDCODED -->
    <br>
    <strong>Auditoria:</strong> Todos os campos de timestamp + raw_response JSON <!-- HARDCODED -->
</div>
```

**O que deveria ser dinâmico:**

Verificar se tabela existe e quais índices estão criados:

```php
private function getPayoutDatabaseInfo(): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'limpvix_payouts';

    $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;

    if (!$tableExists) {
        return [
            'exists' => false,
            'indexes' => [],
            'columns' => [],
        ];
    }

    // Get indexes
    $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
    $indexNames = array_unique(array_column($indexes, 'Key_name'));

    // Get columns
    $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
    $columnNames = array_column($columns, 'Field');

    // Check for timestamp columns
    $timestampColumns = array_filter($columnNames, fn($col) => str_ends_with($col, '_at'));

    return [
        'exists' => true,
        'table_name' => $table,
        'indexes' => $indexNames,
        'columns' => $columnNames,
        'timestamp_columns' => $timestampColumns,
        'has_audit' => in_array('raw_response', $columnNames) && count($timestampColumns) >= 5,
    ];
}
```

**Renderizar dinamicamente:**
```php
<?php $dbInfo = $this->getPayoutDatabaseInfo(); ?>

<?php if ($dbInfo['exists']): ?>
    <h4>💾 Database Table: <code><?php echo esc_html($dbInfo['table_name']); ?></code>
        <span style="color: #10b981;">✓ Criada</span>
    </h4>
    <div>
        <strong>Índices:</strong> <?php echo implode(', ', array_map('esc_html', $dbInfo['indexes'])); ?>
        <br>
        <strong>Campos Timestamp:</strong> <?php echo count($dbInfo['timestamp_columns']); ?> campos
        <br>
        <strong>Auditoria:</strong> <?php echo $dbInfo['has_audit'] ? '✓ Completa' : '⚠️ Parcial'; ?>
    </div>
<?php else: ?>
    <h4>💾 Database Table: <code>wp_limpvix_payouts</code>
        <span style="color: #ef4444;">❌ Não Criada</span>
    </h4>
<?php endif; ?>
```

---

## 🎯 PROBLEMA CRÍTICO: MercadoPago Integration

### Estado Atual (75% Dinâmico)

**Linha 4634-4738:**
```php
$mpAccessToken = get_option('limpvix_mercadopago_access_token');
$mpPublicKey = get_option('limpvix_mercadopago_public_key');
$mpConfigured = !empty($mpAccessToken) && !empty($mpPublicKey);
```

**Problema:**
- ✅ Verifica Access Token
- ✅ Verifica Public Key
- ❌ **NÃO verifica Client ID** (necessário para OAuth)
- ❌ **NÃO verifica Client Secret** (necessário para OAuth)

### Credenciais MercadoPago Necessárias

**Para Pagamentos de Clientes:**
- `limpvix_mercadopago_access_token` - Token de acesso da plataforma
- `limpvix_mercadopago_public_key` - Chave pública da plataforma

**Para OAuth de Profissionais (MP→MP Payouts):**
- `limpvix_mercadopago_client_id` - Client ID da aplicação OAuth
- `limpvix_mercadopago_client_secret` - Client Secret da aplicação OAuth

### Solução Completa

```php
private function getMercadoPagoConfigStatus(): array
{
    // Credenciais da plataforma (pagamentos)
    $accessToken = get_option('limpvix_mercadopago_access_token');
    $publicKey = get_option('limpvix_mercadopago_public_key');

    // Credenciais OAuth (payouts profissionais)
    $clientId = get_option('limpvix_mercadopago_client_id');
    $clientSecret = get_option('limpvix_mercadopago_client_secret');

    $platformConfigured = !empty($accessToken) && !empty($publicKey);
    $oauthConfigured = !empty($clientId) && !empty($clientSecret);
    $fullyConfigured = $platformConfigured && $oauthConfigured;

    return [
        'platform_configured' => $platformConfigured,
        'oauth_configured' => $oauthConfigured,
        'fully_configured' => $fullyConfigured,
        'status_icon' => $fullyConfigured ? '✓' : '⚠',
        'status_text' => $fullyConfigured
            ? 'Configurado e Ativo'
            : ($platformConfigured ? 'Configuração Parcial (Falta OAuth)' : 'Configuração Pendente'),
        'status_color' => $fullyConfigured ? '#4ade80' : '#fbbf24',
        'missing' => array_filter([
            !$accessToken ? 'Access Token' : null,
            !$publicKey ? 'Public Key' : null,
            !$clientId ? 'Client ID (OAuth)' : null,
            !$clientSecret ? 'Client Secret (OAuth)' : null,
        ]),
    ];
}
```

**Renderizar status completo:**
```php
<?php $mpStatus = $this->getMercadoPagoConfigStatus(); ?>

<div style="font-size: 12px; color: rgba(255, 255, 255, 0.8);">
    <span style="color: <?php echo $mpStatus['status_color']; ?>;">
        <?php echo $mpStatus['status_icon']; ?> <?php echo esc_html($mpStatus['status_text']); ?>
    </span>

    <?php if (!empty($mpStatus['missing'])): ?>
        <div style="margin-top: 5px; font-size: 11px; color: #fbbf24;">
            Faltando: <?php echo implode(', ', $mpStatus['missing']); ?>
        </div>
    <?php endif; ?>
</div>
```

---

## 📊 Resumo da Análise

### Score Atual: 70/100

**Dinâmico (70 pontos):**
- ✅ Estatísticas de payouts (Hero Card) - 100%
- ✅ Taxa de sucesso calculada - 100%
- ✅ Status MercadoPago básico - 75%

**Hardcoded (30 pontos perdidos):**
- ❌ Features do Payout System - 0% dinâmico
- ❌ Componentes de Arquitetura - 0% dinâmico
- ❌ Database Info - 0% dinâmico
- ❌ MercadoPago OAuth verification - 25% faltando

---

## 🚀 Plano de Implementação

### Fase 1: Corrigir Status MercadoPago (30 min)
- Adicionar verificação de Client ID e Client Secret
- Mostrar status detalhado com credenciais faltando
- Diferenciar entre "Plataforma OK" vs "OAuth OK"

### Fase 2: Dinamizar Features (1h)
- Criar `getPayoutFeaturesStatus()` method
- Verificar cada feature com class_exists() e database checks
- Renderizar com ícones dinâmicos (✅/⚠️/❌)

### Fase 3: Dinamizar Arquitetura (1h)
- Criar `getPayoutArchitectureStatus()` method
- Verificar todas as classes com class_exists() e interface_exists()
- Renderizar com ícones dinâmicos

### Fase 4: Dinamizar Database Info (30 min)
- Criar `getPayoutDatabaseInfo()` method
- Verificar tabela existe, índices criados, campos audit
- Renderizar informações reais do banco

**Total estimado:** 3 horas

---

## ✅ Checklist de Verificação

Após implementação, a aba deve mostrar:

- [ ] MercadoPago status completo (4 credenciais verificadas)
- [ ] Features com ícones dinâmicos baseados em verificação real
- [ ] Componentes de arquitetura com ✓/❌ baseado em class_exists()
- [ ] Database info com índices e campos reais
- [ ] Cron jobs verificados (reconciliation ativo ou não)
- [ ] Link para Configurações > Conexões quando OAuth pendente

---

**Criado por:** Claude Code Assistant
**Data:** 2026-02-16
**Próximo passo:** Implementar Fase 1-4 para tornar aba 100% dinâmica
