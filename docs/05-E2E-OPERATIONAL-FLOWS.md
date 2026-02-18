# 05 - MAPEAMENTO END-TO-END DE FLUXOS OPERACIONAIS

**Data:** 2026-02-18
**Agente:** 2/4 - Mapeamento E2E
**Plugin:** limpvix-core (497 arquivos PHP no src/)
**Container:** limpvix_wordpress_clean (WordPress 6.8.2, PHP 8.2.29, MySQL 8.0)

---

## MACRO-FLUXO ASCII

```
 CLIENTE                      ADMIN                     PROFISSIONAL
    |                           |                           |
    |  1. Acessa briefing       |                           |
    |  (REST API /briefing)     |                           |
    v                           |                           |
+----------+                    |                           |
| BRIEFING |---[steps]--------->|                           |
| draft -> |   property_type    |                           |
| in_prog->|   address, m2     |                           |
| complete |   frequency        |                           |
+----+-----+                    |                           |
     |                          |                           |
     | verify-phone (OTP)       |                           |
     | select-package           |                           |
     v                          |                           |
+----------+                    |                           |
| PAGAMENTO|  WooCommerce       |                           |
| (PIX/CC) |  checkout          |                           |
+----+-----+                    |                           |
     |                          |                           |
     | woocommerce_payment_complete                         |
     v                          |                           |
+----------+                    |                           |
| BRIEFING |  BriefingPayment   |                           |
| LOCKED   |  Adapter           |                           |
+----+-----+                    |                           |
     |                          |                           |
     | limpvix_briefing_locked (event)                      |
     v                          |                           |
+----------+  BriefingContract  |                           |
| CONTRACT |  Listener          |                           |
| draft    |  (auto-create)     |                           |
+----+-----+                    |                           |
     |                          |                           |
     | limpvix_contract_created |                           |
     v                          |                           |
+----------+  SubmitForAlloc    |                           |
| CONTRACT |  ContractControl.  |                           |
| pending_ |                    |                           |
| allocation                    |                           |
+----+-----+                    |                           |
     |                          |                           |
     | activate (admin)         |                           |
     v                          |                           |
+----------+  autoSendOffers()  |                           |
| CONTRACT |  SendOffers UC     |         +----------+      |
| active   |--[offers]----------|-------->| OFFER    |----->|
+----+-----+  ProximityScorer   |         | pending  |      |
     |        AllocationEngine  |         +----+-----+      |
     |                          |              |            |
     |                          |              | accept/    |
     |                          |              | reject     |
     |                          |              v            |
     |                          |         +----------+      |
     |                          |         | OFFER    |      |
     |                          |         | accepted |      |
     |                          |         +----+-----+      |
     |                          |              |            |
     v                          |              v            |
+----------+  ScheduleExec     |                           |
| EXECUTION|  CreateExecution   |                           |
| scheduled|                    |                           |
+----+-----+                    |                           |
     |                          |                           |
     | dia do servico           |                           |
     v                          |                           |
+----------+  PerformCheckIn    |                    +------+------+
| EXECUTION|  (geo + EPI)       |                    | CHECK-IN    |
| in_prog  |<----------------------------------------| lat/lng/ts  |
+----+-----+                    |                    +------+------+
     |                          |                           |
     | servico executado        |                           |
     v                          |                           |
+----------+  PerformCheckOut   |                    +------+------+
| EXECUTION|  CompleteExec      |                    | CHECK-OUT   |
| completed|<----------------------------------------| evidencias  |
+----+-----+                    |                    +-------------+
     |                          |                           |
     | ValidateExecution        |                           |
     v                          |                           |
+----------+  CompleteService   |                           |
| EXECUTION|  WithPayout        |                           |
| validated|                    |                           |
+----+-----+                    |                           |
     |                          |                           |
     | feedback window 24h      |                           |
     v                          |                           |
+----------+  SubmitFeedback    |                           |
| FEEDBACK |  (structured)      |                           |
| submitted|                    |                           |
+----+-----+                    |                           |
     |                          |                           |
     | PayoutReconciliation     |                           |
     v                          |                           |
+----------+  approvePayout()   |                           |
| PAYOUT   |  5*=immediate      |                           |
| pending->|  4*=24h delay      |                           |
| approved |  <=3*=on_hold     |                           |
+----+-----+                    |                           |
     |                          |                           |
     | MercadoPago transfer     |                           |
     v                          |                           |
+----------+                    |                    +------+------+
| PAYOUT   |                    |                    | RECEBE PIX  |
| completed|-----------------------------------------| via MP      |
+----------+                    |                    +-------------+
```

---

## TABELA DE MIGRACAO SQL (31 migracao)

| # | Arquivo | Tabela(s) Principal(is) |
|---|---------|------------------------|
| 000 | create_migrations_table | controle interno |
| 001 | create_orders_table | wp_limpvix_orders |
| 005 | create_executions_table | wp_limpvix_executions |
| 006 | create_briefings_tables | wp_limpvix_briefings, wp_limpvix_briefing_snapshots |
| 007 | add_briefing_packages | wp_limpvix_package_configs |
| 008 | add_briefing_complexity | alter wp_limpvix_briefings |
| 009 | create_service_catalog | wp_limpvix_service_catalog |
| 010 | create_contracts_tables | wp_limpvix_contracts |
| 011 | create_communication | wp_limpvix_message_templates, wp_limpvix_message_log, wp_limpvix_message_queue |
| 012 | create_professionals | wp_limpvix_professionals |
| 013 | create_scheduling | wp_limpvix_schedules, wp_limpvix_professional_allocations, wp_limpvix_professional_availability, wp_limpvix_check_ins, wp_limpvix_check_outs, wp_limpvix_scheduling_ledger |
| 014 | structured_feedback | wp_limpvix_structured_feedbacks, wp_limpvix_feedback_disputes |
| 015 | financial_ledger | wp_limpvix_financial_ledger |
| 016 | professional_fk | foreign key constraints |
| 017 | feedback_window | alter wp_limpvix_executions (feedback_window) |
| 018 | recurring_payments | wp_limpvix_recurring_payments |
| 019 | professional_skills | wp_limpvix_professional_skills |
| 020 | kyc_fields | alter professionals (kyc) |
| 021 | contract_offers | wp_limpvix_contract_offers |
| 022 | evidence_validation | alter executions (evidence fields) |
| 023 | professional_status | alter professionals (status column) |
| 023 | professional_documents | wp_limpvix_professional_documents |
| 024 | manual_payout | alter payouts (manual fields) |
| 024 | user_verifications | wp_limpvix_user_verifications |
| 025 | service_catalog_skills | alter service_catalog (required_skills) |
| 026 | professional_verification | wp_limpvix_professional_verification |
| 027 | payout_dual_mode | alter payouts (EFI Bank dual mode) |
| 029 | recurring_payment_exec | alter recurring_payments (execution fields) |
| 030 | feedback_resolution | alter feedbacks (resolution fields) |
| 031 | payout_authorized | alter payouts (authorized status) |

---

## FLUXO 1: BRIEFING (Cliente solicita orcamento)

### Etapas End-to-End

