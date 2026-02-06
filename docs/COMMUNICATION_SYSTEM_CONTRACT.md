# CONTRATO TÉCNICO - SISTEMA DE COMUNICAÇÃO LIMPVIX

**Versão:** 1.0
**Data:** 2026-02-06
**Tipo:** Contrato Imutável
**Propósito:** Documentar single sources of truth antes de implementar UI Admin

---

## 📋 EXECUTIVE SUMMARY

Este documento é o **contrato canônico** do sistema de comunicação LimpVix. Serve como **fonte única da verdade** para:

- Templates de mensagens (conteúdo, variáveis, regras)
- Fluxos automáticos (triggers, timing, condições)
- Providers de comunicação (APIs, métodos, configuração)
- Governança e bloqueios (LGPD, segurança, auditoria)

**Regra de Ouro:**
❌ UI Admin **NÃO CRIA** lógica nova
✅ UI Admin **CONSOME E EXPÕE** este contrato

---

## 1. TEMPLATES CANÔNICOS (IMUTÁVEIS)

### 1.1 Inventário Completo

| ID | Nome | Fluxo | Canal | Público | Variáveis | Status |
|---|---|---|---|---|---|---|
| C1.1 | Feedback D+1 | client_feedback_d1 | WhatsApp | Cliente | `{{customer_name}}`, `{{feedback_url}}` | ✅ Ativo |
| C1.2 | Feedback D+3 | client_feedback_d3 | WhatsApp/SMS | Cliente | `{{customer_name}}`, `{{feedback_url}}` | ✅ Ativo |
| C1.3 | Feedback D+7 | client_feedback_d6 | SMS | Cliente | `{{customer_name}}`, `{{feedback_url}}` | ✅ Ativo |
| C2 | Feedback Negativo | client_feedback_negative | NENHUM | Cliente | NENHUMA | 🔒 Bloqueado |
| C3 | Google Review | google_review | WhatsApp/SMS | Cliente | `{{customer_name}}`, `{{google_review_url}}` | ✅ Ativo |
| P1 | Serviço Concluído | staff_completed | SMS | Staff | `{{staff_name}}`, `{{service_name}}` | ✅ Ativo |
| P2 | Pagamento Autorizado | staff_authorized | SMS | Staff | `{{staff_name}}`, `{{service_name}}`, `{{amount}}` | ✅ Ativo |
| P3 | Pagamento em Análise | staff_review | SMS | Staff | `{{staff_name}}`, `{{service_name}}` | ✅ Ativo |

### 1.2 Conteúdo dos Templates

#### C1.1 - Feedback D+1 (WhatsApp Priority)
```
Olá {{customer_name}}! 👋

O serviço realizado ontem atendeu suas expectativas?

Sua avaliação ajuda a Limpvix a manter a qualidade ⭐
Leva menos de 1 minuto:

👉 Avaliar agora: {{feedback_url}}

Equipe Limpvix
```
**Aprovação WhatsApp:** `feedback_limpvix_d1`
**Idempotência:** Máx 1 por order

#### C1.2 - Feedback D+3 (WhatsApp ou SMS)
```
Oi {{customer_name}}!

Ainda não vimos sua avaliação do serviço 😊
Se puder, sua opinião é muito importante:

👉 Avaliar: {{feedback_url}}

Obrigado!
```
**Aprovação WhatsApp:** `feedback_limpvix_d3`

#### C1.3 - Feedback D+6/D+7 (SMS - Última)
```
{{customer_name}}, esta é a última mensagem 😊
Avalie o serviço da Limpvix em 1 minuto:

{{feedback_url}}
```
**Canal:** SMS obrigatório

#### C2 - Feedback Negativo (≤3⭐) - BLOQUEIO INTENCIONAL
```
null
```
**Implementação:** `MessageTemplates::clientFeedbackNegative()` retorna `null`
**Efeito:** Status → `manual_review_required`, admin notificado
**Rationale:** Feedback negativo requer contato humano, não automação

