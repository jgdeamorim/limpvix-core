# 🤖 Análise: Automação do SendOffers

## 📋 Contexto Atual

**Status:** SendOffers está **MANUAL** via botão Admin UI
**Arquitetura:** ✅ Já preparada para automação (event-driven)
**Gap Identificado:** Falta listener automático no evento `limpvix_contract_activated`

---

## 🔍 Análise da Arquitetura Existente

### ✅ O Que Já Existe e Facilita Automação

1. **SendOffers é Use Case Isolado**
   - Localização: `src/Application/UseCase/Briefing/SendOffers.php`
   - Pode ser chamado de qualquer lugar
   - Já tem validações internas (idempotência, coordenadas, etc.)

2. **Eventos WordPress Já Implementados**
   ```php
   // Linha 124 - SendOffers.php
   do_action('limpvix_send_offer_notification', $professionalId, $offerId);

   // Linha 128 - SendOffers.php
   do_action('limpvix_offers_sent', $contractId, count($offers), $offers);
   ```

3. **Evento de Ativação de Contrato Existe**
   - Hook: `limpvix_contract_activated`
   - Disparado em: `src/Domain/Contract/Contract.php:210`
   - Handler atual: `ContractBootstrap::onContractActivated()` (linha 488)
   - Status: ⚠️ Comentário `@future: Enviar notificação ao cliente e profissional`

4. **Idempotência Já Implementada**
   ```php
   // Linha 73-83 - SendOffers.php
   $existingOffers = $this->wpdb->get_var(
       "SELECT COUNT(*) FROM {$table} WHERE contract_id = %d AND status = 'pending'",
       $contractId
   );

   if ($existingOffers > 0) {
       throw new \RuntimeException("Contract already has pending offers");
   }
   ```
   ✅ Evita duplicidade automática

5. **Container Global de Use Cases**
   ```php
   // ContractBootstrap.php:linha 131-144
   $GLOBALS['limpvix_contract_use_cases']['send_offers'] = new SendOffers(...);
   ```
   ✅ Use Case disponível globalmente

---

## 🎯 Análise das 3 Abordagens Sugeridas

### 1️⃣ Automação por Evento de Domínio (Recomendado) ⭐

**Trigger:** `limpvix_contract_activated`

**Implementação:**
```php
// ContractBootstrap.php - Modificar onContractActivated()
public static function onContractActivated($eventData): void
{
    $contractId = $eventData['contract_id'] ?? null;

    if (!$contractId) {
        return;
    }

    // Enviar offers automaticamente
    try {
        $useCases = $GLOBALS['limpvix_contract_use_cases'] ?? [];

        if (isset($useCases['send_offers'])) {
            $result = $useCases['send_offers']->execute($contractId);

            self::logInfo(sprintf(
                'Auto-SendOffers: %d offers sent for Contract #%d',
                $result['offers_sent'],
                $contractId
            ));
        }
    } catch (\Exception $e) {
        // Log error but don't fail contract activation
        self::logError('Auto-SendOffers failed: ' . $e->getMessage());
    }
}
```

**Vantagens:**
- ✅ **Zero intervenção manual** - Fluxo 100% automático
- ✅ **Timing perfeito** - Envia assim que contrato ativa
- ✅ **Escalável** - Funciona para 1 ou 1000 contratos/dia
- ✅ **Arquitetura limpa** - Usa eventos de domínio existentes
- ✅ **Fallback seguro** - Não quebra ativação se falhar

**Desvantagens:**
- ⚠️ Executa síncrono (pode atrasar ativação em 1-3s)
- ⚠️ Se falhar, admin precisa reenviar manualmente

**Cenários de Uso:**
- ✅ Contrato criado via API (app mobile)
- ✅ Contrato criado via Admin
- ✅ Contrato reativado após pausa

---

### 2️⃣ Automação via WP-Cron (Batch Scheduler)

**Trigger:** Cron job a cada 1 hora

