# Análise Completa dos Cron Jobs LimpVix

**Data:** 2026-02-16
**Status:** 🔴 CRÍTICO - Cron jobs não estão executando automaticamente

---

## 📊 Resultado do Diagnóstico

### ✅ **O que está FUNCIONANDO:**
- 9 de 11 cron jobs estão AGENDADOS corretamente no WordPress
- 7 de 11 hooks estão REGISTRADOS corretamente
- 3 schedules customizados estão funcionando
- Bootstrap classes estão carregando corretamente

### ❌ **O que está QUEBRADO:**
- **TODOS os 11 cron jobs nunca executaram** (30+ horas atrasados)
- **4 hooks NÃO estão registrados** (sem callbacks)
- **2 crons estão registrados mas NÃO agendados**
- **WP-Cron depende de visitas ao site** (ambiente localhost)

---

## 🔴 PROBLEMA PRINCIPAL

### DISABLE_WP_CRON = FALSE

**O que significa:**
- WP-Cron só executa quando alguém visita o site
- Em ambiente local (localhost:8080), pode ficar HORAS sem execução
- Cron jobs dependem de tráfego HTTP para serem disparados

**Resultado:**
- Todos os 11 cron jobs estão atrasados entre 7 a 30+ horas
- Nenhum cron job executou desde ontem (2026-02-15)
- Sistema de automação completamente parado

---

## 📋 Análise dos 11 Cron Jobs

| # | Cron Job | Status | Último Exec | Problema |
|---|----------|--------|-------------|----------|
| 1 | `check_contract_expiration` | ⏱️ ATRASADO | Nunca | Hook OK, agendado OK, **WP-Cron não executa** |
| 2 | `process_review_timer` | ⏱️ ATRASADO | Nunca | Hook OK, agendado OK, **WP-Cron não executa** |
| 3 | `send_feedback_reminders` | ❌ QUEBRADO | Nunca | **Hook NÃO registrado** + WP-Cron não executa |
| 4 | `process_payout_batch` | ❌ QUEBRADO | Nunca | **Hook NÃO registrado** |
| 5 | `sync_payouts` | ❌ QUEBRADO | Nunca | **Hook NÃO registrado** |
| 6 | `retry_failed_payouts` | ❌ QUEBRADO | Nunca | **Hook NÃO registrado** |
| 7 | `contracts_daily_check` | ⚠️ PARCIAL | Nunca | Hook OK, **mas NÃO agendado** |
| 8 | `contracts_weekly_briefing` | ⚠️ PARCIAL | Nunca | Hook OK, **mas NÃO agendado** |
| 9 | `fallback_send_offers` | ⏱️ ATRASADO | Nunca | Hook OK, agendado OK, **WP-Cron não executa** |
| 10 | `clean_message_queue` | ⏱️ ATRASADO | Nunca | Hook OK, agendado OK, **WP-Cron não executa** |
| 11 | `mp_periodic_sync` | ⏱️ ATRASADO | Nunca | Hook OK, agendado OK, **WP-Cron não executa** |

### Resumo:
- **5 crons:** Configurados corretamente, mas WP-Cron não executa (depende de visitas)
- **4 crons:** Hooks não registrados (PayoutReconciliationService não inicializou)
- **2 crons:** Hooks registrados mas não agendados (ContractAutomation)

---

## 🔍 Detalhamento dos Problemas

### 1. Hooks NÃO Registrados (4 crons)

**Crons afetados:**
- `limpvix_send_feedback_reminders`
- `limpvix_process_payout_batch`
- `limpvix_sync_payouts`
- `limpvix_retry_failed_payouts`

**Causa raiz:**
Arquivo: `src/Application/Services/PayoutReconciliationService.php`

```php
// Linha ~200: add_action() está dentro do método registerSchedules()
// MAS registerSchedules() NÃO é chamado automaticamente!

public static function registerSchedules(): void
{
    $service = new self();

    // ❌ Esses add_action só executam SE registerSchedules() for chamado
    add_action('limpvix_approve_payout', [$service, 'processScheduledApproval']);
    add_action('limpvix_process_payout_batch', [$service, 'processBatch']);
    add_action('limpvix_sync_payouts', [$service, 'syncProcessingPayouts']);
    add_action('limpvix_retry_failed_payouts', [$service, 'retryFailedPayouts']);

    // ... wp_schedule_event calls
}
```

**Problema:**
- Método `registerSchedules()` nunca é chamado
- Hooks nunca são registrados com `add_action()`
- Crons agendados mas sem callback = **NADA acontece**

**Solução:**
Chamar `PayoutReconciliationService::registerSchedules()` no ContractBootstrap ou criar um PayoutBootstrap.

---

### 2. Crons Agendados mas Não Registrados (2 crons)