#### C3 - Google Review (5⭐)
```
Obrigado pela sua avaliação, {{customer_name}}! 🌟

Se puder, compartilhe sua experiência no Google — isso ajuda muito a Limpvix 💙

👉 Avaliar no Google: {{google_review_url}}

Muito obrigado!
```
**Aprovação WhatsApp:** `google_review_limpvix`
**Condição:** GoogleBusiness deve estar configurado

#### P1 - Serviço Concluído
```
Olá {{staff_name}} 👋

O serviço {{service_name}} foi concluído com sucesso.

Status financeiro atual:
⏳ Aguardando avaliação do cliente

Você será avisado assim que houver atualização.
```

#### P2 - Pagamento Autorizado
```
Boa notícia, {{staff_name}}! ✅

O pagamento do serviço {{service_name}} foi AUTORIZADO.

💰 Valor: R$ {{amount}}
📆 Repasse previsto conforme cronograma.

Equipe Limpvix
```

#### P3 - Pagamento em Análise
```
Olá {{staff_name}}.

O pagamento do serviço {{service_name}} está em análise.

Nossa equipe está avaliando o ocorrido e poderá entrar em contato se necessário.

Equipe Limpvix
```

---

## 2. FLUXOS AUTOMÁTICOS

### 2.1 Fluxo C1 - Solicitação de Feedback (3 Tentativas)

**Trigger:** `bkntc_appointment_completed` (Booknetic hook)

**Timing:**
- **Tentativa 1:** D+24h (WhatsApp template)
- **Tentativa 2:** D+72h (WhatsApp ou SMS)
- **Tentativa 3:** D+168h (SMS apenas)

**Persistência:** `wp_limpvix_feedback_reminders`

**Executor:** `FeedbackRemindersCron` (WP-Cron hourly)

**Bloqueios:**
- Comunicação desativada (`limpvix_comm_active = false`)
- Opt-out do cliente
- Feedback ≤3⭐ já recebido
- Disputa ativa
- Pedido estornado

**Handler:** `FeedbackRemindersCron::handle()`

### 2.2 Fluxo C2 - Feedback Negativo (≤3⭐) - BLOQUEIO DELIBERADO

**Trigger:** `limpvix_feedback_negative_received`

**Ação:** BLOQUEIO INTENCIONAL

**Status:** `manual_review_required` (banco)

**Admin:** Notificação via `do_action('limpvix_notify_admin')`

**Handler:** `MessageFlowTriggers::onFeedbackNegative()`

**Resultado:** Nenhuma mensagem automática + esperando contato humano

**Rationale:** Feedback negativo é sinal de problema. Automação piora a situação. Requer toque humano para resolução adequada.

### 2.3 Fluxo C3 - Convite Google Review (5⭐)

**Trigger:** `limpvix_feedback_positive_received` (rating >= 5)

**Condição:** GoogleBusiness deve estar configurado

**Timing:** Imediato após feedback 5⭐

**Idempotência:** Verifica se já enviou antes

**Handler:** `MessageFlowTriggers::onFeedback5Stars()`

**Método:** `GoogleBusinessReviewHelper::sendReviewInvite()`

**Template:** WhatsApp (`google_review_limpvix`) ou SMS

### 2.4 Fluxo P1 - Serviço Concluído

**Trigger:** `bkntc_appointment_completed`

**Condition:** `limpvix_notify_staff_enabled = true`

**Timing:** Imediato

**Handler:** `MessageFlowTriggers::onServiceCompleted()`

**Canal:** SMS (Twilio)

### 2.5 Fluxo P2 - Pagamento Autorizado

**Trigger:** `limpvix_payment_authorized` (custom event)

**Condition:** `limpvix_notify_staff_enabled = true`

**Timing:** Imediato

**Handler:** `MessageFlowTriggers::onPaymentAuthorized()`

**Canal:** SMS (Twilio)

### 2.6 Fluxo P3 - Pagamento em Análise

**Trigger:** `limpvix_payment_blocked` (custom event)

**Condition:** `limpvix_notify_staff_enabled = true`

**Timing:** Imediato