**Implementação:**
```php
// Novo arquivo: src/Infrastructure/Cron/SendOffersCronAdapter.php
final class SendOffersCronAdapter
{
    public static function register(): void
    {
        if (!wp_next_scheduled('limpvix_auto_send_offers')) {
            wp_schedule_event(time(), 'hourly', 'limpvix_auto_send_offers');
        }

        add_action('limpvix_auto_send_offers', [self::class, 'execute']);
    }

    public static function execute(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_contracts';

        // Buscar contratos ativos sem offers pendentes
        $contracts = $wpdb->get_results("
            SELECT c.id
            FROM {$table} c
            LEFT JOIN {$wpdb->prefix}limpvix_contract_offers o
                ON c.id = o.contract_id AND o.status = 'pending'
            WHERE c.status = 'active'
            AND c.allocated_professional_id IS NULL
            AND o.id IS NULL
            LIMIT 50
        ");

        $sent = 0;
        $failed = 0;

        foreach ($contracts as $contract) {
            try {
                $useCases = $GLOBALS['limpvix_contract_use_cases'] ?? [];

                if (isset($useCases['send_offers'])) {
                    $useCases['send_offers']->execute($contract->id);
                    $sent++;
                }
            } catch (\Exception $e) {
                $failed++;
                error_log('[LimpVix] Auto-SendOffers cron failed for Contract #' . $contract->id . ': ' . $e->getMessage());
            }
        }

        error_log(sprintf(
            '[LimpVix] Auto-SendOffers cron completed: %d sent, %d failed',
            $sent,
            $failed
        ));
    }
}
```

**Vantagens:**
- ✅ **Recuperação automática** - Pega contratos esquecidos
- ✅ **Não bloqueia** - Execução assíncrona
- ✅ **Batch processing** - Processa múltiplos de uma vez
- ✅ **Rate limiting** - Pode limitar a 50 por hora

**Desvantagens:**
- ❌ **Delay de até 1 hora** - Não é imediato
- ❌ **Overhead** - Roda mesmo sem contratos pendentes
- ❌ **Complexidade** - Precisa query custom

**Cenários de Uso:**
- ✅ Fallback se evento falhar
- ✅ Reprocessamento de erros
- ✅ Contratos importados em lote

---

### 3️⃣ Automação Híbrida (Melhor Modelo Enterprise) 🏆

**Combinação:** Evento de Domínio + Cron de Fallback

**Implementação:**

**Parte 1: Evento Imediato**
```php
// ContractBootstrap.php - onContractActivated()
public static function onContractActivated($eventData): void
{
    $contractId = $eventData['contract_id'] ?? null;

    if (!$contractId) {
        return;
    }

    // Incrementar métricas (já existe)
    if (isset($eventData['professional_id'])) {
        self::incrementProfessionalMetric(
            (int) $eventData['professional_id'],
            'total_services'
        );
    }

    // AUTO-ENVIAR OFFERS (NOVO)
    self::autoSendOffers($contractId);

    self::logInfo('Contract activated: #' . $contractId);
}

/**
 * Auto-send offers when contract activates
 *
 * @param int $contractId
 * @return void
 */
private static function autoSendOffers(int $contractId): void
{
    try {
        $useCases = $GLOBALS['limpvix_contract_use_cases'] ?? [];

        if (!isset($useCases['send_offers'])) {
            self::logInfo("Auto-SendOffers skipped: use case not available");
            return;
        }

        // Check if feature flag enabled
        $featureFlags = $GLOBALS['limpvix_feature_flags'] ?? null;
        if ($featureFlags && !$featureFlags->isEnabled('auto_send_offers')) {
            self::logInfo("Auto-SendOffers skipped: feature flag disabled");
            return;
        }

        // Execute SendOffers
        $result = $useCases['send_offers']->execute($contractId);

        self::logInfo(sprintf(
            'Auto-SendOffers: %d offers sent for Contract #%d (immediate)',
            $result['offers_sent'],
            $contractId
        ));

    } catch (\RuntimeException $e) {
        // Expected errors (no coordinates, no eligible professionals, already has offers)
        self::logInfo('Auto-SendOffers expected error: ' . $e->getMessage());

    } catch (\Exception $e) {
        // Unexpected errors - log but don't fail
        self::logError('Auto-SendOffers unexpected error: ' . $e->getMessage());
    }
}
```