| Etapa | Trigger | Arquivo | Metodo | Input | Output | Tabela BD | Evento | Proxima Etapa | Status | Gap |
|-------|---------|---------|--------|-------|--------|-----------|--------|---------------|--------|-----|
| 1.1 Obter Schema | GET /limpvix/v1/briefing/schema | src/Infrastructure/API/BriefingSchemaController.php | BriefingSchemaController::get() | property_type=residential\|commercial | JSON schema dos steps | nenhuma | nenhum | 1.2 Criar Briefing | IMPLEMENTADO | - |
| 1.2 Criar Briefing | POST /limpvix/v1/briefing | src/Infrastructure/API/BriefingController.php | BriefingController::create() | property_type, user_id(opt), JWT | Briefing UUID, status=draft | wp_limpvix_briefings | limpvix_briefing_created | 1.3 Update Steps | IMPLEMENTADO | - |
| 1.3 Atualizar Steps | POST /limpvix/v1/briefing/{uuid}/step | src/Infrastructure/API/BriefingStepController.php | BriefingStepController::update() | uuid, step_name, step_data | Updated briefing | wp_limpvix_briefings | limpvix_briefing_step_completed | 1.4 ou 1.3 (loop) | IMPLEMENTADO | - |
| 1.4 Verificar Telefone | POST /limpvix/v1/briefing/{uuid}/verify-phone | src/Infrastructure/API/BriefingPhoneController.php | BriefingPhoneController::verify() | uuid, id_token (Firebase JWT) | phone_verified=true | wp_limpvix_briefings | limpvix_briefing_phone_verified | 1.5 Selecionar Pacote | IMPLEMENTADO | Depende de Firebase config |
| 1.5 Selecionar Pacote | POST /limpvix/v1/briefing/{uuid}/package | src/Infrastructure/API/PackageController.php | PackageController::select() | uuid, package_slug | briefing atualizado | wp_limpvix_briefings, wp_limpvix_package_configs | nenhum | 1.6 Pagamento | IMPLEMENTADO | - |
| 1.6 Calcular Metricas | Interno (durante steps) | src/Application/Services/BriefingMetricsCalculator.php | BriefingMetricsCalculator::calculate() | briefing data (m2, rooms, etc) | estimated_hours, professional_count | nenhuma (in-memory) | nenhum | - | IMPLEMENTADO | - |
| 1.7 Avaliar Complexidade | Interno | src/Application/UseCases/Briefing/AssessComplexity.php | AssessComplexity::execute() | briefing_uuid | complexity_level | wp_limpvix_briefings | nenhum | - | IMPLEMENTADO | - |
| 1.8 Pagamento WooCommerce | woocommerce_payment_complete | src/Infrastructure/Adapters/BriefingPaymentAdapter.php | BriefingPaymentAdapter::onPaymentComplete() | orderId (WC) | briefing PAID -> LOCKED | wp_limpvix_briefings | limpvix_briefing_paid, limpvix_briefing_locked | 1.9 Criar Contrato | IMPLEMENTADO | - |
| 1.9 Gerar Contrato | limpvix_briefing_locked (evento) | src/Infrastructure/Integration/BriefingContractListener.php | BriefingContractListener::onBriefingLocked() | briefingId, briefingData | contract_id | wp_limpvix_contracts | limpvix_contract_created | Fluxo 3 | IMPLEMENTADO | Apenas para briefings recorrentes |
| 1.10 Notificar Admin | Interno (pos-contrato) | src/Infrastructure/Integration/BriefingContractListener.php | BriefingContractListener::notifyAdminContractCreated() | briefingId, contractId | email enviado | nenhuma | limpvix_admin_notification | - | IMPLEMENTADO | - |

**Score de Completude: 85%**

**Gaps Identificados:**
- Gap B1: Briefing nao-recorrente (avulso) nao gera contrato automaticamente; fluxo avulso nao mapeado
- Gap B2: Frontend do briefing (formulario React) nao esta no plugin; depende de app externo
- Gap B3: Firebase Auth adapter pode nao estar configurado (depende de credentials)

---

## FLUXO 2: ORDER (Pedido criado)

### Etapas End-to-End

| Etapa | Trigger | Arquivo | Metodo | Input | Output | Tabela BD | Evento | Proxima Etapa | Status | Gap |
|-------|---------|---------|--------|-------|--------|-----------|--------|---------------|--------|-----|
| 2.1 Criar Order | Hook intercept_booking (FeatureFlag) | src/Core/Hooks.php | Hooks::onAppointmentCreated() | appointmentData | Order in-memory | nenhuma | nenhum | 2.2 Persistir | IMPLEMENTADO | Depende de booking plugin externo |
| 2.2 Persistir Order | persist_orders flag=true | src/Application/UseCases/PersistOrder.php | PersistOrder::execute() | Order domain obj | order_uuid | wp_limpvix_orders | nenhum | 2.3 | IMPLEMENTADO | Feature flag off por default |
| 2.3 Criar Order (UseCase) | Interno | src/Application/UseCases/Order/CreateOrder.php | CreateOrder::execute() | uuid, id, totalAmount, feePercentage | Order + Financial aggregates | wp_limpvix_orders | nenhum | 2.4 | IMPLEMENTADO | Pure domain, sem persistencia propria |
| 2.4 Autorizar Pagamento | POST endpoint ou interno | src/Application/UseCases/Order/AuthorizePayment.php | AuthorizePayment::execute() | order, payment_data | payment authorized | wp_limpvix_orders | nenhum | 2.5 | IMPLEMENTADO | - |
| 2.5 Capturar Pagamento | Webhook | src/Application/UseCases/Order/CapturePayment.php | CapturePayment::execute() | order, capture_data | payment captured | wp_limpvix_orders, wp_limpvix_financial_ledger | nenhum | 2.6 | IMPLEMENTADO | - |
| 2.6 Agendar Order | Interno | src/Application/UseCases/ScheduleOrder.php | ScheduleOrder::execute() | order_uuid | schedule created | wp_limpvix_schedules | nenhum | Fluxo 4 | IMPLEMENTADO | - |

**Score de Completude: 55%**

**Gaps Identificados:**
- Gap O1: Order e criado de 2 formas desconectadas: (a) via interceptacao de booking plugin, (b) via UseCase puro. Nao ha ponte clara entre Briefing->Order
- Gap O2: Status flow da Order (CREATED -> IN_EXECUTION -> COMPLETED) esta no dominio mas nao ha controller REST dedicado para transicoes
- Gap O3: Feature flags `intercept_booking` e `persist_orders` estao OFF por default -- Order via booking nao funciona sem ativar
- Gap O4: WooCommerce integration e via Order do WC (produto do briefing), nao via Order do LimpVix. Dois conceitos de "Order" coexistem

---

## FLUXO 3: CONTRACT (Contrato gerado)

### Etapas End-to-End