**Handler:** `MessageFlowTriggers::onPaymentBlocked()`

**Canal:** SMS (Twilio)

**Linguagem:** Neutra (sem acusação)

---

## 3. REGRAS DE GOVERNANÇA (IMUTÁVEIS)

### 3.1 Validação Centralizada

**Método:** `MessageTemplates::canSendAutomatically()`

**Checklist de Bloqueio:**
1. ✅ Comunicação ativa globalmente (`limpvix_comm_active = true`)
2. ✅ Cliente não fez opt-out (`wp_limpvix_feedback_reminders.opt_out`)
3. ✅ Feedback não é negativo (`feedback_rating > 3`)
4. ✅ Sem disputa ativa (`limpvix_financial_context.dispute_status != 'active'`)
5. ✅ Pedido não foi estornado (`limpvix_financial_context.refund_status != 'refunded'`)

**Falha:**
```php
return [
    'can_send' => false,
    'reason' => 'specific_block_reason'
];
```

### 3.2 Bloqueio C2 Específico

```php
if (rating <= 3) {
    $wpdb->update('wp_limpvix_feedback_reminders', [
        'status' => 'manual_review_required',
        'stop_reason' => 'negative_feedback'
    ]);

    do_action('limpvix_notify_admin', 'negative_feedback', $data);
}
```

### 3.3 Rate Limiting

**Feedback Reminders (C1):**
- Tentativa 1: D+24h
- Tentativa 2: D+72h
- Tentativa 3: D+168h (7 dias)
- Máximo: 3 tentativas (configurável)
- Intervalo mínimo: 24h entre tentativas

### 3.4 LGPD & CDC Compliance

1. **Aceite explícito:** CustomerBriefingPage com checkbox
2. **Auditoria completa:** Ledger imutável (timestamp + IP + User-Agent)
3. **Opt-out:** Campo `opt_out` em `wp_limpvix_feedback_reminders`
4. **Direito ao esquecimento:** Use Case previsto (não implementado)
5. **Máximo de tentativas:** 3 tentativas (C1)

---

## 4. PROVIDERS DE COMUNICAÇÃO

### 4.1 TwilioSmsProvider

**Arquivo:** `src/Infrastructure/Communication/Providers/TwilioSmsProvider.php`

**Configuração:**
```php
get_option('limpvix_twilio_settings') = [
    'account_sid' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    'auth_token' => '••••••••••••••••••••••••••••••••',
    'from_number' => '+5511999999999'
];
```

**Métodos Públicos:**
- `send(string $to, string $message, array $context): bool`
- `isAvailable(): bool`

**API:** `https://api.twilio.com/2010-04-01/Accounts/{sid}/Messages.json`

**Autenticação:** HTTP Basic Auth (`base64(sid:token)`)

**Logging:** Cria registro em `wp_limpvix_messages_log` (status: pending → sent/failed)

### 4.2 360DialogProvider

**Arquivo:** `src/Infrastructure/Communication/Providers/360DialogProvider.php`

**Configuração:**
```php
get_option('limpvix_360dialog_settings') = [
    'api_key' => '••••••••••••••••••••••••••••••••'
];
```

**Métodos Públicos:**
- `send(string $to, string $message, array $context): bool`
- `isAvailable(): bool`

**API:** `https://waba.360dialog.io/v1/messages`

**Autenticação:** Header `D360-API-KEY`

**Logging:** Cria registro em `wp_limpvix_messages_log`

---

## 5. PÁGINAS PÚBLICAS DO CLIENTE

### 5.1 CustomerBriefingPage

**Rota:** `/?limpvix_briefing=1&order_id={id}&hash={hash}`

**Segurança:** Hash validation (`wp_hash($order_id . 'limpvix_briefing')`)

**Conteúdo:**
- Termos do Serviço
- Sistema de Avaliação
- AVISO: Impacto da Avaliação (5⭐ imediato / 4⭐ 24h / ≤3⭐ retenção)
- Política de Privacidade
- Checkbox obrigatório
- Botão "Aceitar e Prosseguir"