**Parte 2: Cron de Fallback**
```php
// Registrar no ContractBootstrap::registerCronJobs()
if (!wp_next_scheduled('limpvix_fallback_send_offers')) {
    wp_schedule_event(time(), 'hourly', 'limpvix_fallback_send_offers');
}

add_action('limpvix_fallback_send_offers', [self::class, 'onFallbackSendOffers']);

/**
 * Fallback cron: Process contracts that slipped through
 */
public static function onFallbackSendOffers(): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'limpvix_contracts';

    // Find active contracts without offers (created >5 min ago to avoid race)
    $contracts = $wpdb->get_results("
        SELECT c.id
        FROM {$table} c
        LEFT JOIN {$wpdb->prefix}limpvix_contract_offers o
            ON c.id = o.contract_id AND o.status = 'pending'
        WHERE c.status = 'active'
        AND c.allocated_professional_id IS NULL
        AND o.id IS NULL
        AND c.created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        LIMIT 20
    ");

    if (empty($contracts)) {
        return; // Nothing to do
    }

    $sent = 0;
    $failed = 0;

    foreach ($contracts as $contract) {
        try {
            $useCases = $GLOBALS['limpvix_contract_use_cases'] ?? [];

            if (isset($useCases['send_offers'])) {
                $result = $useCases['send_offers']->execute($contract->id);
                $sent++;

                self::logInfo(sprintf(
                    'Fallback-SendOffers: %d offers sent for Contract #%d',
                    $result['offers_sent'],
                    $contract->id
                ));
            }
        } catch (\Exception $e) {
            $failed++;
            self::logError('Fallback-SendOffers failed for Contract #' . $contract->id . ': ' . $e->getMessage());
        }
    }

    if ($sent > 0 || $failed > 0) {
        self::logInfo(sprintf(
            'Fallback-SendOffers cron: %d sent, %d failed',
            $sent,
            $failed
        ));
    }
}
```

**Parte 3: Feature Flag**
```php
// FeatureFlags.php - Adicionar flag
private $flags = [
    'core_enabled' => true,
    'auto_send_offers' => true, // NOVO: Habilitar auto-envio
    // ... outras flags
];
```

**Vantagens:**
- ✅ **Melhor de ambos** - Imediato + Recuperação
- ✅ **Reliability 99.9%** - Dupla proteção
- ✅ **Feature flag** - Pode desabilitar se necessário
- ✅ **Logs completos** - Diferencia immediate vs fallback
- ✅ **Graceful degradation** - Falha silenciosa não quebra sistema

**Desvantagens:**
- ⚠️ **Mais código** - 2 implementações para manter
- ⚠️ **Complexidade** - Precisa coordenar evento + cron

**Cenários de Uso:**
- ✅ **99% dos casos:** Evento imediato funciona
- ✅ **1% edge cases:** Cron recupera falhas
- ✅ **Manutenção:** Admin pode desabilitar via flag

---

## 📊 Comparação das Abordagens