**Crons afetados:**
- `limpvix_contracts_daily_check`
- `limpvix_contracts_weekly_briefing`

**Causa raiz:**
Arquivo: `src/Infrastructure/Automation/ContractAutomation.php`

Provavelmente o método `register()` não está sendo chamado no bootstrap.

**Solução:**
Chamar `ContractAutomation::register()` no ContractBootstrap.

---

### 3. WP-Cron Não Executa (Todos os crons)

**Causa raiz:**
- `DISABLE_WP_CRON` está `FALSE` (ou não definido)
- WP-Cron só executa via HTTP request ao site
- Ambiente localhost com poucas visitas = crons nunca executam

**Como WP-Cron funciona:**
```
User visita site
  ↓
WordPress chega request
  ↓
WP verifica se há crons atrasados
  ↓
Executa crons em background (pseudo-cron)
  ↓
Responde ao usuário
```

**Problema em localhost:**
- Ninguém visita localhost:8080 frequentemente
- Crons ficam 30+ horas sem executar
- Sistema de automação para completamente

---

## ✅ SOLUÇÕES

### Solução 1: Habilitar DISABLE_WP_CRON (Recomendado para produção)

**Arquivo:** `wp-config.php`

```php
// Adicionar ANTES de "That's all, stop editing!"
define('DISABLE_WP_CRON', true);
```

**Depois configurar crontab do servidor:**

```bash
# Executar wp-cron.php a cada 5 minutos
*/5 * * * * curl http://localhost:8080/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

**Vantagens:**
- ✅ Crons executam independente de visitas
- ✅ Horários confiáveis e previsíveis
- ✅ Não impacta performance do site
- ✅ Recomendado para produção

---

### Solução 2: Forçar execução manual (Temporário)

**Via navegador:**
```
http://localhost:8080/wp-cron.php?doing_wp_cron
```

**Via CLI dentro do container:**
```bash
docker exec limpvix_wordpress_clean curl http://localhost/wp-cron.php?doing_wp_cron
```

**Vantagens:**
- ✅ Imediato (não precisa editar arquivos)
- ✅ Bom para desenvolvimento/debug
- ❌ Manual (precisa executar sempre)

---

### Solução 3: Registrar hooks faltantes

**Arquivo:** `src/Core/ContractBootstrap.php`

**Adicionar no método `registerCronJobs()`:**

```php
// Registrar PayoutReconciliationService hooks
\LimpVix\Application\Services\PayoutReconciliationService::registerSchedules();
```

**OU criar bootstrap dedicado:**

**Arquivo:** `src/Core/PayoutBootstrap.php` (novo)

```php
<?php
namespace LimpVix\Core;

final class PayoutBootstrap
{
    public static function init(): void
    {
        // Register payout reconciliation
        \LimpVix\Application\Services\PayoutReconciliationService::registerSchedules();

        // Log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LimpVix] PayoutBootstrap initialized');
        }
    }
}
```

**Depois chamar no Kernel.php:**

```php
// Inicializar módulo Payout
if (class_exists('LimpVix\\Core\\PayoutBootstrap')) {
    PayoutBootstrap::init();
}
```

---

### Solução 4: Agendar crons faltantes

**Arquivo:** `src/Infrastructure/Automation/ContractAutomation.php`

**Verificar se método `register()` existe e chamá-lo:**

No `ContractBootstrap::registerCronJobs()`:

```php
// Registrar ContractAutomation
if (class_exists('LimpVix\\Infrastructure\\Automation\\ContractAutomation')) {
    \LimpVix\Infrastructure\Automation\ContractAutomation::register();
}
```

---

## 🚀 PLANO DE AÇÃO IMEDIATO

### Passo 1: Forçar execução dos crons atrasados (AGORA)

```bash
# Via container Docker
docker exec limpvix_wordpress_clean curl http://localhost/wp-cron.php?doing_wp_cron

# OU via navegador
# Abrir: http://localhost:8080/wp-cron.php?doing_wp_cron
```

**Resultado esperado:**
- 9 crons atrasados vão executar
- CronMonitor vai registrar as execuções
- Dashboard vai mostrar status atualizado

---

### Passo 2: Registrar hooks faltantes

**Opção A - Quick Fix no ContractBootstrap:**

Editar: `src/Core/ContractBootstrap.php`

```php
private static function registerCronJobs(): void
{
    // ... código existente ...

    // FIX: Registrar PayoutReconciliationService hooks
    if (class_exists('LimpVix\\Application\\Services\\PayoutReconciliationService')) {
        \LimpVix\Application\Services\PayoutReconciliationService::registerSchedules();
        self::logInfo('PayoutReconciliationService hooks registered');
    }

    // FIX: Registrar ContractAutomation
    if (class_exists('LimpVix\\Infrastructure\\Automation\\ContractAutomation')) {
        \LimpVix\Infrastructure\Automation\ContractAutomation::register();
        self::logInfo('ContractAutomation registered');
    }
}
```

**Depois desativar/ativar plugin:**

```bash
docker exec limpvix_wordpress_clean wp plugin deactivate limpvix-core
docker exec limpvix_wordpress_clean wp plugin activate limpvix-core
```

---

### Passo 3: Habilitar DISABLE_WP_CRON

**Editar:** `wp-config.php`

```php
// Adicionar antes de "That's all, stop editing!"
define('DISABLE_WP_CRON', true);
```

**Configurar crontab:**

```bash
# No host (fora do container)
crontab -e