| Etapa | Trigger | Arquivo | Metodo | Input | Output | Tabela BD | Evento | Proxima Etapa | Status | Gap |
|-------|---------|---------|--------|-------|--------|-----------|--------|---------------|--------|-----|
| 3.1 Criar Contrato | limpvix_briefing_locked / Manual | src/Application/UseCase/Contract/CreateContract.php | CreateContract::execute() | CreateContractRequest DTO | contract_id, contract_number | wp_limpvix_contracts | limpvix_contract_created | 3.2 | IMPLEMENTADO | - |
| 3.2 Criar de Briefing | Evento limpvix_briefing_locked | src/Application/UseCases/Contract/CreateContractFromBriefing.php | CreateContractFromBriefing::execute() | briefingData | contract_id | wp_limpvix_contracts | limpvix_contract_created | 3.3 | IMPLEMENTADO | Apenas briefings recorrentes |
| 3.3 Submit For Allocation | REST PUT /contracts/{id}/submit | src/Application/UseCase/Contract/SubmitForAllocation.php | SubmitForAllocation::execute() | contract_id | status=pending_allocation | wp_limpvix_contracts | nenhum | 3.4 | IMPLEMENTADO | - |
| 3.4 Ativar Contrato | REST PUT /contracts/{id}/activate | src/Application/UseCase/Contract/ActivateContract.php | ActivateContract::execute() | ActivateContractRequest | status=active | wp_limpvix_contracts | limpvix_contract_activated | 3.5 | IMPLEMENTADO | - |
| 3.5 Auto-Enviar Offers | limpvix_contract_activated (sem professional) | src/Core/ContractBootstrap.php | ContractBootstrap::autoSendOffers() | contractId | offers created | wp_limpvix_contract_offers | nenhum | Fluxo 4 | IMPLEMENTADO | - |
| 3.6 Pausar Contrato | REST PUT /contracts/{id}/pause | src/Application/UseCase/Contract/PauseContract.php | PauseContract::execute() | PauseContractRequest | status=paused | wp_limpvix_contracts | limpvix_contract_paused | 3.7 | IMPLEMENTADO | - |
| 3.7 Retomar Contrato | REST PUT /contracts/{id}/resume | src/Application/UseCase/Contract/ResumeContract.php | ResumeContract::execute() | contract_id | status=active | wp_limpvix_contracts | limpvix_contract_resumed | - | IMPLEMENTADO | - |
| 3.8 Cancelar Contrato | REST PUT /contracts/{id}/cancel | src/Application/UseCase/Contract/CancelContract.php | CancelContract::execute() | CancelContractRequest | status=cancelled | wp_limpvix_contracts | limpvix_contract_cancelled | - | IMPLEMENTADO | - |
| 3.9 Completar Contrato | REST PUT /contracts/{id}/complete | src/Application/UseCase/Contract/CompleteContract.php | CompleteContract::execute() | contract_id | status=completed | wp_limpvix_contracts | limpvix_contract_completed | - | IMPLEMENTADO | - |
| 3.10 Expirar Contrato | Cron diario | src/Application/UseCase/Contract/ExpireContract.php | ExpireContract::execute() | (batch) | N expired | wp_limpvix_contracts | limpvix_contract_expired | 3.11? | IMPLEMENTADO | - |
| 3.11 Renovar Contrato | limpvix_contract_expired (auto_renew) | src/Application/UseCase/Contract/RenewContract.php | RenewContract::execute() | contract_id | new contract_id | wp_limpvix_contracts | limpvix_contract_renewed | - | IMPLEMENTADO | Auto-renew via payment desativado |
| 3.12 Realocar Professional | REST ou interno | src/Application/UseCase/Contract/ReallocateProfessional.php | ReallocateProfessional::execute() | contract_id, new_prof_id, reason | reallocated | wp_limpvix_contracts | ProfessionalReallocated | - | IMPLEMENTADO | - |
| 3.13 Gerar Numero Contrato | Interno (durante create) | src/Application/Services/ContractNumberGenerator.php | ContractNumberGenerator::generate() | none | LVX-2026-XXXX | nenhuma | nenhum | - | IMPLEMENTADO | - |

**Status Machine do Contract:**
```
draft -> pending_allocation -> active -> paused -> active (resume)
                                    -> completed
                                    -> cancelled
                                    -> expired -> renewed (new contract)
```

**Score de Completude: 90%**

**Gaps Identificados:**
- Gap C1: Recurring payment cron DESATIVADO (comentado no ContractBootstrap). Modelo on-demand por visita
- Gap C2: Auto-renew trigger (ContractRenewedListener) existe mas depende de payment confirmado que nao ocorre automaticamente

---

## FLUXO 4: SCHEDULING/ALLOCATION (Alocacao de profissional)

### Etapas End-to-End

| Etapa | Trigger | Arquivo | Metodo | Input | Output | Tabela BD | Evento | Proxima Etapa | Status | Gap |
|-------|---------|---------|--------|-------|--------|-----------|--------|---------------|--------|-----|
| 4.1 Enviar Offers | Contract activated (auto) ou manual | src/Application/UseCase/Briefing/SendOffers.php | SendOffers::execute() | contractId | N offers sent | wp_limpvix_contract_offers | nenhum | 4.2 | IMPLEMENTADO | - |
| 4.2 Listar Offers (Prof) | GET /limpvix/v1/offers | src/Infrastructure/API/OfferController.php | OfferController::list() | JWT (professional) | offers array | wp_limpvix_contract_offers | nenhum | 4.3 | IMPLEMENTADO | - |
| 4.3 Aceitar Offer | POST /limpvix/v1/offers/{id}/accept | src/Application/UseCase/Professional/AcceptOffer.php | AcceptOffer::execute() | AcceptOfferRequest | offer accepted | wp_limpvix_contract_offers, wp_limpvix_contracts | nenhum | 4.5 | IMPLEMENTADO | - |
| 4.4 Rejeitar Offer | POST /limpvix/v1/offers/{id}/reject | src/Application/UseCase/Professional/RejectOffer.php | RejectOffer::execute() | RejectOfferRequest | offer rejected | wp_limpvix_contract_offers | nenhum | - | IMPLEMENTADO | - |
| 4.5 Alocar Professional | Interno (pos-aceite) | src/Application/UseCases/Scheduling/AllocateProfessional.php | AllocateProfessional::execute() | contract_id, professional_id | allocation created | wp_limpvix_professional_allocations | nenhum | 4.6 | IMPLEMENTADO | - |
| 4.6 Criar Schedule | Pos-alocacao | src/Application/UseCases/Scheduling/CreateSchedule.php | CreateSchedule::execute() | contract_id, dates | schedule_id | wp_limpvix_schedules | nenhum | Fluxo 5 | IMPLEMENTADO | - |
| 4.7 Encontrar Slots | GET endpoint | src/Application/UseCases/Scheduling/FindAvailableSlots.php | FindAvailableSlots::execute() | date, service_type | available slots | wp_limpvix_professional_availability | nenhum | - | IMPLEMENTADO | - |
| 4.8 Atualizar Disponib. | PUT endpoint | src/Application/UseCases/Scheduling/UpdateProfessionalAvailability.php | UpdateProfessionalAvailability::execute() | professional_id, schedule | updated | wp_limpvix_professional_availability | nenhum | - | IMPLEMENTADO | - |
| 4.9 AllocationEngine | Interno (scoring) | src/Application/Services/Scheduling/AllocationEngine.php | AllocationEngine::findBestProfessionals() | location, complexity, window, count | scored professionals | nenhuma (in-memory) | nenhum | - | IMPLEMENTADO | Core scoring engine |
| 4.10 ProximityScorer | Interno (sub-scoring) | src/Application/Services/Scheduling/ProximityScorer.php | ProximityScorer::calculateScore() | professional, location | 0-40 pontos | nenhuma | nenhum | - | IMPLEMENTADO | - |
| 4.11 AvailabilityCalc | Interno (sub-scoring) | src/Application/Services/Scheduling/AvailabilityCalculator.php | AvailabilityCalculator::calculateScore() | professional, date, load | 0-30 pontos | nenhuma | nenhum | - | IMPLEMENTADO | - |
| 4.12 GeolocationValid | Interno | src/Application/Services/Scheduling/GeolocationValidator.php | GeolocationValidator::validate() | coords, service_location | valid/invalid | nenhuma | nenhum | - | IMPLEMENTADO | - |
| 4.13 Offer Notification | limpvix_offers_sent | src/Infrastructure/Integration/OfferNotificationListener.php | OfferNotificationListener::handle() | offers data | notification sent | wp_limpvix_message_log | nenhum | - | IMPLEMENTADO | Depende de Communication provider configurado |
| 4.14 Fallback Cron | Hourly cron | src/Infrastructure/Cron/SendOffersCronAdapter.php | SendOffersCronAdapter::execute() | (batch) | offers recovered | wp_limpvix_contract_offers | nenhum | - | IMPLEMENTADO | Safety net |

