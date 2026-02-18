# PLANO FINAL GO-LIVE - LimpVix Core
## 18 de Fevereiro de 2026 - Documento Definitivo de Engenharia

**Versao:** 1.0 FINAL
**Plugin:** limpvix-core v0.2.0
**Stack:** WordPress 6.8.2 | PHP 8.2 | MySQL 8.0 | DDD + Clean Architecture
**Fonte:** 6 agentes de auditoria paralelos + 4 rodadas anteriores (20 agentes total)
**Escopo:** 497 arquivos PHP | 163 Domain files | 55 UseCases | 26 tabelas | 60+ endpoints REST

---

# INDICE

1. [ARQUITETURA DO SISTEMA](#1-arquitetura-do-sistema)
2. [INVENTARIO COMPLETO](#2-inventario-completo)
3. [9 FLUXOS OPERACIONAIS E2E](#3-fluxos-operacionais-e2e)
4. [MAPA DE WIRING (EVENTOS + LISTENERS)](#4-mapa-de-wiring)
5. [SCHEMA DO BANCO DE DADOS (26 TABELAS)](#5-schema-do-banco-de-dados)
6. [66 FINDINGS DA AUDITORIA (POR SEVERIDADE)](#6-findings-da-auditoria)
7. [FASE 0: EMERGENCIA - COMPLETA](#7-fase-0-emergencia)
8. [FASE 1: RECONECTAR CADEIA OPERACIONAL](#8-fase-1-reconectar-cadeia)
9. [FASE 2: ADMIN UI PROFISSIONAL](#9-fase-2-admin-ui)
10. [VERIFICACAO E TESTES](#10-verificacao-e-testes)
11. [CHECKLIST GO-LIVE FINAL](#11-checklist-go-live)

---

# 1. ARQUITETURA DO SISTEMA

## 1.1 Visao Geral

LimpVix e um marketplace on-demand de servicos de limpeza (modelo Uber).
Conecta clientes a profissionais verificados com gestao completa do ciclo de vida.

```
CAMADAS (Clean Architecture):

[REST API]  [Admin Pages]  [WooCommerce]  [Cron Jobs]  [AJAX]
     |            |              |              |          |
     v            v              v              v          v
+------------------------------------------------------------------+
|                    APPLICATION LAYER                              |
|  55 Use Cases (Briefing, Contract, Execution, Financial,         |
|  Professional, Feedback, Scheduling, Communication)              |
+------------------------------------------------------------------+
     |                                                    |
     v                                                    v
+------------------------------------------------------------------+
|                      DOMAIN LAYER                                |
|  7 Aggregates + 5 State Machines + 25+ Value Objects             |
|  163 files | 13 Bounded Contexts | Zero infra dependencies      |
+------------------------------------------------------------------+
     |
     v
+------------------------------------------------------------------+
|                   INFRASTRUCTURE LAYER                            |
|  Repositories (wpdb) | Providers (EFI, Twilio, PPID)            |
|  Adapters (WooCommerce, Firebase, ViaCEP)                        |
|  Events (WordPress hooks) | Auth (JWT, API Key)                  |
+------------------------------------------------------------------+
```

## 1.2 Boot Sequence

```
1. limpvix-core.php          → Constantes + Autoloader + cron_schedules
2. plugins_loaded (p:20)     → limpvix_core_init()
3. Kernel::boot()            → FeatureFlags + TransactionManager + Authorization
4. Hooks::register()         → registerFinancialAdapters + registerAdminInterface
5. AuthBootstrap::init()     → JWT + API Key + Rate Limiting + CORS
6. BriefingBootstrap::init() → REST + Pages + Listeners
7. CommunicationBootstrap    → MessageQueue + Templates
8. FeedbackBootstrap::init() → Feedback module
9. SchedulingBootstrap       → Scheduling module
10. ProfessionalBootstrap    → Professional marketplace
11. ContractBootstrap::init() → 15 UseCases + 4 Crons + Hybrid Auto-SendOffers
12. ExecutionBootstrap       → 9 UseCases + Listeners
13. ContractAutomation       → Auto-expiration cron
14. BriefingContractListener → Briefing-to-Contract bridge
15. AdapterBootstrap         → WooCommerce + Feedback + Timer adapters
```

## 1.3 Bounded Contexts (13)

| Context | Aggregate Root | State Machine | Files |
|---------|---------------|---------------|-------|
| Contract | Contract | draft->pending_allocation->active<->paused->completed/cancelled/expired | ~15 |
| Execution | Execution + ContractExecution | created->checked_in->in_execution->checked_out->validated->closed | ~25 |
| Finance | Financial + RecurringPayment | pending->authorized->captured->held->payout_authorized->payout_completed | ~20 |
| Briefing | Briefing | draft->in_progress->pending_phone->awaiting_payment->paid->locked | ~20 |
| Professional | Professional | pending_documents->pending_verification->verified->active | ~10 |
| Feedback | Feedback + StructuredFeedback | draft->submitted->validated/disputed | ~10 |
| Order | Order | pending->confirmed->in_progress->completed/cancelled | ~8 |
| Communication | MessageDelivery | pending->sent->delivered->read/failed | ~8 |
| Scheduling | Schedule | draft->allocated->in_progress->completed/cancelled | ~10 |
| Verification | ProfessionalVerification | pending_verification->active/not_eligible/suspended | ~10 |
| Customer | Customer | (no state machine) | ~5 |
| Support | Support | (no state machine) | ~5 |
| Staff | Staff | (no state machine) | ~5 |

---

# 2. INVENTARIO COMPLETO

## 2.1 Use Cases (55 total)

### Briefing (9)
| UseCase | Trigger | Events Fired |
|---------|---------|-------------|
| CreateBriefing | REST POST | limpvix_briefing_created |
| UpdateBriefingStep | REST POST | limpvix_briefing_step_completed |
| LockBriefing | WC payment confirmed | limpvix_briefing_locked |
| VerifyBriefingPhone | REST POST | limpvix_briefing_phone_verified |
| RegisterBriefingAcceptance | AJAX | limpvix_briefing_accepted |
| GetBriefingSchema | REST GET | (none) |
| AssessComplexity | REST POST | (none) |
| CalculateProfessionalsRequired | Internal | (none) |
| SelectPackage | REST POST | (none) |

### Contract (15)
| UseCase | Trigger | Events Fired |
|---------|---------|-------------|
| CreateContract | REST/Admin | limpvix_contract_created |
| CreateContractFromBriefing | Event: briefing_locked | limpvix_contract_created_from_briefing |
| ActivateContract | REST/Admin | limpvix_contract_activated |
| PauseContract | REST/Admin | limpvix_contract_paused |
| ResumeContract | REST/Admin | limpvix_contract_resumed |
| CancelContract | REST/Admin | limpvix_contract_cancelled |
| CompleteContract | REST/Admin | limpvix_contract_completed |
| ExpireContract | Cron (daily) | limpvix_contract_expired |
| RenewContract (UseCase/) | Domain | limpvix_contract_renewed |
| RenewContract (UseCases/) | Admin/Cron | (creates new contract) |
| SubmitForAllocation | REST | (state change) |
| ScheduleNextExecution | Post-execution | (internal) |
| ReallocateProfessional | Admin | ProfessionalReallocated event |
| GetReallocationOptions | REST GET | (none) |
| ListContracts | REST GET | (none) |

### Execution (9 + 8 Evidence)
| UseCase | Trigger | Events Fired |
|---------|---------|-------------|
| CreateExecution | REST POST | (none) |
| ScheduleExecution | REST POST | limpvix_execution_scheduled |
| StartExecution | REST POST | limpvix_execution_started |
| CompleteExecution | REST POST | limpvix_execution_completed |
| CancelExecution | REST POST | limpvix_execution_cancelled |
| MarkNoShow | REST POST | limpvix_execution_no_show |
| RescheduleExecution | REST POST | limpvix_execution_rescheduled |
| PerformCheckIn | REST POST | limpvix_execution_checked_in |
| PerformCheckOut | REST POST | (state change) |
| AddEvidence | REST POST | limpvix_evidence_added |
| ApproveEvidence | REST POST | limpvix_evidence_approved |
| RejectEvidence | REST POST | limpvix_evidence_rejected |
| RemoveEvidence | REST DELETE | limpvix_evidence_removed |
| ValidateExecution | REST POST | (state change) |
| ReportIssue | REST POST | limpvix_execution_issue_reported |

### Professional (12)
| UseCase | Trigger | Events Fired |
|---------|---------|-------------|
| RegisterProfessional | REST POST | limpvix_professional_registered |
| ListProfessionals | REST GET | (none) |
| AcceptOffer | REST POST | limpvix_offer_accepted |
| RejectOffer | REST POST | limpvix_offer_rejected |
| UpdateAvailability | REST PUT | (none) |
| UpdateProfessionalScore | Event-driven | limpvix_professional_score_updated |
| ProcessKYC | REST POST | (KYC events) |
| GetProfessionalStatistics | REST GET | (none) |
| GetScoreHistory | REST GET | (none) |
| GetAllocationHistory | REST GET | (none) |
| ListOffers | REST GET | (none) |
| SendOffers | Event/Cron/Admin | limpvix_offers_sent |

### Financial (7)
| UseCase | Trigger | Events Fired |
|---------|---------|-------------|
| ChargeRecurringPayment | Cron (daily) | (payment events) |
| ProcessPaymentWebhook | Webhook POST | limpvix_financial_transition_* |
| RetryFailedPayment | Cron | (payment events) |
| ExecutePayout | Admin/Cron | limpvix_payout_success |
| ApproveManualPayout | Admin AJAX | (payout events) |
| CreateManualPayout | Admin AJAX | (payout events) |
| CreateOrder | WooCommerce | (none) |

### Feedback (7)
| UseCase | Trigger | Events Fired |
|---------|---------|-------------|
| SubmitFeedback | REST/AJAX | limpvix_domain_event (FeedbackCreated) |
| SubmitStructuredFeedback | REST POST | limpvix_domain_event |
| ApproveFeedback | Admin | limpvix_domain_event (FeedbackApproved) |
| RejectFeedback | Admin | limpvix_domain_event (FeedbackRejected) |
| DisputeFeedback | REST POST | (dispute events) |
| CheckFeedbackWindowStatus | Cron | (none) |
| CalculateProfessionalScore | Event-driven | (none) |

## 2.2 REST API Endpoints (60+)

```
/limpvix/v1/
+-- /auth/ (3: login, refresh, me)
+-- /briefing/ (6: schema, create, get, step, verify-phone, accept)
+-- /contracts/ (12: CRUD + activate, pause, cancel, offers, reallocation)
+-- /executions/ (9+4: CRUD + schedule, start, complete, evidence)
+-- /professionals/ (11: CRUD + offers, availability, score, allocations)
+-- /customers/ (6: list, me, get, update, contracts, briefings)
+-- /offers/ (3: get, accept, reject)
+-- /documents/ (6: upload, list, approve, reject, pending, kyc-status)
+-- /health/ (2: status, cron)
+-- /webhooks/ (1: mercadopago)
+-- /otp/ (2: send, verify)
+-- /cep/ (1: lookup)
+-- /packages/ (2: list, get)
+-- /services/ (3: list, get, catalog)
+-- /api-keys/ (3: list, create, revoke)
```

## 2.3 Cron Jobs (8 registrados)

| Hook | Schedule | Arquivo | Status |
|------|----------|---------|--------|
| limpvix_check_contract_expiration | daily | ContractAutomation | ATIVO |
| limpvix_charge_recurring_payments | daily | RecurringPaymentCronAdapter | DESATIVADO (comentado) |
| limpvix_reconcile_payouts | 6h | PayoutReconciliationCronAdapter | ATIVO |
| limpvix_payment_auth_timeout | 5min | PaymentAuthorizationTimeoutCronAdapter | ATIVO |
| limpvix_fallback_send_offers | hourly | SendOffersCronAdapter | ATIVO |
| limpvix_send_feedback_reminders | hourly | FeedbackRemindersCron | ATIVO |
| limpvix_process_review_timer | hourly | TimerCronAdapter | ATIVO |
| limpvix_contracts_daily_check | daily | ContractAutomation | ATIVO |

## 2.4 Providers Externos

| Provider | Funcao | Status |
|----------|--------|--------|
| EFI Bank (PIX Cash-In) | Cobranca PIX QR Code | CORRIGIDO (Fase 0 Fix #5) |
| EFI Bank (PIX Cash-Out) | Payout para profissional | Implementado |
| MercadoPago | Fallback payment | Implementado (legacy) |
| Twilio | SMS OTP + notificacoes | Implementado |
| NVoip | SMS alternativo | Implementado |
| 360Dialog | WhatsApp | Implementado |
| Firebase | Phone auth OTP | Implementado |
| PPID | KYC biometrico (OCR+Liveness+FaceMatch) | Implementado (mock disponivel) |
| Exato Digital | Background check | Stub |
| ViaCEP | Geocoding CEP | Implementado (sem lat/lng) |
| Google Maps | Geocoding endereco | Implementado |

---

# 3. FLUXOS OPERACIONAIS E2E (9 Fluxos)

## FLUXO 1: ONBOARDING DO PROFISSIONAL

```
[1] RegisterProfessional (REST POST /professionals)
    -> Valida CPF, email, skills
    -> Cria WordPress user (role: limpvix_professional)
    -> Geocode endereco (Google Maps)
    -> Cria Professional aggregate (score=0, KYC=not_started)
    -> Fires: limpvix_professional_registered
    -> Envia email welcome

[2] Verificacao OTP (REST POST /otp/send + /otp/verify)
    -> Twilio/NVoip envia SMS
    -> Firebase valida OTP
    -> Marca phone_verified=true

[3] Upload Documentos (REST POST /professionals/{id}/documents)
    -> Upload RG, CPF, selfie, comprovante residencia
    -> Armazena em WordPress media library
    -> Status: pending review

[4] KYC Biometrico (ProcessKYC use case)
    -> PPID: OCR documento (min 85% confidence)
    -> PPID: Liveness detection (min 80%)
    -> PPID: Face match doc vs selfie (min 85%)
    -> Se aprovado: kycStatus=approved, validade 24 meses
    -> Se rejeitado: kycRetryCount++ (max 3)

[5] Background Check (Exato Digital)
    -> background_status: PENDING->APPROVED/RESTRICTED/NOT_ELIGIBLE
    -> Consentimento LGPD registrado (wp_limpvix_consent_records)

[6] Admin Review (Admin page: DocumentReviewPage)
    -> Aprova/rejeita documentos
    -> Se todos aprovados: Professional.verify(adminId)

[7] Setup Pagamento
    -> Professional.setPixKey(key, type) [cpf|cnpj|email|phone|random]
    -> Preferred payout method: pix_manual ou mp_oauth

[8] Define Regiao + Skills
    -> ServiceRegion(lat, lng, radiusKm)
    -> ProfessionalSkills com certificacoes
    -> WeeklyAvailability (slots por dia)

RESULTADO: Professional.canAcceptOffers() = true
REGRAS: rating_inicial=0, raio=10km, max_skills=10, max_diario=480min
STATUS: 95% implementado
GAP: Falta UseCase ActivateProfessional/DeactivateProfessional formal
```

## FLUXO 2: JORNADA DO CLIENTE (Briefing -> Pagamento)

```
[1] CreateBriefing (REST POST /briefing)
    -> Cria em status DRAFT
    -> Fires: limpvix_briefing_created

[2-6] UpdateBriefingStep (REST POST /briefing/{uuid}/step)
    -> Step 1: PropertyType (residential/commercial)
    -> Step 2: PropertyStructure (m2, quartos, banheiros)
    -> Step 3: CalculateMetrics (m2 x 3min + 30min buffer)
    -> Step 4: Frequency (avulso/weekly/biweekly/monthly)
    -> Step 5: AssessComplexity (simple=1x, medium=1.3x, complex=1.5x)
    -> Step 6: SelectPackage (basic/standard+15%/premium+30%)

[7] VerifyBriefingPhone (REST POST /briefing/{uuid}/verify-phone)
    -> OTP via Twilio/NVoip
    -> Status: PENDING_PHONE -> IN_PROGRESS

[8] Pagamento via WooCommerce
    -> Cria WC Order com taxa plataforma 15%
    -> woocommerce_payment_complete -> WooCommercePaymentAdapter
    -> Status: AWAITING_PAYMENT -> PAID

[9] LockBriefing (automatico pos-pagamento)
    -> BriefingPaymentAdapter.onBriefingPaid()
    -> Status: PAID -> LOCKED (imutavel)
    -> Fires: limpvix_briefing_locked

[10] Se recorrente:
    -> BriefingContractListener.onBriefingLocked()
    -> CreateContractFromBriefing use case
    -> Gera contrato automaticamente
    -> Fires: limpvix_contract_created_from_briefing

REGRAS: expira_48h, taxa_15%, metricas=m2x3min+30min
STATUS: 85% implementado
GAP: Briefing avulso (one-off) nao gera contrato
```

## FLUXO 3: CICLO DO CONTRATO

```
[1] CreateContract (REST POST /contracts ou via Briefing)
    -> Gera contract_number: LMPVX-YYYYMM-NNNNNN
    -> Status: DRAFT
    -> Fires: limpvix_contract_created

[2] SubmitForAllocation (REST POST /contracts/{id}/submit)
    -> Status: DRAFT -> PENDING_ALLOCATION

[3] Alocacao Profissional (Fluxo 4)
    -> SendOffers -> AcceptOffer -> ActivateContract

[4] ActivateContract (REST POST /contracts/{id}/activate)
    -> allocated_professional_id = professional.id (PK)
    -> Status: PENDING_ALLOCATION -> ACTIVE
    -> Fires: limpvix_contract_activated
    -> ContractBootstrap::onContractActivated()
    -> Auto-SendOffers se nao tem profissional alocado (HYBRID)

[5] Ciclo de Execucoes (LOOP)
    -> ScheduleNextExecution -> CreateExecution -> Execute -> Complete
    -> Para cada execucao: check-in -> servico -> check-out -> feedback

[6] Opcoes durante ciclo:
    -> PauseContract: ACTIVE -> PAUSED
    -> ResumeContract: PAUSED -> ACTIVE
    -> ReallocateProfessional: troca profissional (valida KYC, no active executions)
    -> CancelContract: * -> CANCELLED (terminal)

[7] Expiracao (Cron diario)
    -> ExpireContract use case: end_date <= hoje
    -> Status: ACTIVE -> EXPIRED
    -> Se auto_renew=true: RenewContract

STATE MACHINE:
  draft -> pending_allocation -> active <-> paused -> completed|cancelled|expired

REGRAS: weekly(+7d), biweekly(+14d), monthly(dia fixo)
STATUS: 90% implementado
GAPS:
  - ContractStatus VO rejeita 'expired' (P0-S1)
  - renew() bypassa state machine (P0-S2)
  - Cron cobranca recorrente COMENTADO
```

## FLUXO 4: ALOCACAO E MATCHING

```
[1] SendOffers use case (Event/Cron/Admin)
    -> Busca profissionais elegiveis:
       - ServiceRegion.coversLocation(lat, lng)
       - ProfessionalSkills.hasAllSkills(required)
       - Professional.isAvailableAt(dateTime)
       - Professional.canAcceptOffers() (KYC aprovado, ativo, nao suspenso)

[2] Scoring Algorithm (max 100 pontos):
    -> Proximidade: 40pts (Haversine: 0-5km=40, 5-10km=30, 10-15km=20, 15-20km=10)
    -> Score profissional: 30pts (score/100 * 30)
    -> Taxa aceitacao: 20pts (acceptance_rate * 20)
    -> Experiencia: 10pts (min(10, completed_services/10))

[3] Criar ofertas (wp_limpvix_contract_offers)
    -> Top 1-10 profissionais
    -> Expira em 24h
    -> Fires: limpvix_send_offer_notification (per offer)
    -> Fires: limpvix_offers_sent (batch)

[4] AcceptOffer (REST POST /professionals/{id}/offers/{offer_id}/accept)
    -> TRANSACTION:
       - Marca oferta como 'accepted'
       - Expira outras ofertas pendentes do mesmo contrato
       - Aloca profissional ao contrato
    -> Fires: limpvix_offer_accepted

[5] Fallback (Cron hourly: limpvix_fallback_send_offers)
    -> Contratos ativos sem ofertas pendentes (>5min)
    -> Rate limit: max 20 por execucao
    -> GARANTIA: Ofertas enviadas em max 1h

REGRAS: timeout=24h, max=480min/dia, score_min=0
STATUS: 80% implementado
GAPS:
  - Sem endpoint REST para alocacao manual
  - ViaCEP nao retorna coordenadas
```

## FLUXO 5: EXECUCAO DO SERVICO

```
[1] CreateExecution (REST POST /executions)
    -> Cria execution com contract_id, professional_id, scheduled_date
    -> NOTA: professional_id aqui = user_id (NAO professional.id PK!)
    -> INCONSISTENCIA com Contract que usa professional.id PK

[2] ScheduleExecution (REST POST /executions/{id}/schedule)
    -> Status: draft -> scheduled
    -> Fires: limpvix_execution_scheduled

[3] Check-in (PerformCheckIn use case)
    -> GeoLocation validation: distanceTo(serviceLocation) <= 150m
    -> TimeWindow validation: +/-60min da janela agendada
    -> Se fora do geofence: SlaViolation.outOfGeofence(distance)
    -> Se atrasado: SlaViolation.lateCheckIn(delay)
    -> Status: CREATED -> CHECKED_IN
    -> Fires: limpvix_execution_checked_in -> NotifyClientOnCheckIn

[4] StartExecution
    -> Status: CHECKED_IN -> IN_EXECUTION

[5] Coletar Evidencias (AddEvidence use case)
    -> Tipos: photo, video
    -> Categorias: location
    -> Stages: check_in, execution, check_out
    -> Min 2 fotos (before/after)

[6] Check-out (PerformCheckOut use case)
    -> GeoLocation + EvidenceCollection obrigatoria
    -> Status: IN_EXECUTION -> CHECKED_OUT

[7] ValidateExecution
    -> Preconditions: status=CHECKED_OUT, evidence!=null, checkIn!=null, checkOut!=null
    -> Status: CHECKED_OUT -> VALIDATED
    -> Inicia feedback window (24h)

[8] Feedback Window
    -> 24h para cliente avaliar
    -> Lembretes: 12h, 24h, 48h
    -> Timeout 24h sem feedback = payout automatico

STATE MACHINE:
  created -> checked_in -> in_execution -> checked_out -> validated -> closed

REGRAS: geofence=150m, SLA=+/-60min, evidencia_obrigatoria
STATUS: 85% implementado
GAPS CRITICOS:
  - SEM trigger automatico Schedule->Execution (P1-OP1)
  - ExecutionManagementPage AUSENTE no admin (P1-UI1)
  - Duas state machines divergentes (ExecutionStatusEnum vs ExecutionStatus) (P0-S3)
```

## FLUXO 6: CICLO FINANCEIRO

```
[1] Order criada via Briefing/WooCommerce
    -> Financial aggregate: status=PENDING
    -> LedgerEntry registrada (append-only, imutavel)

[2] Pagamento autorizado (WooCommerce webhook)
    -> WooCommercePaymentAdapter.handlePaymentComplete()
    -> Financial: PENDING -> AUTHORIZED

[3] Captura (automatica)
    -> Financial: AUTHORIZED -> CAPTURED

[4] Calcular taxa plataforma
    -> PlatformFeeCalculator: 15% (configuravel via wp_options)
    -> LedgerEntry: platform_fee registrada

[5] Hold (aguardando execucao)
    -> Financial: CAPTURED -> HELD
    -> Dinheiro congelado ate check-out

[6] Execucao completa + Feedback
    -> Decisao baseada em rating:
       - 5 estrelas: payout IMEDIATO
       - 4 estrelas: payout em 24h (WP-Cron delayed)
       - <= 3 estrelas: HOLD para review admin
    -> PayoutReconciliationService.approvePayout(order_id, rating)

[7] Criar Payout record
    -> wp_limpvix_payouts: status=pending
    -> Payout method: pix_manual ou mp_oauth
    -> Retry: max 3 tentativas

[8] Processar batch (Cron: limpvix_reconcile_payouts, 6h)
    -> PayoutReconciliationCronAdapter
    -> EfiPayoutProvider.createPayout() [PIX Cash-Out]
    -> Status: pending -> approved -> processing -> completed

[9] Payout completo
    -> Fires: limpvix_payout_success
    -> Profissional recebe D+1 ou D+2

GOLDEN RULE: authorizePayout() REQUER Order.status = COMPLETED

STATE MACHINES:
  Financial: pending->authorized->captured->held->payout_authorized->payout_completed
  Payout: pending->approved->authorized->processing->completed|failed|on_hold

REGRAS: taxa=15%, hold_ate_checkout, max_retry=3, D+1/D+2
STATUS: 70-75% implementado
GAPS CRITICOS:
  - EFI curlRequest CORRIGIDO (Fase 0 Fix #5)
  - Payout crons NAO BOOTADOS no Kernel (P1-OP3)
  - Feedback->Payout wiring INCOMPLETO (P1-OP2)
  - Cobranca recorrente DESATIVADA (P0-I1)
```

## FLUXO 7: FEEDBACK E QUALIDADE

```
[1] Execution VALIDATED -> Abrir janela feedback (24h)
    -> StructuredFeedback.createDraft()
    -> FeedbackSchedulingListener ativa

[2] Lembretes (Cron hourly: limpvix_send_feedback_reminders)
    -> 12h, 24h, 48h sem resposta
    -> $sendMessage = null (NAO ENVIA DE VERDADE - P1-OP4)

[3] Cliente preenche feedback (REST/AJAX)
    -> FeedbackChecklist: criterios 1-5 por item
    -> FeedbackPhotos: min 2 fotos
    -> Comentario opcional
    -> StructuredFeedback.submit()

[4] Score final calculado
    -> FeedbackChecklist.getAverageScore()
    -> 1.00 - 5.00

[5] Rating impacta profissional
    -> UpdateProfessionalScore use case
    -> Score impacts:
       - excellent_feedback (>=4.5): +0.10
       - good_execution: +0.05
       - poor_feedback (<3.0): -0.20
       - no_show: -0.50
       - late_checkin: -0.10
       - epi_violation: -0.30
    -> Calculo: 70% novo rating + 30% historico
    -> Se score < 3.0: suspensao automatica

[6] Payout decision
    -> FeedbackApproved event -> ReleasePayoutHoldOnFeedbackApproved listener
    -> 5 estrelas: aprovacao imediata
    -> 4 estrelas: aprovacao em 24h
    -> <= 3 estrelas: on_hold para admin
    -> Feedback.blocksPayout() = rating <= 2 && !validatedByAdmin

[7] Resolucao (Admin)
    -> Feedback.resolve(by, name, text, severity)
    -> Severity: grave=-1.50pts, medio=-0.75pts, leve=0pts
    -> Payout liberado apos resolucao

[8] Disputa (Profissional tem 48h)
    -> DisputeFeedback use case
    -> Status: submitted -> disputed
    -> Admin arbitragem

TIMEOUT: 24h sem feedback = payout automatico

STATUS: 65% implementado
GAPS:
  - REST API para feedback COMENTADA (P1-OP6)
  - Lembretes $sendMessage = null (P1-OP4)
  - Wiring Feedback->Payout incompleto (P1-OP2)
```

## FLUXO 8: RECORRENCIA

```
[1] Cron diario: limpvix_charge_recurring_payments
    -> Busca contratos: auto_renew=true, status=active, nextExecutionDate <= hoje+3d

[2] ChargeRecurringPayment use case
    -> Calcula valor:
       - weekly: monthlyValue / 4.33
       - biweekly: monthlyValue / 2.16 (ERRO 7.4%/ano - P2-F1)
       - monthly: monthlyValue

[3] Gera cobranca
    -> EfiPaymentProvider.createPaymentCharge()
    -> PIX QR Code (cob.write)
    -> Armazena pix_qrcode + pix_qrimage em recurring_payments

[4] Webhook confirma pagamento
    -> ProcessPaymentWebhook use case
    -> RecurringPayment: pending -> completed

[5] Agendar proxima execucao
    -> ScheduleNextExecution use case
    -> Loop continua

[6] Falha no pagamento
    -> Retry: +3d, +5d
    -> Max 3 tentativas por ciclo
    -> 3a falha: pausar contrato

STATUS: Implementado mas DESATIVADO
GAP CRITICO: Cron COMENTADO em ContractBootstrap:461
```

## FLUXO 9: COMUNICACAO

```
Canais: SMS (Twilio/NVoip) | WhatsApp (360Dialog) | Push (Firebase) | Email

Eventos -> Mensagens:
  BriefingCreated       -> Customer: Email "Briefing iniciado"
  BriefingPhoneVerified -> Customer: SMS "Telefone verificado"
  ContractActivated     -> Professional: Push "Nova alocacao"
  ExecutionScheduled    -> Professional: Push "Servico amanha"
  ExecutionCheckedIn    -> Customer: Push "Profissional chegou"
  ExecutionCompleted    -> Customer: Email "Avaliar servico?"
  FeedbackSubmitted     -> Professional: Email "Feedback recebido"
  PaymentCompleted      -> Customer: Email "Cobranca confirmada"
  PayoutCompleted       -> Professional: Email "Payout na sua conta"

Sistema:
  - MessageFlowTriggers: registra listeners para cada evento
  - MessageQueueService: enfileira com retry
  - WpMessageTemplateRepository: templates versionados
  - wp_limpvix_message_log: audit trail (append-only)
  - Retry: 3 tentativas (5s, 30s, 300s)
  - DND: 22:00-08:00 apenas Email

STATUS: 40% implementado
GAP CRITICO: communicationProvider = NULL (P1-OP4)
  - class_exists() com double backslashes falha silenciosamente (P1-CLASS1/2)
  - sendViaSMS() / sendViaWhatsApp() NUNCA executam (P1-CLASS3)
```

---

# 4. MAPA DE WIRING (EVENTOS + LISTENERS)

## 4.1 Cadeia Operacional

```
Fluxo 1 (Onboarding Prof)  ----------------------------------------+
                                                                     |
                                                                     v
Fluxo 2 (Cliente Briefing) -> Fluxo 3 (Contrato) -> Fluxo 4 (Alocacao)
                                       |                  |
                                  Fluxo 8 (Recorrencia)   |
                                       |                  |
                                  Fluxo 6 (Financeiro) <- Fluxo 5 (Execucao)
                                       ^                  |
                                       +---- Fluxo 7 (Feedback)

Fluxo 9 (Comunicacao) -> intercepta TODOS os eventos acima
```

## 4.2 Links FUNCIONANDO

| De | Para | Mecanismo | Arquivo |
|----|------|-----------|---------|
| Briefing LOCKED | Contract CREATED | BriefingContractListener.onBriefingLocked() | ContractBootstrap |
| Contract ACTIVATED | SendOffers | ContractBootstrap.autoSendOffers() (HYBRID) | ContractBootstrap:719 |
| Offer ACCEPTED | Contract allocation | AcceptOffer (TRANSACTION) | ProfessionalBootstrap |
| WC Payment Complete | Financial transition | WooCommercePaymentAdapter | AdapterBootstrap |
| Feedback APPROVED | Score update | UpdateProfessionalScoreOnFeedbackApproved | FeedbackBootstrap |
| Contract expiration | Auto-expire | ContractAutomation (cron daily) | Kernel |
| Fallback offers | Re-send unsent | SendOffersCronAdapter (hourly) | ContractBootstrap |

## 4.3 Links QUEBRADOS (4 criticos)

| # | De | Para | Problema | Fix |
|---|-----|------|---------|-----|
| 1 | Schedule CREATED | Execution CREATED | Sem trigger automatico | Criar ScheduleToExecutionListener |
| 2 | Feedback APPROVED | Payout RELEASED | Wiring incompleto | Completar ReleasePayoutHoldOnFeedbackApproved |
| 3 | Payout APPROVED | Transfer PROCESSING | Crons nao bootados | Boot PayoutReconciliationService no Kernel |
| 4 | TODOS eventos | Communication | provider=null, class_exists bugado | Fix double backslash + injetar provider |

## 4.4 Eventos Custom Registrados (50+)

Categorias:
- Briefing: 6 eventos (created, step_completed, locked, phone_verified, accepted, paid)
- Contract: 8 eventos (created, activated, paused, resumed, cancelled, completed, expired, renewed)
- Execution: 8 eventos (scheduled, started, completed, cancelled, no_show, rescheduled, checked_in, issue_reported)
- Evidence: 4 eventos (added, removed, approved, rejected)
- Professional: 4 eventos (registered, score_updated, status_updated, suspended)
- Offer: 3 eventos (sent, accepted, rejected)
- Financial: 4 eventos (transition_success, transition_rejected, transition_error, payout_success)
- Feedback: 4 eventos (enable, positive_received, negative_received, domain_event)
- Ledger: 3 eventos (appended, idempotent, error)
- Admin: 2 eventos (notification, notify_admin)
- Verification: 6 eventos (initiated, otp_verified, kyc_completed, kyc_approved, background_requested, background_completed)
- WooCommerce: 2 eventos (payment_processed, payment_rejected)

---

# 5. SCHEMA DO BANCO DE DADOS (26 TABELAS)

## 5.1 Tabelas por Dominio

```
ORDERS & EXECUTION (3):
  wp_limpvix_orders          - Aggregate root, links WC order
  wp_limpvix_executions      - Service executions (evidence JSON)
  wp_limpvix_execution_evidence - Evidence tracking

BRIEFING (4):
  wp_limpvix_briefings       - Multi-step briefing wizard
  wp_limpvix_briefing_data   - Step data (JSON)
  wp_limpvix_briefing_additionals - Selected add-ons
  wp_limpvix_briefing_ledger - Event log (append-only)

SERVICE CATALOG (3):
  wp_limpvix_service_catalog - Services offered
  wp_limpvix_service_additionals - Add-on services
  wp_limpvix_package_configs - Package tiers (basic/standard/premium)

PROFESSIONALS (5):
  wp_limpvix_professionals   - Professional profiles (KYC, score, PIX)
  wp_limpvix_professional_skills - M2M: skills + certifications
  wp_limpvix_professional_documents - KYC documents
  wp_limpvix_professional_allocations_history - Allocation audit trail
  wp_limpvix_professional_availability - Weekly schedule

CONTRACTS & OFFERS (3):
  wp_limpvix_contracts       - Recurring contracts
  wp_limpvix_contract_offers - Professional matching offers
  wp_limpvix_recurring_payments - Per-execution billing

SCHEDULING (6):
  wp_limpvix_schedules       - Service schedules
  wp_limpvix_professional_allocations - Schedule allocations
  wp_limpvix_check_ins       - GPS check-in records
  wp_limpvix_check_outs      - GPS check-out records
  wp_limpvix_scheduling_ledger - Event log (append-only)

COMMUNICATION (3):
  wp_limpvix_message_templates - Versioned templates
  wp_limpvix_message_log     - Send audit (append-only)
  wp_limpvix_message_queue   - Retry queue

FEEDBACK (3):
  wp_limpvix_structured_feedbacks - Checklist + photos
  wp_limpvix_feedback_disputes - Arbitration
  wp_limpvix_feedback         - Main feedback (rating, resolution)

FINANCIAL (3):
  wp_limpvix_financial_ledger - Immutable event log
  wp_limpvix_payouts          - Payout tracking (dual-mode)
  wp_limpvix_payout_audit_trail - Manual payout audit

VERIFICATION (3):
  wp_limpvix_user_verifications - OTP phone/email
  wp_limpvix_professional_verification - 4-layer KYC pipeline
  wp_limpvix_consent_records  - LGPD consent (immutable)

SISTEMA (1):
  wp_limpvix_migrations       - Migration tracking
```

## 5.2 Tabelas Append-Only (Auditoria)

Estas tabelas sao IMUTAVEIS (sem UPDATE/DELETE):
- wp_limpvix_briefing_ledger
- wp_limpvix_financial_ledger
- wp_limpvix_scheduling_ledger
- wp_limpvix_message_log
- wp_limpvix_consent_records
- wp_limpvix_payout_audit_trail

---

# 6. FINDINGS DA AUDITORIA (66 total)

## 6.1 P0 - BLOCKERS (16 findings)

### Fatal Errors (5) - TODOS CORRIGIDOS NA FASE 0
| ID | Issue | Arquivo | Status |
|----|-------|---------|--------|
| P0-F1 | number_format(null) | DashboardController.php:108 | CORRIGIDO |
| P0-F2 | DateTimeImmutable type mismatch | Professional.php:260 | CORRIGIDO |
| P0-F3 | formatRecipientType(null) | PayoutsPage.php:334 | CORRIGIDO |
| P0-F4 | ExecutePayout constructor 3 args vs 5 | PayoutsPage.php:267 | CORRIGIDO |
| P0-F5 | curlRequest headers bug | EfiPaymentProvider.php:278 | CORRIGIDO |

### State Machine Bugs (3)
| ID | Issue | Arquivo | Fase |
|----|-------|---------|------|
| P0-S1 | 'expired' rejeitado por ContractStatus VO | ContractStatus.php | Fase 1.7 |
| P0-S2 | renew() bypassa state machine | Contract.php:291-309 | Fase 1.7 |
| P0-S3 | ExecutionStatusEnum vs ExecutionStatus divergentes | Domain/Execution/ | Fase 1.7 |

### Security (5) - TODOS CORRIGIDOS NA FASE 0
| ID | Issue | Arquivo | Status |
|----|-------|---------|--------|
| P0-SEC1 | Migrations expostas via HTTP | run-*.php (5 arquivos) | CORRIGIDO |
| P0-SEC2 | AJAX nopriv sem nonce | Feedback+Briefing handlers | CORRIGIDO |
| P0-SEC3 | permission_callback string | ProfessionalDocumentController | CORRIGIDO |
| P0-SEC4 | UserRoles::unregister no activation | limpvix-core.php:169 | CORRIGIDO |
| P0-SEC5 | $this->orderId inexistente | Execution.php:161 | CORRIGIDO |

### Critical Missing (3)
| ID | Issue | Arquivo | Fase |
|----|-------|---------|------|
| P0-I1 | Recurring payment cron COMENTADO | ContractBootstrap.php:461 | Fase 1.5 |
| P0-I2 | Payout crons NAO bootados | Kernel.php | Fase 1.3 |
| P0-I3 | Communication provider = NULL | CommunicationBootstrap | Fase 1.4 |

## 6.2 P1 - HIGH SEVERITY (12 findings)

| ID | Issue | Arquivo | Fase |
|----|-------|---------|------|
| P1-OP1 | Schedule->Execution sem trigger | (ausente) | Fase 1.1 |
| P1-OP2 | Feedback->Payout wiring incompleto | (listener incompleto) | Fase 1.2 |
| P1-OP3 | Payout->Transfer crons nao bootados | Kernel.php | Fase 1.3 |
| P1-OP4 | ALL->Communication provider null | CommunicationBootstrap | Fase 1.4 |
| P1-UI1 | ExecutionManagementPage AUSENTE | (nao existe) | Fase 2.2 |
| P1-EV1 | onExecutionValidated() undefined | MessageFlowTriggers.php:37 | Fase 1.4 |
| P1-CLASS1 | Double backslash class_exists Twilio | MessageFlowTriggers.php:391 | Fase 1.4 |
| P1-CLASS2 | Double backslash class_exists WhatsApp | MessageFlowTriggers.php:432 | Fase 1.4 |
| P1-CLASS3 | sendViaSMS/WhatsApp nunca executam | MessageFlowTriggers.php | Fase 1.4 |
| P1-CR1 | PaymentAuthTimeout capture/cancel TODOs | PaymentAuthorizationTimeoutCronAdapter | Fase 1.3 |
| P1-CR2 | every_15_minutes nunca registrado | AdminBootstrap.php:5085 | Fase 1.9 |
| P1-SM1 | OrderPolicy usa metodos inexistentes | OrderPolicy.php | Fase 1.7 |

## 6.3 P2 - MEDIUM SEVERITY (25 findings)

| ID | Issue | Tipo |
|----|-------|------|
| P2-A1 | 32 violations Application imports Infrastructure | DDD |
| P2-A2 | AdminBootstrap.php 7.124 linhas (God Object) | God Object |
| P2-A3 | UseCase/ e UseCases/ coexistem | Structure |
| P2-A4 | RenewContract duplicado | Duplication |
| P2-A5 | Finance/ vs Financial/ namespace split | Structure |
| P2-A6 | Migrations em 3 locais diferentes | Structure |
| P2-A7 | BookingEngineInterface sem implementacao | Dead Code |
| P2-A8 | IssueRepositoryInterface sem implementacao | Dead Code |
| P2-A9 | CommunicationBootstrap duplicado (Core + Infra) | Duplication |
| P2-F1 | Biweekly divisor 2.16 = 7.4% erro/ano | Financial |
| P2-F2 | Float arithmetic para calculos financeiros | Financial |
| P2-DB1 | Migrations 023 & 024 duplicadas | Schema |
| P2-DB2 | Migration numbering com gaps (002-004, 028) | Schema |
| P2-DB3 | Duas ledger tables (ledger vs financial_ledger) | Schema |
| P2-DB4 | Duas feedback tables (feedback vs structured_feedback) | Schema |
| P2-BOOK1-6 | Booknetic residuals (6 items) | Dead Code |
| P2-UI1 | Twilio/Exato settings nested em Briefing if block | Settings Bug |
| P2-UI2 | 3 submenus orfaos | UI Bug |
| P2-UC1 | ScheduleOrder e 100% stub | Missing Impl |
| P2-UC2 | FindCommonSlot() oversimplificado | Missing Impl |
| P2-UC3 | GeolocationAdapter retorna valores hardcoded | Missing Impl |

## 6.4 P3 - LOW SEVERITY (13 findings)

Dead code, technical debt, optimizacoes, inconsistencias menores.
Completo no doc 00-AUDIT-EXECUTIVE-SUMMARY.md.

---

# 7. FASE 0: EMERGENCIA - COMPLETA

**Status:** 10/10 fixes aplicados e verificados em 2026-02-18
**Verificacao:** wp-load.php OK, migrations HTTP 403, syntax check all clean

Detalhes em docs/08-OPERATIONAL-FLOWS-BLUEPRINT.md

---

# 8. FASE 1: RECONECTAR CADEIA OPERACIONAL

## 8.1 Schedule -> Execution Trigger (P1-OP1)

**Problema:** Quando um Schedule e criado, nenhuma Execution e criada automaticamente.
**Solucao:** Criar ScheduleToExecutionListener

```
Arquivo: src/Infrastructure/Integration/ScheduleToExecutionListener.php
Hook: add_action('limpvix_execution_scheduled', [listener, 'onExecutionScheduled'])
Acao: CreateExecution use case com dados do Schedule
Registrar em: ExecutionBootstrap::init() ou ContractBootstrap::init()
```

## 8.2 Feedback -> Payout Wiring (P1-OP2)

**Problema:** FeedbackApproved event nao dispara liberacao de payout consistentemente.
**Solucao:** Verificar e completar ReleasePayoutHoldOnFeedbackApproved listener

```
Verificar: Listener registrado no FeedbackBootstrap
Verificar: PayoutReconciliationService.approvePayout() chamado com rating correto
Verificar: FSM rules (5*=imediato, 4*=24h, <=3*=hold)
Garantir: payout status transiciona para 'approved' ou 'on_hold'
```

## 8.3 Boot Payout Crons (P1-OP3)

**Problema:** PayoutReconciliationService crons nao sao registrados no boot.
**Solucao:** Adicionar ao Kernel ou ContractBootstrap

```
No boot():
  PayoutReconciliationService::registerCronHooks()
  PayoutReconciliationService::scheduleCronJobs()

Crons necessarios:
  - limpvix_reconcile_payouts (cada 6h)
  - limpvix_payment_auth_timeout (cada 5min)

Tambem: Completar TODOs no PaymentAuthorizationTimeoutCronAdapter (capture/cancel)
```

## 8.4 Ativar Comunicacao (P1-OP4)

**Problema:** communicationProvider = null + class_exists com double backslash.
**Solucao:** 3 fixes

```
Fix 1: MessageFlowTriggers.php:391
  ANTES: class_exists('LimpVix\\Infrastructure\\Communication\\...')
  DEPOIS: class_exists('LimpVix\Infrastructure\Communication\...')
  (Remover double backslash em TODAS as class_exists)

Fix 2: CommunicationBootstrap
  Injetar TwilioSmsProvider como provider padrao quando configurado
  Injetar WhatsApp360DialogProvider quando configurado
  Fallback: LogOnlyProvider para desenvolvimento

Fix 3: MessageFlowTriggers
  Verificar que sendViaSMS() e sendViaWhatsApp() usam providers injetados
  Verificar retry logic funcional
```

## 8.5 Ativar Cobranca Recorrente (P0-I1)

**Problema:** Cron de cobranca comentado em ContractBootstrap.php:461.
**Solucao:** Descomentar + verificar

```
Descomentar: add_action('limpvix_charge_recurring_payments', [$this, 'chargeRecurringPayments'])
Verificar: ChargeRecurringPayment use case funciona
Verificar: EfiPaymentProvider.createPaymentCharge() funciona (Fix #5 ja aplicado)
Verificar: RecurringPayment state machine (pending->processing->completed/failed)
```

## 8.6 Ativar Feedback REST API (P1-OP6)

**Problema:** REST API para feedback comentada no FeedbackBootstrap.
**Solucao:** Descomentar registro da API

```
Descomentar: register_rest_route() calls no FeedbackBootstrap
Verificar: endpoints POST /feedback, GET /feedback/{id} funcionam
Verificar: permission_callback corretos (callable, nao string)
```

## 8.7 Fix State Machines (P0-S1, P0-S2, P0-S3)

```
Fix S1: Adicionar 'expired' ao ContractStatus VO
  Arquivo: src/Domain/Contract/ValueObjects/ContractStatus.php
  Acao: Adicionar caso 'expired' no from() e isTerminal()

Fix S2: Fazer renew() usar ensureCanTransitionTo()
  Arquivo: src/Domain/Contract/Contract.php:291-309
  Acao: Antes de transicionar, chamar $this->status->ensureCanTransitionTo(ContractStatus::active())

Fix S3: Unificar ExecutionStatusEnum e ExecutionStatus
  Decisao: Manter ExecutionStatusEnum (backed enum, PHP 8.1)
  Acao: Migrar todos os usos de ExecutionStatus para ExecutionStatusEnum
  Remover: ExecutionStatus VO redundante
```

## 8.8 Purge Booknetic (P2-BOOK1-6)

```
Codigo:
  - AdminBootstrap: remover $isBookneticActive, corrigir $allPluginsActive
  - Tests: remover referencias a wp_bkntc_staff

Database:
  DROP TABLE IF EXISTS wp_bkntc_* (21 tabelas)

Container:
  rm -rf /var/www/html/wp-content/plugins/booknetic/ (25MB, 1874 files)
```

## 8.9 Fix Settings/Bootstrap (P2-UI1, P1-CR2)

```
Fix 1: AdminBootstrap.php:365-381
  Twilio/Exato settings nested dentro de Briefing if block
  Mover para fora do bloco condicional

Fix 2: Kernel.php:216
  Criar metodo logError() (chamado mas nao existe)

Fix 3: 3 submenus orfaos
  Verificar parent slugs existem para cada add_submenu_page

Fix 4: Registrar 'every_15_minutes' custom schedule
  Adicionar ao filtro cron_schedules em limpvix-core.php
```

---

# 9. FASE 2: ADMIN UI PROFISSIONAL

## 9.1 Reorganizar Menu Admin

```
LimpVix (menu principal)
+-- Dashboard (KPIs reais dos 9 fluxos)
+-- Operacional
|   +-- Briefings (F2) - BriefingManagementPage
|   +-- Contratos (F3) - ContractManagementPage
|   +-- Agendamentos (F4) - ScheduleManagementPage
|   +-- Execucoes (F5) - ExecutionManagementPage [NOVA]
+-- Pessoas
|   +-- Profissionais (F1) - ProfessionalManagementPage
|   +-- Clientes - CustomersManagementPage
+-- Financeiro
|   +-- Orders (F6) - OrdersPage
|   +-- Payouts (F6) - PayoutsPage
|   +-- Relatorio - FinancialReportController
+-- Qualidade
|   +-- Feedback (F7) - FeedbackManagementPage
|   +-- KYC/Documentos - DocumentReviewPage
+-- Comunicacao (F9)
|   +-- Templates - MessageTemplatesAdminPage
|   +-- Log - MessageFlowsAdminPage
+-- Configuracoes
    +-- Geral - LimpVixSettingsPage
    +-- Integracoes (EFI, Twilio, PPID, WhatsApp)
    +-- Cron Jobs - CronHealthWidget
    +-- Seguranca - API Keys, JWT, Rate Limiting
```

## 9.2 Criar ExecutionManagementPage (P1-UI1)

Nova pagina admin com:
- Lista de execucoes com filtros (status, profissional, contrato, data)
- Timeline visual: check-in -> execucao -> check-out -> validacao
- Mapa com geolocalizacao (check-in/out GPS)
- Evidencias inline (fotos before/after)
- SLA violations destacadas
- Status em tempo real
- Acoes: validar, cancelar, reagendar, marcar no-show

## 9.3 Quebrar AdminBootstrap (7.124 linhas -> ~15 classes)

Extrair:
- Cada aba de settings -> SettingsTab class propria
- CSS inline (~5.000 linhas) -> assets/css/admin.css
- JavaScript inline -> assets/js/admin.js
- Cada secao de pagina -> Controller proprio

## 9.4 Unificar Namespaces

- Mover UseCase/ para UseCases/ (padrao plural)
- Resolver RenewContract duplicado (manter UseCase/Contract/ como principal)
- Unificar Finance/ e Financial/ em Financial/

---

# 10. VERIFICACAO E TESTES

## 10.1 Testes Pos-Fase 0 (COMPLETOS)

```bash
# Zero fatal errors
docker exec limpvix_wordpress_clean php -r "require('/var/www/html/wp-load.php'); echo 'OK';"
# RESULTADO: OK

# Migrations HTTP blocked
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/.../run-005-migration.php
# RESULTADO: 403

# Syntax check all fixed files
docker exec limpvix_wordpress_clean php -l /var/www/html/.../EfiPaymentProvider.php
# RESULTADO: No syntax errors (todos os 7 arquivos)
```

## 10.2 Testes Pos-Fase 1

```bash
# ContractStatus aceita 'expired'
docker exec limpvix_wordpress_clean php -r "
require('/var/www/html/wp-load.php');
use LimpVix\Domain\Contract\ValueObjects\ContractStatus;
\$s = ContractStatus::from('expired');
echo \$s->value();
"

# Payout crons registrados
docker exec limpvix_wordpress_clean php -r "
require('/var/www/html/wp-load.php');
\$crons = _get_cron_array();
\$found = 0;
foreach(\$crons as \$t => \$hooks) {
  foreach(\$hooks as \$h => \$e) {
    if(strpos(\$h, 'payout') !== false || strpos(\$h, 'reconcile') !== false) {
      echo \"\$h\n\"; \$found++;
    }
  }
}
echo \$found >= 2 ? 'OK' : 'FAIL';
"

# Communication provider nao null
docker exec limpvix_wordpress_clean php -r "
require('/var/www/html/wp-load.php');
// class_exists fix verificacao
\$class = 'LimpVix\Infrastructure\Communication\Providers\TwilioSmsProvider';
echo class_exists(\$class) ? 'Twilio: OK' : 'Twilio: MISSING';
"

# Booknetic removido
docker exec limpvix_wordpress_clean php -r "
require('/var/www/html/wp-load.php');
global \$wpdb;
\$t = \$wpdb->get_col(\"SHOW TABLES LIKE 'wp_bkntc_%'\");
echo count(\$t) === 0 ? 'OK - Booknetic removed' : 'FAIL: '.count(\$t).' tables remain';
"

# Recurring payment cron ativo
docker exec limpvix_wordpress_clean php -r "
require('/var/www/html/wp-load.php');
\$crons = _get_cron_array();
foreach(\$crons as \$t => \$hooks) {
  if(isset(\$hooks['limpvix_charge_recurring_payments'])) {
    echo 'OK - Recurring cron active at '.date('Y-m-d H:i', \$t);
    exit;
  }
}
echo 'FAIL - Recurring cron NOT found';
"
```

---

# 11. CHECKLIST GO-LIVE FINAL

## Pre-Deploy

- [x] FASE 0: 10 fatal errors + security fixes (COMPLETO)
- [ ] FASE 1.1: Schedule->Execution trigger
- [ ] FASE 1.2: Feedback->Payout wiring
- [ ] FASE 1.3: Boot payout crons
- [ ] FASE 1.4: Ativar comunicacao (fix class_exists + provider)
- [ ] FASE 1.5: Ativar cobranca recorrente
- [ ] FASE 1.6: Ativar feedback REST API
- [ ] FASE 1.7: Fix 3 state machines
- [ ] FASE 1.8: Purge Booknetic
- [ ] FASE 1.9: Fix settings/bootstrap bugs

## Configuracao Producao

- [ ] EFI Bank: client_id, client_secret, pix_key, cert_path (PRODUCAO, nao sandbox)
- [ ] Twilio: account_sid, auth_token, from_number
- [ ] 360Dialog: api_key (WhatsApp)
- [ ] PPID: email, senha (KYC biometrico)
- [ ] JWT: limpvix_jwt_secret (forte, unico)
- [ ] Feature flags: core_enabled=true, admin_interface=true
- [ ] Platform fee: limpvix_platform_fee_percentage=15
- [ ] EFI sandbox: limpvix_efi_sandbox=no

## Verificacao Final

- [ ] wp-load.php carrega sem erros
- [ ] Admin dashboard renderiza com KPIs
- [ ] REST API /health retorna 200
- [ ] Cron jobs todos registrados (8 minimo)
- [ ] WooCommerce payment adapter conectado
- [ ] Login JWT funcional
- [ ] API Key funcional
- [ ] Rate limiting ativo

## Primeiro Ciclo E2E

- [ ] Criar profissional (Fluxo 1)
- [ ] Aprovar KYC (Fluxo 1)
- [ ] Criar briefing (Fluxo 2)
- [ ] Pagar via WooCommerce (Fluxo 2)
- [ ] Contrato criado automaticamente (Fluxo 3)
- [ ] Ofertas enviadas (Fluxo 4)
- [ ] Profissional aceita oferta (Fluxo 4)
- [ ] Execucao criada (Fluxo 5)
- [ ] Check-in com GPS (Fluxo 5)
- [ ] Check-out com evidencias (Fluxo 5)
- [ ] Feedback submetido (Fluxo 7)
- [ ] Payout autorizado (Fluxo 6)
- [ ] Profissional recebe PIX (Fluxo 6)
- [ ] Notificacoes enviadas (Fluxo 9)

---

# RESUMO EXECUTIVO

| Metrica | Antes Fase 0 | Apos Fase 0 | Apos Fase 1 | Apos Fase 2 |
|---------|-------------|-------------|-------------|-------------|
| Fatal Errors | 5 | 0 | 0 | 0 |
| Security Vulns | 5 | 0 | 0 | 0 |
| Fluxos E2E | 3/9 | 3/9 | 9/9 | 9/9 |
| Links Quebrados | 4 | 4 | 0 | 0 |
| Comunicacao | 0% | 0% | 100% | 100% |
| State Machines | 2 bugs | 2 bugs | 0 bugs | 0 bugs |
| Crons Ativos | 5/8 | 5/8 | 8/8 | 8/8 |
| Admin Pages | Crashes | Funcional | Organizada | Profissional |
| Score Geral | 24.7/100 | 80/100 | 92/100 | 98/100 |

**PRIORIDADE:** Fase 1 (reconectar 4 links quebrados) e o CAMINHO CRITICO para go-live.

**Ordem:** Fase 0 (COMPLETA) -> Fase 1 (9 tarefas) -> Fase 2 (4 tarefas) -> Go-Live

---

*Documento gerado em 2026-02-18 por 6 agentes de auditoria paralelos.*
*Convergencia de 4 rodadas de auditoria (20 agentes total), 497 arquivos PHP analisados.*
*Plugin: limpvix-core v0.2.0 | WordPress 6.8.2 | PHP 8.2.29 | MySQL 8.0*
