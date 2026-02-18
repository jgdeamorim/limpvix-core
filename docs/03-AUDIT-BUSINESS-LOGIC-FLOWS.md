# AUDITORIA 3/3: LOGICA DE NEGOCIO, FLUXOS E COERENCIA

**Plugin:** limpvix-core
**Data:** 2026-02-18
**Escopo:** State Machines, Use Cases, Cron Jobs, Domain Events, Pagamentos, Comunicacao, Alocacao, Dead Code
**Severidade:** CRITICAL / HIGH / MEDIUM / LOW / INFO

---

## SUMARIO EXECUTIVO

A terceira e ultima auditoria do limpvix-core revela um sistema com arquitetura DDD madura, mas com divergencias estruturais acumuladas entre camadas legadas e novas. Foram identificados **5 achados CRITICAL**, **8 HIGH**, **11 MEDIUM**, **7 LOW** e **5 INFO** -- totalizando **36 achados** que impactam desde a integridade de dados ate a cobranca automatica de contratos.

Os problemas mais graves sao:
1. Bug de propriedade inexistente em Execution.php (`$this->orderId` vs `$this->orderUuid`)
2. Cron de cobranca recorrente registrado mas callback comentado -- pagamentos NUNCA sao cobrados
3. Duas state machines paralelas divergentes para Execution, Order e Finance
4. Status 'expired' escrito no BD mas nao reconhecido pelo Value Object ContractStatus
5. Contract::renew() bypassa a state machine, permitindo transicoes ilegais

**Risco geral:** ALTO -- cobrancas automaticas inoperantes e inconsistencias de estado podem causar perda financeira e corrupcao de dados.

---

## INDICE