**Score de Completude: 80%**

**Gaps Identificados:**
- Gap S1: AllocationEngine depende de interfaces (ProfessionalRepositoryInterface, ScheduleRepositoryInterface) no Domain/Scheduling que sao distintas das de Domain/Professional
- Gap S2: Nao ha endpoint REST dedicado para "alocar manualmente" um profissional a um contrato pelo admin
- Gap S3: Schedule creation listener (ScheduleCreationListener) esta registrado mas o fluxo de criacao automatica de schedules apos alocacao nao esta integrado end-to-end

---

## FLUXO 5: EXECUTION (Execucao do servico)

### Etapas End-to-End

| Etapa | Trigger | Arquivo | Metodo | Input | Output | Tabela BD | Evento | Proxima Etapa | Status | Gap |
|-------|---------|---------|--------|-------|--------|-----------|--------|---------------|--------|-----|
| 5.1 Criar Execution | Interno ou REST | src/Application/UseCase/Execution/CreateExecution.php | CreateExecution::execute() | CreateExecutionRequest | execution_uuid | wp_limpvix_executions | nenhum | 5.2 | IMPLEMENTADO | - |
| 5.2 Agendar Execution | REST POST /executions/{id}/schedule | src/Application/UseCase/Execution/ScheduleExecution.php | ScheduleExecution::execute() | ScheduleExecutionRequest | status=scheduled | wp_limpvix_executions | limpvix_execution_scheduled | 5.3 | IMPLEMENTADO | - |
| 5.3 Iniciar Execution | REST POST /executions/{id}/start | src/Application/UseCase/Execution/StartExecution.php | StartExecution::execute() | execution_uuid | status=in_progress | wp_limpvix_executions | limpvix_execution_started | 5.4 | IMPLEMENTADO | - |
| 5.4 Check-In | REST POST /executions/{id}/checkin | src/Application/UseCases/Execution/PerformCheckIn.php | PerformCheckIn::execute() | executionUuid, geo, now, epiEvidence | check_in data | wp_limpvix_executions, wp_limpvix_check_ins | limpvix_execution_checked_in | 5.5 | IMPLEMENTADO | - |
| 5.5 Adicionar Evidencias | REST POST /executions/{id}/evidence | src/Application/UseCases/Execution/AddEvidence.php | AddEvidence::execute() | execution_uuid, evidence | evidence added | wp_limpvix_executions | nenhum | 5.6 | IMPLEMENTADO | - |
| 5.6 Check-Out | REST POST /executions/{id}/checkout | src/Application/UseCases/Execution/PerformCheckOut.php | PerformCheckOut::execute() | executionUuid, geo, now | check_out data | wp_limpvix_executions, wp_limpvix_check_outs | nenhum | 5.7 | IMPLEMENTADO | - |
| 5.7 Completar Execution | REST POST /executions/{id}/complete | src/Application/UseCase/Execution/CompleteExecution.php | CompleteExecution::execute() | CompleteExecutionRequest | status=completed | wp_limpvix_executions | limpvix_execution_completed | 5.8 | IMPLEMENTADO | - |
| 5.8 Validar Execution | Interno/Admin | src/Application/UseCases/Execution/ValidateExecution.php | ValidateExecution::execute() | execution_uuid | status=validated | wp_limpvix_executions | nenhum | 5.9 | IMPLEMENTADO | - |
| 5.9 Aprovar Evidencia | Admin | src/Application/UseCases/Execution/ApproveEvidence.php | ApproveEvidence::execute() | execution_uuid, evidence_id | evidence approved | wp_limpvix_executions | nenhum | - | IMPLEMENTADO | - |
| 5.10 Reportar Problema | REST POST /executions/{id}/issue | src/Application/UseCases/Execution/ReportIssue.php | ReportIssue::execute() | execution_uuid, issue_type, description | issue registered | wp_limpvix_executions | nenhum | - | IMPLEMENTADO | - |
| 5.11 Mark No-Show | REST/Admin | src/Application/UseCase/Execution/MarkNoShow.php | MarkNoShow::execute() | execution_uuid | status=no_show | wp_limpvix_executions | limpvix_execution_no_show | - | IMPLEMENTADO | - |
| 5.12 Reagendar | REST POST /executions/{id}/reschedule | src/Application/UseCase/Execution/RescheduleExecution.php | RescheduleExecution::execute() | RescheduleExecutionRequest | new_date | wp_limpvix_executions | limpvix_execution_rescheduled | 5.2 | IMPLEMENTADO | - |
| 5.13 Cancelar Execution | REST POST /executions/{id}/cancel | src/Application/UseCase/Execution/CancelExecution.php | CancelExecution::execute() | CancelExecutionRequest | status=cancelled | wp_limpvix_executions | limpvix_execution_cancelled | - | IMPLEMENTADO | - |
| 5.14 Checked-In Notif | limpvix_execution_checked_in | src/Infrastructure/Integration/ExecutionCheckedInListener.php | ExecutionCheckedInListener::handle() | execution data | notification to customer | wp_limpvix_message_log | nenhum | - | IMPLEMENTADO | Depende de Communication provider |

**Status Machine da Execution:**
```
created -> scheduled -> in_progress -> completed -> validated
                    -> cancelled
                    -> no_show
                    -> rescheduled (-> scheduled)
```

**Score de Completude: 85%**

**Gaps Identificados:**
- Gap E1: Nao ha trigger automatico Schedule->Execution. Execution precisa ser criada manualmente ou via ScheduleNextExecution do contrato
- Gap E2: Event listeners de Execution sao stubs (@future) - nao enviam notificacoes reais
- Gap E3: Feedback window start em CompleteServiceWithPayout e invocado, mas nao ha cron para verificar expiracoes de feedback window (FeedbackReminderCronAdapter existe mas depende de `$sendMessage = null`)

---

## FLUXO 6: PAYMENT (Pagamento do cliente)

### Etapas End-to-End

| Etapa | Trigger | Arquivo | Metodo | Input | Output | Tabela BD | Evento | Proxima Etapa | Status | Gap |
|-------|---------|---------|--------|-------|--------|-----------|--------|---------------|--------|-----|
| 6.1 Checkout WooCommerce | Cliente finaliza compra | WooCommerce core | WC checkout | cart, payment | WC Order | wp_wc_orders | woocommerce_payment_complete | 6.2 | EXTERNO (WC) | - |
| 6.2 Captura PIX/CC | Gateway PIX (EFI/MP) | src/Infrastructure/Finance/Providers/EfiPaymentProvider.php | EfiPaymentProvider::createCharge() | amount, payer | charge_id, qr_code | nenhuma (externo) | nenhum | 6.3 | IMPLEMENTADO | EFI primario, MP fallback |
| 6.3 Webhook Pagamento | POST /limpvix/v1/webhooks/mercadopago | src/Infrastructure/API/Controllers/MercadoPagoWebhookController.php | MercadoPagoWebhookController::handle() | webhook payload | payment confirmed | wp_limpvix_recurring_payments | nenhum | 6.4 | IMPLEMENTADO | - |
| 6.4 Processar Webhook | Interno | src/Application/UseCases/Finance/ProcessPaymentWebhook.php | ProcessPaymentWebhook::execute() | payment_data | status updated | wp_limpvix_recurring_payments, wp_limpvix_contracts | nenhum | 6.5 | IMPLEMENTADO | - |
| 6.5 Cobranca Recorrente | DESATIVADO (cron comentado) | src/Application/UseCases/Finance/ChargeRecurringPayment.php | ChargeRecurringPayment::execute() | contract_id | charge created | wp_limpvix_recurring_payments | nenhum | 6.3 | IMPLEMENTADO mas DESATIVADO | Modelo on-demand |
| 6.6 Retry Pagamento Falho | Cron ou manual | src/Application/UseCases/Finance/RetryFailedPayment.php | RetryFailedPayment::execute() | payment_id | retried | wp_limpvix_recurring_payments | nenhum | 6.3 | IMPLEMENTADO | - |
| 6.7 Platform Fee Calc | Interno | src/Application/Services/PlatformFeeCalculator.php | PlatformFeeCalculator::calculate() | total, percentage | fee, net | nenhuma | nenhum | - | IMPLEMENTADO | - |
| 6.8 Financial Ledger | Evento interno | src/Application/UseCases/AppendLedgerEntry.php | AppendLedgerEntry::execute() | entry_data | ledger_id | wp_limpvix_financial_ledger | nenhum | - | IMPLEMENTADO | - |
| 6.9 Transicao FSM Financial | Comando | src/Application/UseCases/TransitionFinancialStatus.php | TransitionFinancialStatus::execute() | TransitionFinancialStatusCommand | new_status | wp_limpvix_financial_ledger | nenhum | - | IMPLEMENTADO | - |
| 6.10 Timeout Autorizacao | Cron | src/Infrastructure/Cron/PaymentAuthorizationTimeoutCronAdapter.php | PaymentAuthorizationTimeoutCronAdapter::execute() | (batch) | expired authorizations | wp_limpvix_recurring_payments | nenhum | - | IMPLEMENTADO | - |