**Handler:** `RegisterBriefingAcceptance::execute()`

**Persistência:** `wp_limpvix_financial_ledger` (event_type='briefing_accepted')

### 5.2 CustomerFeedbackPage

**Rota:** `/?limpvix_feedback=1&order_id={id}&hash={hash}`

**Segurança:** Hash validation (`wp_hash($order_id . 'limpvix_feedback')`)

**Conteúdo:**
- Rating: 5 Estrelas interativas
- Impact Box dinâmico (muda cor conforme rating)
- Reasons contextuais (positivos para ≥4⭐, negativos para ≤3⭐)
- Comentário opcional

**Handler:** `ProcessCustomerFeedback::execute()`

**Persistência:** `wp_limpvix_orders` (customer_rating, feedback_reasons, feedback_comment)

---

## 6. REPOSITÓRIOS E PERSISTÊNCIA

### 6.1 MessageRepository

**Arquivo:** `src/Infrastructure/Communication/Repositories/MessageRepository.php`

**Tabela:** `wp_limpvix_messages_log`

**Schema:**
```sql
CREATE TABLE wp_limpvix_messages_log (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    order_id INT,
    booking_id INT,
    recipient_phone VARCHAR(20),
    recipient_type VARCHAR(20), -- 'client' | 'staff'
    channel VARCHAR(20), -- 'sms' | 'whatsapp'
    template_id VARCHAR(100),
    flow_id VARCHAR(100),
    message_content TEXT,
    status VARCHAR(20), -- 'pending' | 'sent' | 'delivered' | 'failed'
    provider_response LONGTEXT,
    sent_at DATETIME,
    delivered_at DATETIME,
    created_at DATETIME,
    updated_at DATETIME
);
```

**Métodos:**
- `create(array $data): int`
- `getById(int $id): ?array`
- `getByOrder(int $order_id): array`
- `updateStatus(int $id, string $status, ?string $provider_response): bool`
- `getFailedMessages(int $limit): array`

### 6.2 Outras Tabelas

**`wp_limpvix_feedback_reminders`:**
- Rastreamento do fluxo C1
- Contador de tentativas
- Status (pending | completed | manual_review_required)

**`wp_limpvix_messages`:**
- Log de mensagens específicas por canal/tipo
- External ID (Twilio SID, 360Dialog ID)

**`wp_limpvix_financial_ledger`:**
- Eventos de briefing_accepted
- Auditoria imutável de aceites

---

## 7. SINGLE SOURCES OF TRUTH

| Componente | Fonte Única | Localização |
|---|---|---|
| Templates Canônicos | `MessageTemplates.php` | Domain layer (imutável) |
| Timing de Tentativas | `limpvix_feedback_timing` | WordPress options |
| Ativação de Fluxos | `limpvix_active_flows` | WordPress options |
| Configurações Twilio | `limpvix_twilio_settings` | WordPress options |
| Configurações 360Dialog | `limpvix_360dialog_settings` | WordPress options |
| Templates Customizados | `limpvix_custom_templates` | WordPress options |
| Bloqueios de Envio | `canSendAutomatically()` | MessageTemplates.php |
| Auditoria de Envios | `wp_limpvix_messages_log` | Database |
| Rastreamento C1 | `wp_limpvix_feedback_reminders` | Database |
| Aceites de Briefing | `wp_limpvix_financial_ledger` | Database (imutável) |

---

## 8. GAPS PARA UI ADMIN (NÃO IMPLEMENTADOS)

### 8.1 Críticos

1. **Dashboard de Métricas**
   - Total de mensagens (fluxo, canal, período)
   - Taxa de sucesso/erro por provider
   - Feedback distribution (1⭐-5⭐)
   - Tentativas vs completadas (C1)

2. **Gerenciamento de C2 (≤3⭐)**
   - Listar orders com `manual_review_required`
   - Enviar resposta personalizada
   - Marcar como resolvido

3. **Retry Manual de Falhas**
   - Buscar mensagens com status 'failed'
   - Reenviar com um clique
   - Ver último erro do provider