1. [State Machines](#1-state-machines)
2. [Use Cases e Fluxos de Negocio](#2-use-cases-e-fluxos-de-negocio)
3. [Cron Jobs e Automacao](#3-cron-jobs-e-automacao)
4. [Domain Events e Listeners](#4-domain-events-e-listeners)
5. [Pagamentos e Financeiro](#5-pagamentos-e-financeiro)
6. [Comunicacao e Notificacoes](#6-comunicacao-e-notificacoes)
7. [Alocacao e Agendamento](#7-alocacao-e-agendamento)
8. [Dead Code e Divida Tecnica](#8-dead-code-e-divida-tecnica)
9. [Tabela Consolidada de Achados](#9-tabela-consolidada-de-achados)
10. [Avaliacao de Risco](#10-avaliacao-de-risco)
11. [Recomendacoes Priorizadas](#11-recomendacoes-priorizadas)

---

## 1. STATE MACHINES

### 1.1 Contract Status

**Arquivo:** `src/Domain/Contract/ValueObjects/ContractStatus.php`

```
  draft --> pending_allocation --> active --> completed  [terminal]
    |            |                  |  |
    +--cancel----+--cancel----------+  +--> paused --> active
                                    |         |
                                    +--cancel-+
```

**Estados:** draft, pending_allocation, active, paused, completed, cancelled
**Terminais:** completed, cancelled

**ACHADO C-01 [CRITICAL]:** Status 'expired' usado em DB mas ausente do VO
- `src/Infrastructure/Automation/ContractAutomation.php:194` executa SQL direto:
  ```sql
  SET status = 'expired', updated_at = NOW()
  ```
- ContractStatus VO rejeita 'expired' com InvalidArgumentException
- Contratos marcados 'expired' no BD ficarao orfaos -- impossivel reconstrui-los via Domain Model

**ACHADO C-02 [CRITICAL]:** Contract::renew() bypassa state machine
- `src/Domain/Contract/Contract.php:291-309`
- Permite `completed --> active` e `cancelled --> active` diretamente
- Usa `$this->status = ContractStatus::active()` sem chamar `ensureCanTransitionTo()`
- Violacao deliberada das transicoes definidas (completed e cancelled sao terminais)
- Metodo `expire()` (L274-286) tem o mesmo problema: nao valida transicao

### 1.2 Execution Status -- DIVERGENCIA CRITICA

Existem **DUAS state machines paralelas e incompativeis** para execucoes:

**Execution (PHP 8.1 Enum - novo):**
Arquivo: `src/Domain/Execution/Enums/ExecutionStatusEnum.php`
```
  CREATED --> CHECKED_IN --> IN_EXECUTION --> CHECKED_OUT --> VALIDATED --> CLOSED
```
Usado por: `src/Domain/Execution/Execution.php`

**ContractExecution (Value Object - legado):**
Arquivo: `src/Domain/Execution/ExecutionStatus.php`
```
  draft --> scheduled --> in_progress --> completed
                |              |
                +--> cancelled +---> no_show
```
Usado por: `src/Domain/Execution/ContractExecution.php`

**ACHADO C-03 [CRITICAL]:** Duas state machines completamente diferentes para o mesmo conceito
- Execution: 6 estados (CREATED, CHECKED_IN, IN_EXECUTION, CHECKED_OUT, VALIDATED, CLOSED)
- ContractExecution: 6 estados (draft, scheduled, in_progress, completed, cancelled, no_show)
- ZERO intersecao de nomes de estados
- Nenhum adaptador ou bridge entre as duas
- Queries SQL podem misturar os dois conjuntos de estados

### 1.3 Order Status -- DIVERGENCIA

**Order (PHP 8.1 Enum - novo):**
Arquivo: `src/Domain/Order/Enums/OrderStatusEnum.php`
```
  CREATED --> CONFIRMED --> SCHEDULED --> IN_EXECUTION --> COMPLETED --> CLOSED
                                |                              |
                                +--> CANCELLED                 +--> DISPUTED
```
Usado por: `src/Domain/Order/Order.php`

**OrderStatus (Value Object - legado):**
Arquivo: `src/Domain/Order/OrderStatus.php`
```
  created --> validated --> scheduled --> confirmed --> in_progress --> done
                                             |                          |
                                             +--> canceled_ok           +--> failed
                                             +--> canceled_penalty
                                             +--> no_show
```
Usado por: `src/Domain/Order/OrderPolicy.php`

**ACHADO H-01 [HIGH]:** OrderPolicy usa OrderStatus legado mas Order usa OrderStatusEnum novo
- `OrderPolicy.php:147` chama `OrderStatus::IN_PROGRESS()` -- nao existe no Enum
- `OrderPolicy.php:129` chama `$order->getScheduledAt()` -- metodo nao existe em Order
- `OrderPolicy.php:170` chama `$order->getPrice()` -- metodo nao existe em Order
- `OrderPolicy.php:237` chama `$order->getMetadata()` -- metodo nao existe em Order
- Resultado: OrderPolicy e COMPLETAMENTE INUTILIZAVEL com o Order atual

### 1.4 Financial Status -- DIVERGENCIA

**Financial (PHP 8.1 Enum - novo):**
Arquivo: `src/Domain/Finance/Enums/FinancialStatusEnum.php`
```
  PENDING --> AUTHORIZED --> CAPTURED --> HELD --> PAYOUT_AUTHORIZED --> PAYOUT_COMPLETED
     |             |           |           |
     +--> FAILED   +-----------+-----------+--> REFUNDED
```
Estado orfao: RELEASED (existe na tabela de transicoes mas nenhum metodo transiciona para ele)

**FinancialStatus (Value Object - legado):**
Arquivo: `src/Domain/Finance/FinancialStatus.php`
```
  CREATED --> PAID --> HELD --> REVIEW --> AUTHORIZED --> TRANSFERRED
                        |                                     |
                        +--> BLOCKED                          +--> REFUNDED
```

**ACHADO H-02 [HIGH]:** Estado RELEASED e um dead state
- `src/Domain/Finance/Financial.php:220-222` define transicao RELEASED --> PAYOUT_AUTHORIZED
- Nenhum metodo no aggregate Financial transiciona PARA RELEASED
- Estado inacessivel -- dead code na state machine

### 1.5 RecurringPayment Status

**Arquivo:** `src/Domain/Finance/ValueObjects/RecurringPaymentStatus.php`
```
  pending --> processing --> completed  [terminal]
                  |
                  +--> failed --> processing (retry, max 3x)
                         |
                         +--> cancelled  [terminal]
```

**Status:** OK -- bem implementada, com retry logic e invariantes claras.

---

## 2. USE CASES E FLUXOS DE NEGOCIO

### 2.1 Inventario de Use Cases

O plugin possui DOIS diretorios de use cases (indicando migracao incompleta):

| Diretorio | Arquivos | Estilo |
|---|---|---|
| `src/Application/UseCase/` (singular) | 43 | Legado |
| `src/Application/UseCases/` (plural) | 56 | Novo |

**ACHADO M-01 [MEDIUM]:** Use case duplicado
- `src/Application/UseCase/RenewContract.php`
- `src/Application/UseCases/Contract/RenewContract.php`
- Dois arquivos com o mesmo proposito em namespaces diferentes
- Risco de chamar o errado

**ACHADO M-02 [MEDIUM]:** ScheduleOrder e um stub completo
- `src/Application/UseCases/ScheduleOrder.php`
- Contem 6 TODOs e nenhuma logica implementada
- Referenciado por OrderPolicy mas nunca funcional

### 2.2 Fluxo Principal: Contrato --> Execucao --> Pagamento

```
 [1. Criar Contrato]
        |
        v
 [2. Alocar Profissional] (AllocationEngine)
        |
        v
 [3. Ativar Contrato]
        |
        v
 [4. Criar Execution] --> Check-in --> In Execution --> Check-out --> Validate --> Close
        |                    ^                                            |
        |                    |                                            v
        |               Geofence OK?                             [5. Criar Financial]
        |                                                                |
        |                                                                v
        |                                                   Autorizar Pagamento
        |                                                                |
        |                                                                v
        |                                                    [6. Payout ao Profissional]
        v
 [7. Feedback do Cliente]
        |
        +---> 5 estrelas --> Convite Google Review (C3)
        +---> 4 estrelas --> Payout com 24h delay
        +---> 1-3 estrelas --> Bloqueio automatico (C2)
```

### 2.3 Fluxo de Cobranca Recorrente (On-Demand)

```
 [RecurringPaymentCronAdapter] (diario 00:00)
        |
        v
 Buscar contratos com nextExecution <= hoje + 3 dias
        |
        v
 Para cada contrato:
   ChargeRecurringPayment.execute(contractId)
        |
        v
 Criar RecurringPayment(pending)
        |
        v
 Enviar ao Gateway (EFI Bank / PIX)
        |
        v
 Webhook de resposta --> ProcessPaymentWebhook
        |
        +---> approved --> markAsCompleted --> Contract.renewWithPayment()
        +---> rejected --> markAsFailed --> retry se attempt < 3

 [Retry de Falhas]
 RetryFailedPayment.retryAllPendingPayments()
   Buscar payments failed com attempt < 3 e age > 2 dias
```

---

## 3. CRON JOBS E AUTOMACAO

### 3.1 Inventario de Cron Jobs

| Hook | Frequencia | Adapter | Status |
|---|---|---|---|
| `limpvix_fallback_send_offers` | Hourly | SendOffersCronAdapter | OK |
| `limpvix_payment_authorization_timeout` | Hourly | PaymentAuthorizationTimeoutCronAdapter | PARCIAL |
| `limpvix_reconcile_payouts` | 6h | PayoutReconciliationCronAdapter | OK |
| `limpvix_charge_recurring_payments` | Daily | RecurringPaymentCronAdapter | INOPERANTE |
| `limpvix_sync_payouts` | 15min | PayoutReconciliationService | INOPERANTE |
| `limpvix_feedback_reminder_*` | Varies | FeedbackRemindersCron | OK |

### 3.2 Achados em Cron Jobs

**ACHADO C-04 [CRITICAL]:** RecurringPaymentCronAdapter registrado mas callback comentado
- `src/Infrastructure/Cron/RecurringPaymentCronAdapter.php:308-336` registra o wp_schedule_event
- `src/Core/ContractBootstrap.php:461` tem o add_action COMENTADO:
  ```php
  // add_action('limpvix_charge_recurring_payments', [self::class, 'onChargeRecurringPayments']);
  ```
- O cron dispara diariamente mas nada acontece
- **Impacto:** Nenhum contrato recorrente e cobrado automaticamente

**ACHADO H-03 [HIGH]:** PaymentAuthorizationTimeout com TODOs no gateway
- `src/Infrastructure/Cron/PaymentAuthorizationTimeoutCronAdapter.php`
- `capturePayment()` e `cancelAuthorization()` contem:
  ```php
  // TODO: Implement actual capture/cancel logic via gateway API
  ```
- Apenas atualiza status local -- pagamentos podem ficar em limbo no gateway
- Desconexao entre estado local e estado real no MercadoPago/EFI

**ACHADO H-04 [HIGH]:** Schedule 'every_15_minutes' nunca registrado
- `src/Application/Services/PayoutReconciliationService.php:304`:
  ```php
  wp_schedule_event(time(), 'every_15_minutes', 'limpvix_sync_payouts');
  ```
- O schedule 'every_15_minutes' nao e registrado via `cron_schedules` filter
- `AdminBootstrap.php:5085` lista como documentacao mas NAO registra
- Cron `limpvix_sync_payouts` nunca sera executado pelo WordPress

---

## 4. DOMAIN EVENTS E LISTENERS

### 4.1 Mapa de Eventos Disparados

| Evento | Disparado Em | Mecanismo |
|---|---|---|
| `limpvix_execution_checked_in` | Execution.php:161 | do_action |
| `limpvix_execution_validated` | (nao encontrado) | do_action esperado |
| `limpvix_feedback_negative_received` | FeedbackProcessor | do_action |
| `limpvix_feedback_positive_received` | FeedbackProcessor | do_action |
| `limpvix_payment_authorized` | Financial flow | do_action |
| `limpvix_payment_blocked` | Financial flow | do_action |
| `limpvix_notify_admin` | MessageFlowTriggers | do_action |
| `RecurringPaymentCompleted` | RecurringPayment.php:250 | Domain Event |
| `RecurringPaymentFailed` | RecurringPayment.php:278 | Domain Event |
| `ContractExpired` | Contract.php:285 | Domain Event |
| `FeedbackApproved` | FeedbackProcessor | Domain Event |

### 4.2 Mapa de Listeners Registrados

| Hook / Evento | Listener | Arquivo |
|---|---|---|
| `limpvix_feedback_negative_received` | MessageFlowTriggers::onFeedbackNegative | MessageFlowTriggers.php:31 |
| `limpvix_feedback_positive_received` | MessageFlowTriggers::onFeedback5Stars | MessageFlowTriggers.php:34 |
| `limpvix_execution_validated` | MessageFlowTriggers::onExecutionValidated | MessageFlowTriggers.php:37 |
| `limpvix_payment_authorized` | MessageFlowTriggers::onPaymentAuthorized | MessageFlowTriggers.php:40 |
| `limpvix_payment_blocked` | MessageFlowTriggers::onPaymentBlocked | MessageFlowTriggers.php:43 |
| `limpvix_execution_checked_in` | NotifyClientOnCheckIn | EventListeners/ |
| `limpvix_domain_event` (FeedbackApproved) | ReleasePayoutHoldOnFeedbackApproved | EventListeners/ |
| `limpvix_domain_event` (FeedbackApproved) | UpdateProfessionalScoreOnFeedbackApproved | EventListeners/ |

### 4.3 Achados em Eventos

**ACHADO C-05 [CRITICAL]:** Bug de propriedade em Execution.php
- `src/Domain/Execution/Execution.php:161`:
  ```php
  do_action('limpvix_execution_checked_in', $this->executionUuid, $this->orderId, $this->professionalId);
  ```
- A propriedade e `$this->orderUuid` (L40), NAO `$this->orderId`
- `$this->orderId` nao existe -- em PHP isso retorna `null` sem erro fatal (propriedade dinamica)
- **Impacto:** Todos os listeners de check-in recebem `null` como order identifier
- NotifyClientOnCheckIn nao consegue localizar o pedido para notificar o cliente

**ACHADO H-05 [HIGH]:** onExecutionValidated registrado mas nao implementado
- `MessageFlowTriggers.php:37` registra:
  ```php
  add_action('limpvix_execution_validated', [__CLASS__, 'onExecutionValidated'], 10, 1);
  ```
- O metodo `onExecutionValidated()` NAO existe na classe MessageFlowTriggers
- WordPress chamara um metodo inexistente -- warning silencioso, nenhuma notificacao enviada

**ACHADO H-06 [HIGH]:** RecurringPaymentCompleted sem listener
- `RecurringPayment.php:250` dispara `RecurringPaymentCompleted` domain event
- Nenhum listener registrado para este evento (busca completa no codebase)
- Evento serve para renovar contrato apos pagamento confirmado
- Contract::renewWithPayment() nunca e chamado automaticamente

**ACHADO M-03 [MEDIUM]:** Evento ContractExpired disparado sem listeners
- `Contract.php:285` emite `ContractExpired` event
- Nenhum listener encontrado
- Contratos expiram sem notificacao ao cliente ou profissional

---

## 5. PAGAMENTOS E FINANCEIRO

### 5.1 Fluxo Financial (Pagamento por OS)

```
  PENDING
    |
    v
  AUTHORIZED (pago pelo cliente, nao capturado)
    |
    v
  CAPTURED (capturado pelo gateway)
    |
    v
  HELD (retido ate validacao de feedback)
    |
    v
  PAYOUT_AUTHORIZED (aprovado para repasse ao profissional)
    |
    v
  PAYOUT_COMPLETED [terminal]

  Branches:
    AUTHORIZED/CAPTURED/HELD --> REFUNDED [terminal]
    PENDING --> FAILED [terminal]
```

### 5.2 Regras de Payout (FinancialPolicy)

| Rating | Acao | Delay |
|---|---|---|
| 5 estrelas | Payout imediato + Convite Google Review | 0h |
| 4 estrelas | Payout com delay | 24h |
| 3 estrelas ou menos | Bloqueio automatico | Manual review |
| Sem feedback (timeout 24h) | Payout automatico | 24h |

### 5.3 Cobranca Recorrente (On-Demand)

**Modelo de cobranca por execucao (nao por mes calendario):**

| Frequencia | Divisor | Exemplo (R$600/mes) |
|---|---|---|
| weekly | 4.33 | R$138.57/execucao |
| biweekly | 2.16 | R$277.78/execucao |
| monthly | 1.00 | R$600.00/execucao |

**ACHADO M-04 [MEDIUM]:** Divisor 2.16 para biweekly pode gerar erro acumulado
- `RecurringPayment::calculateExecutionValue()` usa divisor fixo 2.16
- 12 meses x 2 execucoes/mes = 24 cobracas a R$277.78 = R$6,666.72
- Valor anual esperado: R$7,200.00
- **Diferenca acumulada:** R$533.28/ano por contrato (7.4% de perda)
- Divisor mais preciso seria 2.1667 (26 periodos/12 meses)

### 5.4 Platform Fee

**Arquivo:** `src/Application/Services/PlatformFeeCalculator.php`

**ACHADO M-05 [MEDIUM]:** Uso de `float` para calculos financeiros
- `PlatformFeeCalculator::calculate()` usa `round($totalAmount * ($feePercentage / 100), 2)`
- Em PHP, `float` pode causar problemas de arredondamento (IEEE 754)
- Exemplo: `0.1 + 0.2 = 0.30000000000000004`
- Para valores financeiros, recomenda-se `bcmath` ou inteiros em centavos

---

## 6. COMUNICACAO E NOTIFICACOES

### 6.1 Canais

| Canal | Provider | Status |
|---|---|---|
| SMS | TwilioSmsProvider | Configuravel |
| WhatsApp | WhatsApp360DialogProvider | Configuravel |
| Google Review | GoogleBusinessReviewHelper | Configuravel |

### 6.2 Fluxos de Mensagens (MessageFlowTriggers)

| Fluxo | Trigger | Handler | Status |
|---|---|---|---|
| C1: Feedback Reminder | FeedbackRemindersCron | -- | OK |
| C2: Feedback Negativo | limpvix_feedback_negative_received | onFeedbackNegative | OK (bloqueio deliberado) |
| C3: Google Review | limpvix_feedback_positive_received | onFeedback5Stars | OK |
| P1: Execucao Validada | limpvix_execution_validated | onExecutionValidated | BROKEN (metodo ausente) |
| P2: Pagamento Autorizado | limpvix_payment_authorized | onPaymentAuthorized | OK |
| P3: Pagamento Bloqueado | limpvix_payment_blocked | onPaymentBlocked | OK |

**ACHADO H-07 [HIGH]:** Fluxo P1 (notificacao ao profissional) completamente inoperante
- `onExecutionValidated` referenciado no add_action mas metodo nao existe
- Profissional NUNCA e notificado quando sua execucao e validada
- Metodo `onServiceCompleted()` existe mas e para outro hook (nunca registrado)

---

## 7. ALOCACAO E AGENDAMENTO

### 7.1 AllocationEngine

**Arquivo:** `src/Application/Services/Scheduling/AllocationEngine.php`

**Scoring:**
| Criterio | Peso |
|---|---|
| Proximidade | 40% |
| Disponibilidade | 30% |
| Rating | 20% |
| Carga atual | 10% |

**ACHADO M-06 [MEDIUM]:** findCommonSlot() e um stub simplificado
- Nao calcula intersecao real de horarios
- Retorna primeiro horario disponivel sem considerar conflitos
- Pode gerar double-booking em cenarios de alta demanda

### 7.2 Geofencing

**Arquivo:** `src/Domain/Execution/Execution.php`

- Raio padrao: 150 metros
- Validacao via formula Haversine (GeoLocation VO)
- Check-in fora do raio gera SLA violation mas NAO bloqueia a execucao

**ACHADO M-07 [MEDIUM]:** GeolocationAdapter e completamente stubbed
- Metodos de reverse geocoding e distance calculation retornam valores hardcoded
- Geofencing funciona apenas com coordenadas diretas, sem validacao de endereco

---

## 8. DEAD CODE E DIVIDA TECNICA

### 8.1 TODOs no Codebase

Total encontrado: **62+ TODOs**

| Arquivo | TODOs | Criticidade |
|---|---|---|
| `src/Core/Hooks.php` | 14 | Alta (hooks nunca implementados) |
| `PaymentAuthorizationTimeoutCronAdapter.php` | 2 | Alta (gateway logic) |
| `OrderPolicy.php` | 2 | Media (validacao incompleta) |
| `KYC Providers (Ppid, Exato)` | 6+ | Media (stubs completos) |
| Diversos | 38+ | Baixa-Media |

### 8.2 Codigo Deprecated

**ACHADO M-08 [MEDIUM]:** ContractAutomation deprecated desde 0.8.0
- `src/Infrastructure/Automation/ContractAutomation.php`
- Marcado como `@deprecated since 0.8.0`
- Ainda executa SQL direto que escreve 'expired' no BD (ver achado C-01)
- Se invocado, corrompe dados

### 8.3 Classes Mortas / Stub

| Classe | Motivo |
|---|---|
| `PpidKycProvider` | Stub completo |
| `ExatoBackgroundProvider` | Stub completo |
| `GeolocationAdapter` | Retorna hardcoded |
| `ScheduleOrder` (use case) | 6 TODOs, sem logica |
| `OrderPolicy` | Incompativel com Order atual |

### 8.4 Duplicacoes

**ACHADO M-09 [MEDIUM]:** Diretorios UseCase vs UseCases
- `src/Application/UseCase/` (43 arquivos, singular, legado)
- `src/Application/UseCases/` (56 arquivos, plural, novo)
- RenewContract duplicado em ambos
- Namespace mismatch pode causar autoload de classe errada

**ACHADO L-01 [LOW]:** Value Objects vs Enums coexistindo
- ExecutionStatus (VO) + ExecutionStatusEnum (Enum) -- estados diferentes
- OrderStatus (VO) + OrderStatusEnum (Enum) -- estados diferentes
- FinancialStatus (VO) + FinancialStatusEnum (Enum) -- estados diferentes
- ContractStatus (VO) -- sem equivalente Enum (unico)
- RecurringPaymentStatus (VO) -- sem equivalente Enum (unico)

**ACHADO L-02 [LOW]:** class_exists() com double backslash
- `MessageFlowTriggers.php:391`:
  ```php
  if (!class_exists('LimpVix\\\\Infrastructure\\\\Communication\\\\Providers\\\\TwilioSmsProvider'))
  ```
- String com aspas simples e 4 barras -- em PHP isso e `LimpVix\\Infrastructure\\...` (2 barras literais)
- Deveria ser `'LimpVix\\Infrastructure\\Communication\\Providers\\TwilioSmsProvider'` (2 barras)
- class_exists() NUNCA encontrara a classe, causando falso negativo silencioso

**ACHADO L-03 [LOW]:** WhatsApp360DialogProvider mesmo problema
- `MessageFlowTriggers.php:432` -- mesma duplicacao de backslashes
- sendViaWhatsApp() sempre retorna false sem erro

**ACHADO L-04 [LOW]:** sendViaSMS/sendViaWhatsApp nunca enviam mensagens
- Consequencia direta de L-02 e L-03
- Todas as mensagens SMS e WhatsApp do MessageFlowTriggers NUNCA sao enviadas
- onPaymentAuthorized, onPaymentBlocked, onServiceCompleted -- todos silenciosamente falham

**ACHADO L-05 [LOW]:** PayoutReconciliationService instancia dependencias diretamente
- Viola principio de Dependency Injection
- Dificulta testes unitarios

**ACHADO L-06 [LOW]:** OAuth tokens armazenados sem criptografia
- Tokens de gateway armazenados via `get_option()` / `update_option()`
- WordPress options table e texto plano
- Risco de vazamento via SQL injection ou backup

**ACHADO L-07 [LOW]:** error_log como mecanismo principal de auditoria
- RecurringPaymentCronAdapter, PlatformFeeCalculator, e outros usam `error_log()`
- Em producao, logs podem ser descartados ou nao persistidos
- Recomendado: tabela de auditoria dedicada

### 8.5 Achados INFO

**ACHADO I-01 [INFO]:** AllocationEngine scoring funcional mas simplificado
- Pesos hardcoded (40/30/20/10)
- Sem A/B testing ou ajuste dinamico
- Adequado para fase atual do produto

**ACHADO I-02 [INFO]:** FeedbackRemindersCron bem implementado
- Cadencia 24h/48h/72h configuravel
- Respeita opt-out e limites de envio
- Integrado com MessageTemplates

**ACHADO I-03 [INFO]:** RecurringPayment domain model robusto
- State machine clara com retry logic
- Domain events para auditoria
- calculateExecutionValue() com modelo on-demand

**ACHADO I-04 [INFO]:** FinancialPolicy com regras de payout claras
- Rating-based authorization funcional
- Guards para disputas e profissionais invalidos
- Delay de 24h para 4 estrelas implementado

**ACHADO I-05 [INFO]:** WordPressEventDispatcher com reflection fallback
- Converte domain events para WordPress actions
- Naming convention consistente: limpvix_{aggregate}_{event}
- Fallback por reflection e criativo mas fragil

---

## 9. TABELA CONSOLIDADA DE ACHADOS

| ID | Severidade | Area | Descricao | Arquivo Principal |
|---|---|---|---|---|
| C-01 | CRITICAL | State Machine | 'expired' no BD sem VO | ContractAutomation.php, ContractStatus.php |
| C-02 | CRITICAL | State Machine | renew()/expire() bypassa state machine | Contract.php:291-309 |
| C-03 | CRITICAL | State Machine | Duas SMs paralelas divergentes (Exec/Order/Finance) | ExecutionStatusEnum.php vs ExecutionStatus.php |
| C-04 | CRITICAL | Cron | Callback de cobranca recorrente COMENTADO | ContractBootstrap.php:461 |
| C-05 | CRITICAL | Domain Event | $this->orderId inexistente (deveria ser orderUuid) | Execution.php:161 |
| H-01 | HIGH | State Machine | OrderPolicy usa VO legado, metodos inexistentes | OrderPolicy.php |
| H-02 | HIGH | State Machine | RELEASED e dead state inacessivel | Financial.php:220-222 |
| H-03 | HIGH | Cron | Gateway capture/cancel sao TODOs | PaymentAuthorizationTimeoutCronAdapter.php |
| H-04 | HIGH | Cron | Schedule 'every_15_minutes' nao registrado | PayoutReconciliationService.php:304 |
| H-05 | HIGH | Communication | onExecutionValidated metodo ausente | MessageFlowTriggers.php:37 |
| H-06 | HIGH | Domain Event | RecurringPaymentCompleted sem listener | RecurringPayment.php:250 |
| H-07 | HIGH | Communication | Fluxo P1 inteiro inoperante | MessageFlowTriggers.php |
| H-08 | HIGH | Domain Event | ContractExpired sem listener | Contract.php:285 |
| M-01 | MEDIUM | Use Cases | RenewContract duplicado em dois diretorios | UseCase/ vs UseCases/ |
| M-02 | MEDIUM | Use Cases | ScheduleOrder e stub completo | ScheduleOrder.php |
| M-03 | MEDIUM | Domain Event | ContractExpired sem notificacoes | Contract.php:285 |
| M-04 | MEDIUM | Finance | Divisor 2.16 biweekly gera erro acumulado 7.4%/ano | RecurringPayment.php |
| M-05 | MEDIUM | Finance | Float para calculos financeiros | PlatformFeeCalculator.php |
| M-06 | MEDIUM | Scheduling | findCommonSlot() e stub | AllocationEngine.php |
| M-07 | MEDIUM | Scheduling | GeolocationAdapter e stub | GeolocationAdapter.php |
| M-08 | MEDIUM | Dead Code | ContractAutomation deprecated mas ativo | ContractAutomation.php |
| M-09 | MEDIUM | Dead Code | UseCase vs UseCases coexistindo | src/Application/ |
| M-10 | MEDIUM | Communication | class_exists com backslash duplicado em SMS | MessageFlowTriggers.php:391 |
| M-11 | MEDIUM | Communication | class_exists com backslash duplicado em WhatsApp | MessageFlowTriggers.php:432 |
| L-01 | LOW | Architecture | VOs e Enums coexistindo com estados diferentes | Multiplos |
| L-02 | LOW | Communication | class_exists TwilioSmsProvider sempre false | MessageFlowTriggers.php:391 |
| L-03 | LOW | Communication | class_exists WhatsApp360Dialog sempre false | MessageFlowTriggers.php:432 |
| L-04 | LOW | Communication | sendViaSMS/sendViaWhatsApp nunca enviam | MessageFlowTriggers.php |
| L-05 | LOW | Architecture | DI violado em PayoutReconciliationService | PayoutReconciliationService.php |
| L-06 | LOW | Security | OAuth tokens sem criptografia | Options table |
| L-07 | LOW | Observability | error_log como audit trail | Multiplos |
| I-01 | INFO | Scheduling | AllocationEngine scoring adequado | AllocationEngine.php |
| I-02 | INFO | Communication | FeedbackRemindersCron robusto | FeedbackRemindersCron.php |
| I-03 | INFO | Finance | RecurringPayment domain model solido | RecurringPayment.php |
| I-04 | INFO | Finance | FinancialPolicy regras claras | FinancialPolicy.php |
| I-05 | INFO | Events | WordPressEventDispatcher funcional | WordPressEventDispatcher.php |

---

## 10. AVALIACAO DE RISCO

### 10.1 Risco Financeiro: ALTO

- **C-04:** Cobrancas recorrentes NUNCA executam -- perda de receita direta
- **H-03:** Autorizacoes de pagamento nao capturadas/canceladas no gateway
- **M-04:** Erro acumulado de 7.4%/ano no calculo biweekly
- **H-06:** Contratos nao renovam automaticamente apos pagamento

### 10.2 Risco de Integridade de Dados: ALTO

- **C-01:** Status 'expired' cria registros irrecuperaveis pelo domain model
- **C-03:** Duas state machines geram queries com estados misturados
- **C-02:** renew() permite transicoes ilegais de estados terminais

### 10.3 Risco Operacional: MEDIO

- **C-05:** Notificacoes de check-in falham silenciosamente
- **L-02/L-03/L-04:** TODAS as mensagens SMS/WhatsApp do MessageFlowTriggers falham
- **H-05/H-07:** Profissionais nunca notificados sobre validacao

### 10.4 Divida Tecnica: MEDIA

- 62+ TODOs, 43 use cases legados, VOs e Enums duplicados
- Migracao VO-->Enum incompleta em 3 de 5 dominios
- Stubs em producao (KYC, Geolocation, ScheduleOrder)

---

## 11. RECOMENDACOES PRIORIZADAS

### P0: IMEDIATO (Producao em risco)

1. **Fix C-05:** Corrigir `$this->orderId` para `$this->orderUuid` em `Execution.php:161`
   - Impacto: 1 linha
   - Risco: Nulo (fix de bug)

2. **Fix C-04:** Descomentar callback de cobranca recorrente em `ContractBootstrap.php:461`
   - Impacto: 1 linha
   - Risco: Baixo (testar em staging primeiro)
   - **ATENCAO:** Verificar se o metodo `onChargeRecurringPayments` esta implementado

3. **Fix L-02/L-03:** Corrigir double backslash em class_exists
   - `MessageFlowTriggers.php:391` e `MessageFlowTriggers.php:432`
   - Trocar `'LimpVix\\\\Infrastructure\\\\...'` por `'LimpVix\\Infrastructure\\...'`
   - Impacto: 2 linhas
   - Risco: Nulo

4. **Fix H-05:** Implementar metodo `onExecutionValidated()` ou remover o add_action
   - Impacto: 1 metodo ou 1 linha removida
   - Risco: Nulo

### P1: SPRINT PROXIMO (Integridade de dados)

5. **Fix C-01:** Adicionar 'expired' ao ContractStatus VO OU remover ContractAutomation
   - Opcao A: Adicionar `const EXPIRED = 'expired'` ao VO (mais seguro)
   - Opcao B: Deprecar ContractAutomation e migrar logica para Contract::expire() (mais limpo)

6. **Fix C-02:** Fazer renew() e expire() usarem `ensureCanTransitionTo()`
   - Adicionar transicoes `completed --> active` e `cancelled --> active` ao ALLOWED_TRANSITIONS
   - OU criar metodos explicitos que documentam a excecao

7. **Fix H-06:** Criar listener para RecurringPaymentCompleted
   - Deve chamar Contract::renewWithPayment()
   - Registro via WordPressEventDispatcher

8. **Fix C-03:** Iniciar migracao para state machine unica
   - Escolher Enum (recomendado) ou VO, nao ambos
   - Deprecar ContractExecution em favor de Execution
   - Criar migration script para converter estados no BD

9. **Fix H-03:** Implementar capture/cancel real no PaymentAuthorizationTimeout

### P2: MEDIO PRAZO (Qualidade e manutencao)

10. **Fix H-01:** Atualizar OrderPolicy para usar OrderStatusEnum e metodos existentes do Order
11. **Fix H-02:** Remover RELEASED da tabela de transicoes do Financial ou criar metodo para acessar
12. **Fix H-04:** Registrar schedule 'every_15_minutes' ou substituir por schedule existente
13. **Fix M-01:** Unificar diretorios UseCase/ e UseCases/
14. **Fix M-04:** Corrigir divisor biweekly para 2.1667
15. **Fix M-05:** Migrar calculos financeiros para `bcmath` ou inteiros em centavos
16. **Fix M-08:** Remover ContractAutomation deprecated
17. **Fix L-06:** Criptografar tokens OAuth em wp_options

---

## ESTIMATIVA DE ESFORCO

| Prioridade | Achados | Esforco Estimado |
|---|---|---|
| P0 (Imediato) | 4 fixes | 2-4 horas |
| P1 (Sprint) | 5 fixes | 3-5 dias |
| P2 (Medio prazo) | 8 fixes | 2-3 sprints |
| **Total** | **17 fixes acionaveis** | ~1 mes |

---

*Documento gerado em 2026-02-18 por auditoria automatizada de codigo.*