**Score de Completude: 70%**

**Gaps Identificados:**
- Gap P1: Cobranca recorrente automatica esta DESATIVADA no ContractBootstrap (comentada). Modelo atual e on-demand por visita
- Gap P2: EfiPaymentProvider e MercadoPagoPaymentProvider existem mas credenciais dependem de wp_options configurados pelo admin
- Gap P3: WooCommerce Order (pagamento do briefing) e LimpVix recurring payment sao fluxos separados e desconectados. Nao ha unificacao

---

## FLUXO 7: PAYOUT (Pagamento ao profissional)

### Etapas End-to-End

| Etapa | Trigger | Arquivo | Metodo | Input | Output | Tabela BD | Evento | Proxima Etapa | Status | Gap |
|-------|---------|---------|--------|-------|--------|-----------|--------|---------------|--------|-----|
| 7.1 Execution Validated | Pos-validacao da execucao | src/Application/UseCases/Order/CompleteServiceWithPayout.php | CompleteServiceWithPayout::execute() | order, financial, execution | payout_authorized=true | wp_limpvix_orders | nenhum | 7.2 | IMPLEMENTADO | - |
| 7.2 Criar Payout do Ledger | Interno/evento | src/Application/Services/PayoutReconciliationService.php | PayoutReconciliationService::createPayoutFromLedger() | ledger_event_id, order_id, prof_id, amounts, recipient | payout_id | wp_limpvix_payouts | nenhum | 7.3 | IMPLEMENTADO | - |
| 7.3 Aprovar Payout | Feedback do cliente | src/Application/Services/PayoutReconciliationService.php | PayoutReconciliationService::approvePayout() | order_id, rating | status change | wp_limpvix_payouts | nenhum | 7.4 | IMPLEMENTADO | 5*=imediato, 4*=24h, <=3*=on_hold |
| 7.4 Criar Payout Manual | Admin | src/Application/UseCases/Financial/CreateManualPayout.php | CreateManualPayout::execute() | payout_data | payout_id | wp_limpvix_payouts | nenhum | 7.5 | IMPLEMENTADO | - |
| 7.5 Aprovar Payout Manual | Admin | src/Application/UseCases/Financial/ApproveManualPayout.php | ApproveManualPayout::execute() | payout_id | status=approved | wp_limpvix_payouts | nenhum | 7.6 | IMPLEMENTADO | - |
| 7.6 Executar Payout | Cron batch | src/Application/UseCases/Financial/ExecutePayout.php | ExecutePayout::execute() | payout_id | gateway_response | wp_limpvix_payouts | nenhum | 7.7 | IMPLEMENTADO | - |
| 7.7 Transferir via MP | Interno | src/Infrastructure/Finance/Providers/MercadoPagoPayoutProvider.php | MercadoPagoPayoutProvider::createPayout() | payout_id | mp_response | wp_limpvix_payouts | nenhum | 7.8 | IMPLEMENTADO | Depende de credenciais MP |
| 7.8 Batch Processar | Cron hourly | src/Application/Services/PayoutReconciliationService.php | PayoutReconciliationService::processBatch() | limit=10 | N processed | wp_limpvix_payouts | nenhum | 7.9 | IMPLEMENTADO | - |
| 7.9 Sincronizar Status | Cron 15min | src/Application/Services/PayoutReconciliationService.php | PayoutReconciliationService::syncProcessingPayouts() | (batch) | N synced | wp_limpvix_payouts | nenhum | - | IMPLEMENTADO | - |
| 7.10 Retry Falhas | Cron twicedaily | src/Application/Services/PayoutReconciliationService.php | PayoutReconciliationService::retryFailedPayouts() | (batch) | N retried | wp_limpvix_payouts | nenhum | 7.7 | IMPLEMENTADO | - |
| 7.11 Reconciliation Cron | Cron | src/Infrastructure/Cron/PayoutReconciliationCronAdapter.php | PayoutReconciliationCronAdapter::executeStatic() | (batch) | reconciled | wp_limpvix_payouts | nenhum | - | IMPLEMENTADO | - |

**Status Machine do Payout:**
```
pending -> approved -> processing -> completed
                   -> failed (-> retry -> processing)
       -> on_hold (manual review) -> approved
```

**Score de Completude: 75%**

**Gaps Identificados:**
- Gap PO1: PayoutReconciliationService::registerCronHooks() e scheduleCronJobs() existem mas NAO sao chamados no Kernel/Hooks. Os cron jobs de payout batch/sync/retry nao estao agendados
- Gap PO2: Audit trail (wp_limpvix_payout_audit_trail) mencionado no schema mas nao ha UseCase escrevendo nela
- Gap PO3: MercadoPago payout requer OAuth do profissional (ProfessionalOAuthController existe) mas o fluxo de vinculacao MP -> profissional nao esta completo
- Gap PO4: EFI Bank payout (dual mode na migration 027) existe na migration mas nao ha ExecuteTransfer UseCase integrado com EFI

---

## FLUXO 8: FEEDBACK (Avaliacao pos-servico)

### Etapas End-to-End