# Adicionar linha:
*/5 * * * * docker exec limpvix_wordpress_clean curl -s http://localhost/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

**OU dentro do container:**

```bash
docker exec -it limpvix_wordpress_clean bash
crontab -e

# Adicionar:
*/5 * * * * curl -s http://localhost/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

---

### Passo 4: Verificar correções

```bash
# Executar diagnóstico novamente
docker exec limpvix_wordpress_clean php /var/www/html/wp-content/plugins/limpvix-core/diagnose_cron_jobs.php
```

**Resultado esperado após correções:**
- ✅ 11/11 crons agendados
- ✅ 11/11 hooks registrados
- ✅ 11/11 crons executando regularmente
- ✅ CronMonitor com dados de última execução
- ✅ Dashboard mostrando health score > 90%

---

## 📊 Verificação Final

### Checklist de Validação:

**Crons Agendados:**
- [ ] 11/11 crons aparecem em `_get_cron_array()`
- [ ] Nenhum cron está atrasado (timestamp > now)

**Hooks Registrados:**
- [ ] `limpvix_send_feedback_reminders` tem callback
- [ ] `limpvix_process_payout_batch` tem callback
- [ ] `limpvix_sync_payouts` tem callback
- [ ] `limpvix_retry_failed_payouts` tem callback
- [ ] `limpvix_contracts_daily_check` tem callback e está agendado
- [ ] `limpvix_contracts_weekly_briefing` tem callback e está agendado

**Execuções:**
- [ ] CronMonitor tem dados para os 11 jobs
- [ ] Status = 'success' (ou pelo menos 'failure', mas não 'unknown')
- [ ] Age < threshold (hourly = 2h, daily = 25h, etc)

**WP-Cron:**
- [ ] `DISABLE_WP_CRON = true` em wp-config.php
- [ ] Crontab configurado (executar a cada 5min)
- [ ] Teste manual funciona: `curl http://localhost:8080/wp-cron.php?doing_wp_cron`

---

## 🎯 Impacto Atual (Crons não executando)

### Funcionalidades PARADAS:

1. **Contratos:**
   - ❌ Contratos expirando não são detectados
   - ❌ Check diário de status não roda
   - ❌ Briefing semanal não é gerado

2. **Payouts:**
   - ❌ Payouts pendentes não são processados automaticamente
   - ❌ Sincronização com MercadoPago parou
   - ❌ Retry de falhas não acontece
   - 💰 **IMPACTO FINANCEIRO:** Profissionais não recebem pagamentos

3. **Feedback:**
   - ❌ Lembretes de feedback não são enviados
   - ❌ Timer de review não processa
   - 📉 **IMPACTO UX:** Clientes não são lembrados de avaliar

4. **Ofertas:**
   - ❌ Fallback de envio de offers não funciona
   - 📉 **IMPACTO OPERACIONAL:** Profissionais podem não receber ofertas

5. **Comunicação:**
   - ❌ Fila de mensagens não é limpa
   - 💾 **IMPACTO PERFORMANCE:** Fila cresce indefinidamente

6. **MercadoPago:**
   - ❌ Sincronização periódica parou
   - ⚠️ **IMPACTO:** Detecção de mudanças atrasada

---

## 📝 Resumo Executivo

**Situação Atual:**
- 🔴 Sistema de automação 100% parado há 30+ horas
- 🔴 Impacto financeiro (payouts não processados)
- 🔴 Impacto operacional (contratos não gerenciados)

**Causa Raiz:**
- WP-Cron configurado incorretamente (depende de visitas)
- 4 hooks não registrados (PayoutReconciliationService)
- 2 crons não agendados (ContractAutomation)

**Tempo para Resolver:**
- Quick fix (forçar execução): **5 minutos**
- Correção completa: **30 minutos**
- Validação: **10 minutos**

**Prioridade:** 🔥 CRÍTICO - Resolver AGORA

---

**Próximo passo:** Executar Passo 1 do Plano de Ação para recuperar sistema.