4. **Teste de Conectividade**
   - Botão "Testar Twilio" (SMS real)
   - Botão "Testar 360Dialog" (WhatsApp real)
   - Resposta do provider em tempo real

### 8.2 Secundários

5. **Histórico de Feedback por Ordem**
6. **Opt-out Management**
7. **Template Customizado Advanced**
8. **Webhook Delivery Status**
9. **Analytics & Reporting**
10. **Configuração de Template WhatsApp**

---

## 9. CONTRATO DE INTERFACE (UI ADMIN)

### 9.1 Responsabilidades da UI

**O que UI DEVE fazer:**
- ✅ **Exibir** templates canônicos (read-only)
- ✅ **Listar** fluxos com status
- ✅ **Ativar/desativar** fluxos
- ✅ **Configurar** timing (horas)
- ✅ **Mostrar** métricas agregadas
- ✅ **Permitir** envio manual (casos excepcionais)
- ✅ **Expor** histórico de envios

**O que UI NÃO DEVE fazer:**
- ❌ **Alterar** templates canônicos (domínio)
- ❌ **Criar** novos fluxos automáticos (domínio)
- ❌ **Ignorar** bloqueios de segurança
- ❌ **Bypassar** LGPD/CDC
- ❌ **Modificar** regras de governança

### 9.2 Padrão de Consumo

```php
// UI consome domínio
$templates = MessageTemplates::getAll(); // read-only
$canSend = MessageTemplates::canSendAutomatically($order, $type);

// UI modifica apenas options
update_option('limpvix_active_flows', [
    'C1' => true,
    'C2' => true, // sempre true (forçado)
    'C3' => false
]);

// UI NUNCA altera templates canônicos
// Templates são "compiled" no deploy, não runtime
```

---

## 10. ARQUITETURA VISUAL

```
┌────────────────────────────────────────────────────────────┐
│                     UI ADMIN (A CRIAR)                      │
├────────────────────────────────────────────────────────────┤
│                                                             │
│  CommunicationCenterPage  MessageTemplatesAdminPage        │
│  (Dashboard)              (Gerenciar Templates)            │
│         │                          │                        │
│    [read-only]              [read + options]               │
│         │                          │                        │
└─────────┼──────────────────────────┼──────────────────────┘
          │                          │
          ↓                          ↓
┌────────────────────────────────────────────────────────────┐
│                 DOMÍNIO (IMUTÁVEL)                          │
├────────────────────────────────────────────────────────────┤
│                                                             │
│  MessageTemplates.php                                      │
│  ├─ get('C1.1') → Template canônico                        │
│  ├─ canSendAutomatically() → Governança                   │
│  └─ logFlowStopped() → Auditoria                          │
│                                                             │
│  MessageFlowTriggers.php                                   │
│  ├─ onFeedbackNegative() → Bloqueio C2                    │
│  ├─ onFeedback5Stars() → Disparo C3                       │
│  └─ onServiceCompleted() → Disparo P1                     │
│                                                             │
└────────────────────────────────────────────────────────────┘
          │
          ↓
┌────────────────────────────────────────────────────────────┐
│                 INFRAESTRUTURA                              │
├────────────────────────────────────────────────────────────┤
│                                                             │
│  TwilioSmsProvider    360DialogProvider                    │
│  MessageRepository                                         │
│  FeedbackRemindersCron                                     │
│                                                             │
└────────────────────────────────────────────────────────────┘
```

---

## CONCLUSÃO

Este documento é o **contrato imutável** do sistema de comunicação LimpVix.

**Regras de Ouro:**
1. Templates canônicos são **read-only** para UI
2. Bloqueios de segurança são **invioláveis**
3. UI **consome e expõe**, não cria lógica
4. Governança está no **domínio**, não na UI
5. LGPD/CDC são **não-negociáveis**

**Próxima Etapa:**
Implementar **BLOCO E** (UI Admin) usando este contrato como fonte única da verdade.

---

**Documento aprovado e versionado.**
**Assinatura técnica:** LimpVix Engineering Team
**Data de vigência:** 2026-02-06