| Etapa | Trigger | Arquivo | Metodo | Input | Output | Tabela BD | Evento | Proxima Etapa | Status | Gap |
|-------|---------|---------|--------|-------|--------|-----------|--------|---------------|--------|-----|
| 8.1 Iniciar Feedback Window | Execution validated | src/Domain/Execution/Execution.php | Execution::startFeedbackWindow() | none | feedback_window_expires_at | wp_limpvix_executions | nenhum | 8.2 | IMPLEMENTADO | 24h window |
| 8.2 Lembrete Feedback | Cron | src/Infrastructure/Adapters/FeedbackReminderCronAdapter.php | FeedbackReminderCronAdapter::execute() | (batch) | reminders sent | wp_limpvix_message_log | nenhum | 8.3 | PARCIAL | $sendMessage = null (provider nao injetado) |
| 8.3 Verificar Window | Interno | src/Application/UseCases/Feedback/CheckFeedbackWindowStatus.php | CheckFeedbackWindowStatus::execute() | execution_uuid | window status | wp_limpvix_executions | nenhum | 8.4 | IMPLEMENTADO | - |
| 8.4 Submeter Feedback | REST (futuro) / interno | src/Application/UseCases/Feedback/SubmitStructuredFeedback.php | SubmitStructuredFeedback::execute() | feedback data (checklist, score, photos) | feedback_uuid | wp_limpvix_structured_feedbacks | FeedbackCreated | 8.5 | IMPLEMENTADO | REST API nao registrada (comentada no FeedbackBootstrap) |
| 8.5 Validar Completude | Interno | src/Application/Services/Feedback/FeedbackCompletenessValidator.php | FeedbackCompletenessValidator::validate() | feedback | completeness score | nenhuma | nenhum | 8.6 | IMPLEMENTADO | - |
| 8.6 Aprovar Feedback | Admin | src/Application/UseCases/Feedback/ApproveFeedback.php | ApproveFeedback::execute() | feedback_uuid | status=approved | wp_limpvix_structured_feedbacks | FeedbackApproved | 8.7 | IMPLEMENTADO | - |
| 8.7 Rejeitar Feedback | Admin | src/Application/UseCases/Feedback/RejectFeedback.php | RejectFeedback::execute() | feedback_uuid, reason | status=rejected | wp_limpvix_structured_feedbacks | FeedbackRejected | - | IMPLEMENTADO | - |
| 8.8 Disputar Feedback | Profissional | src/Application/UseCases/Feedback/DisputeFeedback.php | DisputeFeedback::execute() | feedback_uuid, dispute_data | dispute created | wp_limpvix_feedback_disputes | nenhum | 8.9 | IMPLEMENTADO | - |
| 8.9 Calcular Score Prof | Pos-feedback | src/Application/UseCases/Feedback/CalculateProfessionalScore.php | CalculateProfessionalScore::execute() | professional_id | new_score | wp_limpvix_professionals | nenhum | - | IMPLEMENTADO | - |
| 8.10 Processar Feedback | Evento | src/Application/UseCases/ProcessFeedbackReceived.php | ProcessFeedbackReceived::execute() | feedback_data | payout decision | wp_limpvix_payouts | nenhum | Fluxo 7 | IMPLEMENTADO | - |

**Score de Completude: 65%**

**Gaps Identificados:**
- Gap F1: REST API para feedback NAO esta registrada (comentada em FeedbackBootstrap: `// self::registerRestAPI(...)`)
- Gap F2: FeedbackReminderCronAdapter recebe `$sendMessage = null` -- lembretes nao sao enviados
- Gap F3: Feedback scheduling listener (FeedbackSchedulingListener) esta registrado mas fluxo de trigger automatico pos-execucao nao esta completo
- Gap F4: Integracao Feedback -> Payout aprovacao esta no ProcessFeedbackReceived UseCase mas nao ha event listener conectando FeedbackApproved ao PayoutReconciliationService

---

## FLUXO 9: PROFESSIONAL LIFECYCLE

### Etapas End-to-End

| Etapa | Trigger | Arquivo | Metodo | Input | Output | Tabela BD | Evento | Proxima Etapa | Status | Gap |
|-------|---------|---------|--------|-------|--------|-----------|--------|---------------|--------|-----|
| 9.1 Registrar Professional | REST POST /professionals/register | src/Application/UseCase/Professional/RegisterProfessional.php | RegisterProfessional::execute() | full_name, cpf, phone, email, address, skills | professional_id, user_id | wp_limpvix_professionals, wp_users | limpvix_professional_registered | 9.2 | IMPLEMENTADO | - |
| 9.2 Geocode Endereco | Interno (durante registro) | src/Application/UseCase/Professional/RegisterProfessional.php | RegisterProfessional::geocodeAddress() | address | lat, lng | nenhuma | nenhum | - | IMPLEMENTADO | Fallback para coords de Vitoria-ES |
| 9.3 Upload Documentos | REST POST /professionals/{id}/documents | src/Application/UseCases/Professional/UploadDocument.php | UploadDocument::execute() | professional_id, document_type, file | document_id | wp_limpvix_professional_documents | nenhum | 9.4 | IMPLEMENTADO | - |
| 9.4 Revisar Documento | Admin AJAX | src/Application/UseCases/Professional/ReviewDocument.php | ReviewDocument::execute() | document_id, status, notes | updated | wp_limpvix_professional_documents | nenhum | 9.5 | IMPLEMENTADO | - |
| 9.5 Verificacao KYC | Interno/pipeline | src/Application/UseCases/Verification/RunVerificationPipeline.php | RunVerificationPipeline::execute() | professional_id | verification_result | wp_limpvix_professional_verification, wp_limpvix_user_verifications | nenhum | 9.6 | IMPLEMENTADO | - |
| 9.6 Processar KYC | Interno | src/Application/UseCase/Professional/ProcessKYC.php | ProcessKYC::execute() | professional_id | kyc_status | wp_limpvix_professionals | nenhum | 9.7 | IMPLEMENTADO | - |
| 9.7 Atualizar Disponibilidade | REST PUT /professionals/{id}/availability | src/Application/UseCase/Professional/UpdateAvailability.php | UpdateAvailability::execute() | professional_id, schedule | updated | wp_limpvix_professional_availability | nenhum | - | IMPLEMENTADO | - |
| 9.8 Atualizar Score | Evento interno | src/Application/UseCase/Professional/UpdateProfessionalScore.php | UpdateProfessionalScore::execute() | professional_id | new_score | wp_limpvix_professionals | nenhum | - | IMPLEMENTADO | - |
| 9.9 Listar Profissionais | REST GET /professionals | src/Application/UseCase/Professional/ListProfessionals.php | ListProfessionals::execute() | filters | professionals array | wp_limpvix_professionals | nenhum | - | IMPLEMENTADO | - |
| 9.10 Historico Alocacoes | REST GET /professionals/{id}/allocations | src/Application/UseCase/Professional/GetAllocationHistory.php | GetAllocationHistory::execute() | professional_id | allocations | wp_limpvix_professional_allocations | nenhum | - | IMPLEMENTADO | - |
| 9.11 Historico Score | REST GET /professionals/{id}/score-history | src/Application/UseCase/Professional/GetScoreHistory.php | GetScoreHistory::execute() | professional_id | score history | wp_limpvix_professionals | nenhum | - | IMPLEMENTADO | - |
| 9.12 PPID Verification | Admin AJAX | src/Infrastructure/KYC/PPIDProviderFactory.php | PPIDProviderFactory::testConnection() | email, senha | saldo, nome | nenhuma (externo) | nenhum | - | IMPLEMENTADO | - |
| 9.13 Professional OAuth MP | REST /professionals/oauth/mercadopago | src/Infrastructure/API/ProfessionalOAuthController.php | ProfessionalOAuthController::authorize() | professional_id | oauth_url | nenhuma | nenhum | - | IMPLEMENTADO | - |

**Score de Completude: 80%**

**Gaps Identificados:**
- Gap PR1: Ativacao/desativacao do profissional depende de migration 023 (status column) mas nao ha UseCase dedicado ActivateProfessional / DeactivateProfessional
- Gap PR2: Consent records nao encontrados no codigo -- nenhuma tabela ou UseCase para consentimento LGPD
- Gap PR3: Risk score nao implementado como UseCase separado; score e apenas media de ratings

---

## FLUXO 10: COMUNICACAO

### Etapas End-to-End