| Critério | Evento Domínio | WP-Cron | Híbrido |
|----------|----------------|---------|---------|
| **Tempo de resposta** | ⚡ Imediato (1-3s) | 🐢 Até 1h | ⚡ Imediato + Fallback |
| **Confiabilidade** | 🟡 95% (se evento funcionar) | 🟡 90% (depende de cron) | 🟢 99.9% (dupla proteção) |
| **Complexidade** | 🟢 Baixa (1 handler) | 🟡 Média (query custom) | 🔴 Alta (2 implementações) |
| **Performance** | 🟡 Síncrono (bloqueia 1-3s) | 🟢 Assíncrono | 🟢 Majoritariamente síncrono |
| **Escalabilidade** | 🟢 Escala com eventos | 🟡 Limitado por cron | 🟢 Escala perfeitamente |
| **Manutenibilidade** | 🟢 Simples | 🟢 Simples | 🟡 Requer coordenação |
| **Observabilidade** | 🟢 Logs claros | 🟢 Logs claros | 🟢 Logs detalhados |
| **Feature flag** | ✅ Fácil adicionar | ✅ Fácil adicionar | ✅ Já incluído |

---

## 🎯 Recomendação Final

### ✅ Implementar Abordagem **HÍBRIDA** (Opção 3)

**Justificativa:**
1. **Reliability é crítica** - Marketplace depende de offers sendo enviados
2. **UX exige velocidade** - Profissionais devem receber imediatamente
3. **Sistema deve se auto-recuperar** - Sem intervenção manual
4. **Feature flag dá controle** - Pode desabilitar se houver problemas

**Roadmap de Implementação:**

### FASE 1: Evento Imediato (2h) 🚀
- Modificar `ContractBootstrap::onContractActivated()`
- Adicionar método `autoSendOffers()`
- Adicionar feature flag `auto_send_offers`
- Testes: Ativar contrato e verificar offers enviados

### FASE 2: Cron de Fallback (2h) 🛡️
- Criar `SendOffersCronAdapter`
- Registrar cron `limpvix_fallback_send_offers`
- Query para contratos sem offers
- Testes: Simular falha de evento e verificar cron recupera

### FASE 3: Observabilidade (1h) 📊
- Logs diferenciados (immediate vs fallback)
- Métricas de success rate
- Dashboard admin com estatísticas
- Alertas se fallback disparar muito

**Total:** ~5 horas de implementação

---

## 🐛 Riscos e Mitigações

### Risco 1: Duplicidade de Offers
**Probabilidade:** 🟡 Média
**Impacto:** 🔴 Alto (profissionais recebem duplicado)

**Mitigação:**
- ✅ Idempotência já implementada no SendOffers (linha 73-83)
- ✅ Cron verifica `LEFT JOIN` para evitar reenvio
- ✅ Delay de 5min no cron para evitar race com evento

### Risco 2: Evento Falhar Silenciosamente
**Probabilidade:** 🟡 Média
**Impacto:** 🟡 Médio (offers não enviados)

**Mitigação:**
- ✅ Cron de fallback captura casos esquecidos
- ✅ Logs detalhados para debug
- ✅ Admin pode reenviar manualmente via botão

### Risco 3: Coordenadas Inválidas
**Probabilidade:** 🟢 Baixa (já validado no frontend)
**Impacto:** 🟡 Médio (offers não enviados)

**Mitigação:**
- ✅ SendOffers já valida coordenadas (linha 54-69)
- ✅ Exception é capturada e logada
- ✅ Não quebra ativação de contrato

### Risco 4: Nenhum Profissional Elegível
**Probabilidade:** 🟡 Média (depende de região)
**Impacto:** 🟡 Médio (contrato fica sem offers)

**Mitigação:**
- ✅ SendOffers lança exception clara (linha 102-107)
- ✅ Admin é notificado via log
- ✅ Admin pode expandir raio de busca

### Risco 5: Performance do Evento
**Probabilidade:** 🟡 Média
**Impacto:** 🟡 Médio (ativação lenta)

**Mitigação:**
- ✅ SendOffers otimizado (usa pagination)
- ✅ Timeout de 10s (se demorar, lança exception)
- ✅ Feature flag permite desabilitar se necessário

---

## 📈 Métricas de Sucesso

Após implementação, monitorar:

| Métrica | Target | Como Medir |
|---------|--------|------------|
| **Auto-send success rate** | >95% | Logs de sucesso vs total de ativações |
| **Fallback trigger rate** | <5% | Cron só deve disparar para edge cases |
| **Average offers sent** | 8-10 per contract | Média de offers por contrato |
| **Time to first offer** | <5s | Timestamp ativação → primeiro offer |
| **Duplicate offer rate** | 0% | Queries com GROUP BY profissional + contrato |
| **Manual override rate** | <10% | Admin clicando botão "Enviar Offers" |

---

## 🔄 Fluxo Completo Automatizado

```
Cliente finaliza Briefing
    ↓
Contract criado com status 'pending_allocation'
    ↓
Admin (ou sistema) ativa contrato
    ↓ (event: limpvix_contract_activated)
    ↓
ContractBootstrap::onContractActivated()
    ↓
autoSendOffers() executa imediatamente
    ↓
SendOffers Use Case:
    ├── Valida coordenadas ✓
    ├── Busca profissionais elegíveis ✓
    ├── Calcula match score ✓
    ├── Cria offers no banco ✓
    └── Dispara: do_action('limpvix_send_offer_notification')
        ↓
OfferNotificationListener:
    ├── Email enviado ✓
    └── SMS enviado (se Twilio) ✓
        ↓
Profissionais recebem notificação em <5s ⚡

--- Fallback (só se evento falhar) ---

1 hora depois...
    ↓
WP-Cron: limpvix_fallback_send_offers
    ↓
Query: Contratos sem offers (>5min criados)
    ↓
Se encontrar algum:
    └── Executa SendOffers para cada um
        └── Log: "Fallback-SendOffers executed"
```

---

## 🎓 Lições do Design Atual

### ✅ O Que Está Bem Feito

1. **Idempotência nativa** - SendOffers verifica offers existentes
2. **Eventos já implementados** - Arquitetura event-driven pronta
3. **Use Case isolado** - Pode ser chamado de qualquer contexto
4. **Validações robustas** - Coordenadas, skills, disponibilidade
5. **Notificações assíncronas** - Via WordPress actions

### ⚠️ O Que Precisa Melhorar

1. **Falta automação** - Ainda é manual via botão
2. **Sem fallback** - Se evento falhar, perde offer
3. **Sem feature flag** - Não pode desabilitar rapidamente
4. **Logs básicos** - Poderia ter mais contexto
5. **Query do cron** - Precisa ser criada

---

## 📝 Próximos Passos

### Implementação Sugerida

1. **FASE 1 - Evento Imediato (2h)** ⭐ COMEÇAR AQUI
   - Modificar ContractBootstrap
   - Adicionar autoSendOffers()
   - Adicionar feature flag
   - Testar ativação manual

2. **FASE 2 - Cron Fallback (2h)**
   - Criar SendOffersCronAdapter
   - Implementar query de recuperação
   - Registrar cron job
   - Testar edge cases

3. **FASE 3 - Observabilidade (1h)**
   - Logs detalhados
   - Dashboard de métricas
   - Alertas automáticos

**Total:** ~5 horas para sistema completo enterprise-grade

---

## ✅ Conclusão

**A automação é ESSENCIAL e está PRONTA para ser implementada.**

A arquitetura atual já suporta 90% do necessário:
- ✅ Eventos de domínio existem
- ✅ Use Case é isolado
- ✅ Idempotência implementada
- ✅ Notificações assíncronas

**Falta apenas:**
- ❌ Conectar evento → SendOffers
- ❌ Adicionar cron de fallback
- ❌ Feature flag de controle

**Benefícios da automação:**
- 🚀 UX muito melhor (profissionais recebem imediatamente)
- 🤖 Zero intervenção manual (escala para 1000s de contratos)
- 🛡️ Sistema auto-recuperável (cron pega falhas)
- 📊 Observabilidade completa (logs + métricas)

**Implementar modelo HÍBRIDO é a escolha certa para um sistema enterprise.**