| Etapa | Trigger | Arquivo | Metodo | Input | Output | Tabela BD | Evento | Proxima Etapa | Status | Gap |
|-------|---------|---------|--------|-------|--------|-----------|--------|---------------|--------|-----|
| 10.1 Enviar Mensagem | Evento de dominio | src/Application/UseCases/Communication/SendTemplatedMessage.php | SendTemplatedMessage::execute() | template_id, recipient, variables | message_id | wp_limpvix_message_log | MessageSentEvent / MessageFailedEvent | 10.2 | IMPLEMENTADO | - |
| 10.2 Message Queue | Retry scheduling | src/Application/Services/Communication/MessageQueueService.php | MessageQueueService::scheduleRetry() | message_data | queue_id | wp_limpvix_message_queue | nenhum | 10.3 | IMPLEMENTADO | - |
| 10.3 Processar Queue | Cron single event | src/Application/Services/Communication/MessageQueueService.php | MessageQueueService::processQueueItem() | queue_id | processed | wp_limpvix_message_queue | limpvix_retry_message | 10.1 | IMPLEMENTADO | - |
| 10.4 Order Communication | Evento limpvix_order_* | src/Infrastructure/Integration/OrderCommunicationListener.php | OrderCommunicationListener::handle() | order event | message sent | wp_limpvix_message_log | nenhum | - | IMPLEMENTADO | - |
| 10.5 Queue Cron | WP Cron periodic | src/Infrastructure/Integration/MessageQueueCronListener.php | MessageQueueCronListener::process() | (batch) | messages processed | wp_limpvix_message_queue | nenhum | - | IMPLEMENTADO | - |

**Providers Configurados:**

| Provider | Arquivo | Canal | Status |
|----------|---------|-------|--------|
| WhatsApp 360Dialog | TODO (communicationProvider = null) | WhatsApp | AUSENTE -- provider nao implementado |
| Twilio SMS | src/Admin/Settings/TwilioSettings.php | SMS | PARCIAL -- settings UI existe, provider nao injetado |
| Firebase Push | src/Admin/Settings/FirebaseSettings.php | Push | PARCIAL -- settings UI existe, provider nao injetado |
| NVoip | src/Admin/Settings/NVoipSettings.php | VoIP | PARCIAL -- settings UI existe, provider nao injetado |

**Score de Completude: 40%**

**Gaps Identificados:**
- Gap COM1: CRITICO: communicationProvider = null no CommunicationBootstrap. Nenhuma mensagem real e enviada via qualquer canal
- Gap COM2: Twilio, Firebase, NVoip tem Settings UI mas nenhum Provider implementa CommunicationProviderInterface
- Gap COM3: Templates existem na tabela mas sem canal funcional nenhum template e realmente enviado
- Gap COM4: WhatsApp 360Dialog mencionado como TODO mas nao implementado

---

## FLUXO 11: CRON JOBS (Automacoes)

### Mapa Completo de Cron Jobs

| # | Hook Name | Callback | Frequencia | Registrado em | Status |
|---|-----------|----------|------------|---------------|--------|
| 1 | limpvix_check_contract_expiration | ContractBootstrap::onCheckContractExpiration | daily | ContractBootstrap::registerCronJobs() | FUNCIONAL |
| 2 | limpvix_charge_recurring_payments | ContractBootstrap::onChargeRecurringPayments | - | COMENTADO no ContractBootstrap | DESATIVADO |
| 3 | limpvix_fallback_send_offers | SendOffersCronAdapter::execute | hourly | SendOffersCronAdapter::register() | FUNCIONAL |
| 4 | limpvix_reconcile_payouts | PayoutReconciliationCronAdapter::executeStatic | configuravel | PayoutReconciliationCronAdapter::register() | FUNCIONAL |
| 5 | limpvix_payment_authorization_timeout | PaymentAuthorizationTimeoutCronAdapter::execute | configuravel | PaymentAuthorizationTimeoutCronAdapter::register() | FUNCIONAL |
| 6 | limpvix_feedback_reminder | FeedbackReminderCronAdapter::execute | configuravel | SchedulingBootstrap::registerCronAdapters() | PARCIAL ($sendMessage=null) |
| 7 | limpvix_approve_payout | PayoutReconciliationService::processScheduledApproval | single event | PayoutReconciliationService (per-payout) | IMPLEMENTADO mas nao registrado no boot |
| 8 | limpvix_process_payout_batch | PayoutReconciliationService::processBatch | hourly | PayoutReconciliationService::scheduleCronJobs() | IMPLEMENTADO mas NAO CHAMADO no boot |
| 9 | limpvix_sync_payouts | PayoutReconciliationService::syncProcessingPayouts | every_15_min | PayoutReconciliationService::scheduleCronJobs() | IMPLEMENTADO mas NAO CHAMADO no boot |
| 10 | limpvix_retry_failed_payouts | PayoutReconciliationService::retryFailedPayouts | twicedaily | PayoutReconciliationService::scheduleCronJobs() | IMPLEMENTADO mas NAO CHAMADO no boot |
| 11 | limpvix_process_message_queue | MessageQueueService::processQueueItem | single event | MessageQueueService::scheduleRetry() | FUNCIONAL (per-message) |
| 12 | limpvix_contract_expiring_check | OnContractExpiring::check | daily | SchedulingBootstrap (via init) | FUNCIONAL |

**Score de Completude CRON: 55%**

**Gaps Criticos:**
- Gap CR1: PayoutReconciliationService::scheduleCronJobs() e registerCronHooks() NUNCA sao chamados no Kernel/Hooks/ContractBootstrap. Os cron jobs 8, 9, 10 existem mas nao estao agendados
- Gap CR2: Recurring payment cron (job 2) esta COMENTADO. Modelo on-demand
- Gap CR3: Feedback reminder cron (job 6) nao envia mensagens reais ($sendMessage = null)

---

## MAPA DE ENDPOINTS REST

| Metodo | Path | Controller | Auth | Modulo |
|--------|------|------------|------|--------|
| GET | /limpvix/v1/briefing/schema | BriefingSchemaController | public | Briefing |
| POST | /limpvix/v1/briefing | BriefingController | JWT | Briefing |
| GET | /limpvix/v1/briefing/{uuid} | BriefingController | JWT (owner/admin) | Briefing |
| POST | /limpvix/v1/briefing/{uuid}/step | BriefingStepController | JWT (owner/admin) | Briefing |
| POST | /limpvix/v1/briefing/{uuid}/verify-phone | BriefingPhoneController | JWT (owner/admin) | Briefing |
| POST | /limpvix/v1/briefing/{uuid}/package | PackageController | JWT | Briefing |
| GET | /limpvix/v1/service-catalog | ServiceCatalogController | public | Catalog |
| GET | /limpvix/v1/cep/{cep} | CepController | public | Util |
| GET | /limpvix/v1/contracts | ContractController | admin/JWT | Contract |
| POST | /limpvix/v1/contracts | ContractController | admin | Contract |
| GET | /limpvix/v1/contracts/{id} | ContractController | admin/JWT | Contract |
| PUT | /limpvix/v1/contracts/{id}/submit | ContractController | admin | Contract |
| PUT | /limpvix/v1/contracts/{id}/activate | ContractController | admin | Contract |
| PUT | /limpvix/v1/contracts/{id}/pause | ContractController | admin | Contract |
| PUT | /limpvix/v1/contracts/{id}/resume | ContractController | admin | Contract |
| PUT | /limpvix/v1/contracts/{id}/cancel | ContractController | admin | Contract |
| PUT | /limpvix/v1/contracts/{id}/complete | ContractController | admin | Contract |
| POST | /limpvix/v1/contracts/{id}/send-offers | ContractController | admin | Contract |
| POST | /limpvix/v1/contracts/{id}/reallocate | ContractController | admin | Contract |
| GET | /limpvix/v1/contracts/statistics | ContractController | admin | Contract |
| GET | /limpvix/v1/offers | OfferController | JWT (professional) | Offer |
| GET | /limpvix/v1/offers/{id} | OfferController | JWT (professional) | Offer |
| POST | /limpvix/v1/offers/{id}/accept | OfferController | JWT (professional) | Offer |
| POST | /limpvix/v1/offers/{id}/reject | OfferController | JWT (professional) | Offer |
| GET | /limpvix/v1/executions | ExecutionController | admin/JWT | Execution |
| POST | /limpvix/v1/executions | ExecutionController | admin | Execution |
| GET | /limpvix/v1/executions/{id} | ExecutionController | admin/JWT | Execution |
| POST | /limpvix/v1/executions/{id}/schedule | ExecutionController | admin | Execution |
| POST | /limpvix/v1/executions/{id}/start | ExecutionController | JWT (professional) | Execution |
| POST | /limpvix/v1/executions/{id}/complete | ExecutionController | JWT (professional) | Execution |
| POST | /limpvix/v1/executions/{id}/cancel | ExecutionController | admin | Execution |
| POST | /limpvix/v1/executions/{id}/reschedule | ExecutionController | admin | Execution |
| POST | /limpvix/v1/executions/{id}/no-show | ExecutionController | admin | Execution |
| GET | /limpvix/v1/professionals | ProfessionalController | admin | Professional |
| POST | /limpvix/v1/professionals/register | ProfessionalController | public/admin | Professional |
| GET | /limpvix/v1/professionals/{id} | ProfessionalController | admin/JWT | Professional |
| POST | /limpvix/v1/professionals/{id}/documents | ProfessionalDocumentController | JWT | Professional |
| GET | /limpvix/v1/professionals/{id}/documents | ProfessionalDocumentController | admin/JWT | Professional |
| PUT | /limpvix/v1/professionals/documents/{id}/review | ProfessionalDocumentController | admin | Professional |
| GET | /limpvix/v1/professionals/oauth/mercadopago | ProfessionalOAuthController | JWT | Professional |
| GET | /limpvix/v1/customers | CustomerController | admin | Customer |
| GET | /limpvix/v1/customers/{id} | CustomerController | admin/JWT | Customer |
| POST | /limpvix/v1/webhooks/mercadopago | MercadoPagoWebhookController | webhook signature | Payment |
| GET | /limpvix/v1/health | HealthController | admin | System |
| GET | /limpvix/v1/health/cron | HealthController | admin | System |
| POST | /limpvix/v1/auth/otp/send | OtpController | public | Auth |
| POST | /limpvix/v1/auth/otp/verify | OtpController | public | Auth |
| GET | /limpvix/v1/auth/token | AuthController | JWT | Auth |
| POST | /limpvix/v1/api-keys | ApiKeyController | admin | Auth |

**Total: ~42 endpoints REST**

---

## LISTA CONSOLIDADA DE GAPS E BROKEN LINKS

### GAPS CRITICOS (bloqueiam fluxo operacional)

| # | Fluxo | Descricao | Impacto | Severidade |
|---|-------|-----------|---------|------------|
| GAP-COM1 | Comunicacao | communicationProvider = null. NENHUMA mensagem real e enviada | Profissionais e clientes nao recebem notificacoes | CRITICO |
| GAP-CR1 | Cron/Payout | PayoutReconciliationService cron jobs nao estao agendados no boot | Payouts aprovados nao sao processados em batch | CRITICO |
| GAP-F1 | Feedback | REST API para feedback nao registrada (comentada) | Cliente nao pode submeter feedback via API | ALTO |
| GAP-O4 | Order | Dois conceitos de "Order" coexistem (WC Order vs LimpVix Order) sem ponte clara | Confusao arquitetural; estado financeiro fragmentado | ALTO |

### GAPS MODERADOS (funcionalidade parcial)

| # | Fluxo | Descricao | Impacto |
|---|-------|-----------|---------|
| GAP-E1 | Execution | Nao ha trigger automatico Schedule->Execution | Execution precisa ser criada manualmente |
| GAP-E2 | Execution | Event listeners sao stubs (@future) | Nenhuma notificacao real em eventos de execucao |
| GAP-F2 | Feedback | FeedbackReminderCronAdapter com $sendMessage = null | Lembretes de feedback nao sao enviados |
| GAP-P1 | Payment | Cobranca recorrente desativada | Modelo on-demand apenas (sem auto-cobranca) |
| GAP-PO1 | Payout | Payout batch/sync/retry crons nao agendados | Processamento manual necessario |
| GAP-PO3 | Payout | OAuth MP do profissional nao integrado end-to-end | Payout via MP requer setup manual |
| GAP-PR1 | Professional | Sem UseCase de ativar/desativar profissional | Gerenciamento de status incompleto |
| GAP-S3 | Scheduling | Schedule creation automatica pos-alocacao nao integrada | Agendamento manual necessario |

### GAPS MENORES (melhorias desejadas)

| # | Fluxo | Descricao |
|---|-------|-----------|
| GAP-B1 | Briefing | Briefing avulso (nao-recorrente) nao gera contrato |
| GAP-C1 | Contract | Auto-renew via payment desativado |
| GAP-PR2 | Professional | Consent records LGPD ausentes |
| GAP-PR3 | Professional | Risk score e apenas media simples de ratings |
| GAP-P2 | Payment | Credenciais EFI/MP dependem de config manual |
| GAP-PO2 | Payout | Audit trail tabela existe mas nao e populada |
| GAP-S1 | Scheduling | Repositories duplicados (Domain/Scheduling vs Domain/Professional) |

---

## SCORE GERAL DE COMPLETUDE POR FLUXO

| Fluxo | Score | Justificativa |
|-------|-------|---------------|
| 1. Briefing | 85% | Fluxo mais completo; falta frontend e Firebase config |
| 2. Order | 55% | Dois modelos de Order coexistem desconectados |
| 3. Contract | 90% | DDD maturo; 15 Use Cases; state machine completa |
| 4. Scheduling/Allocation | 80% | AllocationEngine implementado; falta integracao automatica |
| 5. Execution | 85% | 9 Use Cases; check-in/out com geo; EPI validation |
| 6. Payment | 70% | EFI + MP providers existem; recurring desativado |
| 7. Payout | 75% | Reconciliation service completo; crons nao agendados |
| 8. Feedback | 65% | Use Cases implementados; REST API nao registrada |
| 9. Professional | 80% | Registro + KYC + Documents completos; falta ativacao |
| 10. Comunicacao | 40% | Infraestrutura pronta; ZERO providers funcionais |
| 11. Cron Jobs | 55% | 12 crons identificados; 4 nao agendados; 2 parciais |

**SCORE MEDIO GERAL: 71%**

---

## BROKEN LINKS ENTRE FLUXOS

```
Briefing ----[OK]----> Contract (via BriefingContractListener)
Contract ----[OK]----> Offers (via autoSendOffers)
Offers ------[OK]----> Allocation (via AcceptOffer)
Allocation --[BROKEN]-> Schedule (ScheduleCreationListener registrado mas trigger incompleto)
Schedule ----[BROKEN]-> Execution (sem trigger automatico)
Execution ---[OK]----> Feedback Window (startFeedbackWindow)
Feedback ----[BROKEN]-> Payout (ProcessFeedbackReceived existe mas event wiring incompleto)
Payout ------[BROKEN]-> MP Transfer (crons de batch nao agendados no boot)
ALL ----------[BROKEN]-> Communication (provider = null)
```

**Links funcionando ponta-a-ponta:** Briefing -> Contract -> Offers -> Accept Offer
**Links quebrados a partir de:** Schedule -> Execution (manual), Feedback -> Payout (wiring), Payout -> Transfer (cron)
**Link universalmente quebrado:** Communication (nenhum canal funcional)
