# 08 - OPERATIONAL FLOWS BLUEPRINT

**Data:** 2026-02-18
**Versao:** 1.0
**Status:** Fase 0 COMPLETA | Fase 1 PENDENTE | Fase 2 PENDENTE
**Fonte:** Convergencia de 3 canais - Auditoria tecnica (8 docs), Analise UI Admin, 145+ docs historicos

---

## Contexto

O LimpVix e um **marketplace on-demand de servicos de limpeza** (tipo Uber de limpeza).
Conecta **clientes** que precisam de servicos a **profissionais** de limpeza verificados.

Apos 4 rodadas de auditoria (14 agentes), 3 fontes de informacao foram convergidas:
1. **Auditoria tecnica** - 8 docs, 90 achados, 5 fatal errors, 497 arquivos PHP
2. **Analise da UI Admin** - 12 abas settings, 6+ paginas, 10 gaps de UI
3. **145+ docs historicos** - Go-Live score 24.7/100, 5 P0 blockers, arquitetura DDD com 8 Bounded Contexts

**Problema central:** Tinhamos inventario tecnico mas NAO tinhamos fluxos operacionais definidos.
Este documento define primeiro O QUE o sistema deve fazer, depois COMO corrigir para atingir isso.

---

## PARTE 1: FLUXOS OPERACIONAIS DEFINIDOS (O Blueprint)

### FLUXO 1: ONBOARDING DO PROFISSIONAL

```
Objetivo: Profissional cadastrado, verificado (KYC) e pronto para receber ofertas
Atores: Profissional, Admin
Trigger: Profissional acessa formulario de cadastro

[1] Registro -> [2] OTP telefone -> [3] Upload docs (RG, CPF, comprovante) ->
[4] KYC biometrico (PPID: selfie+liveness) -> [5] Background check (Exato Digital) ->
[6] Admin aprova -> [7] Setup pagamento (PIX/conta) -> [8] Define regiao+skills -> ATIVO

State Machine: pending_documents -> pending_verification -> verified_documents -> active

Regras:
  - Rating inicial = 0 (calculado apos 5 servicos)
  - Raio de servico padrao: 10km
  - Max 10 skills por profissional
  - 3+ no-shows/mes = suspensao automatica
  - Limite diario: 480 min (8h)

Status no codigo: 95% implementado
Gap: Falta UseCase ActivateProfessional/DeactivateProfessional
```

### FLUXO 2: JORNADA DO CLIENTE (Briefing -> Pagamento)

```
Objetivo: Cliente solicita servico, define requisitos e paga
Atores: Cliente, Sistema, Payment Gateway (EFI/MP)
Trigger: Cliente clica "Solicitar Limpeza"

[1] Criar briefing (draft) -> [2] Tipo imovel (residencial/comercial) ->
[3] Estrutura (m2, quartos, banheiros) -> [4] Calcular metricas (duracao, profissionais) ->
[5] Frequencia (unico/semanal/quinzenal/mensal) -> [6] Avaliar complexidade ->
[7] Selecionar pacote (basic/standard/premium) -> [8] Verificar telefone (OTP) ->
[9] Visualizar preco (taxa 15%) -> [10] Pagar via WooCommerce (PIX/CC) ->
[11] Lock briefing -> [12] Gerar contrato (se recorrente)

State Machine: draft -> in_progress -> pending_phone -> awaiting_payment -> paid -> locked

Regras:
  - Briefing expira em 48h se nao concluido
  - Complexidade: simples=base, medium=+10%, complex=+25%
  - Taxa plataforma padrao: 15% (configuravel)
  - Metricas: m2 x 3min/m2 + 30min buffer
  - Frequencia semanal/quinzenal/mensal = contrato obrigatorio

Status no codigo: 85% implementado
Gap: Briefing avulso (one-off) nao gera contrato automaticamente
```

### FLUXO 3: CICLO DO CONTRATO (Criacao -> Expiracao/Renovacao)

```
Objetivo: Gerenciar contratos recorrentes com agendamento automatico
Atores: Contract Aggregate, System Cron, Admin, Professional
Trigger: Briefing locked OU admin cria manualmente

[1] Criar contrato (draft) -> [2] Gerar numero (LMPVX-YYYYMM-NNNNNN) ->
[3] Submeter para alocacao -> [4] Alocar profissional (Fluxo 4) ->
[5] Ativar contrato -> [6] Enviar ofertas automaticamente ->
[7] Agendar proxima execucao -> [LOOP: execucao -> cobranca -> proxima execucao]
[Opcoes: Pausar <-> Retomar | Realocar profissional | Cancelar | Completar]
[Expiracao: Cron diario verifica endDate -> expirar -> renovar se auto_renew=true]

State Machine: draft -> pending_allocation -> active <-> paused -> completed|cancelled|expired

Regras:
  - Recorrencia: weekly(+7d), biweekly(+14d), monthly(dia fixo)
  - Auto-renew requer payment approved
  - 3+ no-shows do profissional = realocacao automatica
  - Contrato completed/cancelled = terminal (irreversivel)

Status no codigo: 90% implementado
Gaps:
  - 'expired' escrito no BD mas ContractStatus VO rejeita
  - renew() bypassa state machine (permite transicoes ilegais)
  - Cron de cobranca recorrente COMENTADO (linha 461)
```

### FLUXO 4: ALOCACAO E MATCHING

```
Objetivo: Encontrar o melhor profissional para cada servico
Atores: AllocationEngine, ProximityScorer, AvailabilityCalculator
Trigger: Contrato ativado OU admin solicita manualmente

[1] Buscar profissionais elegiveis (regiao, skills, status=active) ->
[2] Score proximidade (40pts: Haversine, 0-5km=40, 5-10km=30, 10-15km=20) ->
[3] Score disponibilidade (30pts: slots livres no calendario) ->
[4] Score rating (20pts: media de avaliacoes) ->
[5] Score carga (10pts: menos contratos ativos = mais pontos) ->
[6] Ranking por score total (max 100) ->
[7] Enviar ofertas (top 1-3 profissionais) ->
[8] Profissional aceita/rejeita -> [9] Confirmar alocacao

Regras:
  - Pesos: proximidade(40%) > disponibilidade(30%) > rating(20%) > carga(10%)
  - Timeout oferta: implicitamente 48h (nao enforced no codigo)
  - Fallback: se nenhum aceita, cron hourly reenvia
  - Carga maxima: 480min/dia por profissional

Status no codigo: 80% implementado
Gaps:
  - Sem endpoint REST para alocacao manual pelo admin
  - Schedule creation automatica pos-alocacao nao integrada E2E
  - ViaCEP nao retorna coordenadas (precisa geocoding)
```

### FLUXO 5: EXECUCAO DO SERVICO (Check-in -> Check-out)

```
Objetivo: Rastrear execucao em tempo real com evidencias validadas
Atores: Profissional, Cliente, ContractExecution
Trigger: Data agendada atingida

[1] Notificar profissional (24h antes) ->
[2] Profissional chega no local -> [3] Check-in (GPS < 150m + foto EPI) ->
[4] Executar servico (duracao minima 1h) ->
[5] Coletar evidencias (min 2 fotos before/after) ->
[6] Check-out (GPS + evidencias) -> [7] Validar execucao (admin ou auto) ->
[8] Abrir janela de feedback (24h)
[Alternativas: No-show (+30min sem check-in) | Cancelamento | Reagendamento]

State Machine: created -> scheduled -> in_progress -> completed -> validated

Regras:
  - Geofence: 150m do endereco para check-in
  - EPI obrigatorio: foto de equipamentos de protecao
  - SLA: check-in +/-15min da janela agendada
  - No-show: -0.5 rating, 3+/mes = suspensao
  - Cancelamento: ate 24h=gratis, 24h-2h=50%, <2h=100%

Status no codigo: 85% implementado
Gaps:
  - SEM trigger automatico Schedule -> Execution (manual hoje)
  - Event listeners sao stubs (nao enviam notificacoes reais)
  - ExecutionManagementPage AUSENTE no admin
  - Duas state machines divergentes (ExecutionStatusEnum vs ExecutionStatus)
```

### FLUXO 6: CICLO FINANCEIRO (Pagamento -> Taxa -> Payout)

```
Objetivo: Dinheiro entra, plataforma retem taxa, profissional recebe
Atores: Customer, Financial Aggregate, LedgerEntry, PayoutReconciliation
Trigger: Order criada via Briefing

[1] Criar Financial (pending) -> [2] Autorizar pagamento (MP/EFI) ->
[3] Capturar pagamento -> [4] Calcular taxa (15%) ->
[5] Registrar no Ledger -> [6] Colocar em Hold (aguardando execucao) ->
[7] Execucao completa -> [8] Autorizar payout ->
[9] Criar payout record -> [10] Processar batch (cron hourly) ->
[11] Sincronizar status (cron 15min) -> [12] Payout completo ->
[Decisao de timing baseada em feedback:
  5 estrelas = payout imediato | 4 estrelas = payout em 24h | <=3 estrelas = hold manual]

State Machine Financial: pending -> authorized -> captured -> held -> payout_authorized -> payout_completed
State Machine Payout: pending -> approved -> processing -> completed|failed

Regras:
  - Taxa padrao: 15%
  - Hold: dinheiro congelado ate check-out
  - Golden Rule: payout_authorized REQUER Order.status = COMPLETED
  - Retry: max 3 tentativas de payout
  - D+1 ou D+2 para profissional receber

Status no codigo: 70-75% implementado
Gaps CRITICOS:
  - EFI Bank PIX corrigido na Fase 0 (curlRequest param)
  - Payout batch crons NAO REGISTRADOS no boot
  - Cobranca recorrente DESATIVADA (cron comentado)
```

### FLUXO 7: FEEDBACK E QUALIDADE

```
Objetivo: Avaliar qualidade, calcular rating, resolver disputas
Atores: Cliente, StructuredFeedback, Professional, Admin
Trigger: Execucao validada (janela de 24h)

[1] Abrir janela feedback -> [2] Lembrete (se nao submetido em 12h) ->
[3] Cliente preenche checklist (criterios 1-5) -> [4] Anexar fotos (min 2) ->
[5] Comentario (opcional) -> [6] Submeter feedback ->
[7] Calcular score final -> [8] Atualizar rating profissional ->
[9] Decisao de payout (5 estrelas=imediato, 4 estrelas=24h, <=3 estrelas=hold) ->
[10] Profissional pode disputar (48h) -> [11] Admin resolve disputa

Regras:
  - Janela: 24h apos execucao validada
  - Lembretes: 3 tentativas (12h, 24h, 48h)
  - Score < 3.0 = suspensao automatica do profissional
  - Timeout sem feedback (24h) = payout automatico
  - Disputes: profissional tem 48h para contestar

Status no codigo: 65% implementado
Gaps:
  - REST API para feedback COMENTADA (nao registrada)
  - Lembretes $sendMessage = null (nao enviam de verdade)
  - Wiring Feedback -> Payout incompleto
```

### FLUXO 8: RECORRENCIA (Pagamento + Agendamento Automatico)

```
Objetivo: Cobrar automaticamente e agendar proxima execucao
Atores: RecurringPayment, Contract, System Cron, Payment Gateway
Trigger: Cron diario detecta contrato ativo com data de cobranca

[1] Buscar contratos ativos com dueDate hoje ->
[2] Calcular valor por execucao (monthly/4.33 ou /2.16 ou /1) ->
[3] Notificar customer (5 dias antes) -> [4] Processar cobranca ->
[5] Gateway aprova -> [6] Marcar pago -> [7] Renovar contrato ->
[8] Agendar proxima execucao -> [LOOP]
[Falha: retry em +3d, +5d, 3a falha = pausar contrato]

Regras:
  - Semanal: monthlyValue / 4.33
  - Quinzenal: monthlyValue / 2.16
  - Mensal: monthlyValue / 1
  - Max 3 retentativas por ciclo
  - Divisor biweekly 2.16 gera erro acumulado de 7.4%/ano

Status no codigo: Implementado mas DESATIVADO
Gap CRITICO: Cron de cobranca recorrente COMENTADO no ContractBootstrap:461
```

### FLUXO 9: COMUNICACAO (Transversal a todos os fluxos)

```
Objetivo: Notificar todos os atores em cada transicao de estado
Atores: Domain Events, MessageQueue, CustomerNotifier, ProfessionalNotifier
Canais: SMS (Twilio/NVoip), WhatsApp, Push (Firebase), Email

Eventos -> Mensagens:
  BriefingCreated       -> Customer: Email "Briefing iniciado"
  BriefingPhoneVerified -> Customer: SMS "Telefone verificado"
  ContractActivated     -> Professional: Push "Nova alocacao"
  ExecutionScheduled    -> Professional: Push "Servico amanha"
  ExecutionStarted      -> Customer: Push "Profissional chegando"
  ExecutionCompleted    -> Customer: Email "Avaliar servico?"
  FeedbackSubmitted     -> Professional: Email "Feedback recebido"
  PaymentCompleted      -> Customer: Email "Cobranca confirmada"
  PayoutCompleted       -> Professional: Email "Payout na sua conta"

Regras:
  - Retry: 3 tentativas (5s, 30s, 300s)
  - DND: 22:00-08:00 apenas Email
  - Audit trail: toda mensagem logada
  - Templates pre-aprovados no banco

Status no codigo: 40% implementado
Gap CRITICO: communicationProvider = NULL -> NENHUMA mensagem real e enviada
```

---

## PARTE 2: MAPA DE CADEIA OPERACIONAL (Dependencias entre fluxos)

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

**Links FUNCIONANDO:**
- Briefing -> Contract (via BriefingContractListener)
- Contract -> Offers (via autoSendOffers)
- Offers -> Accept -> Allocation

**Links QUEBRADOS (Fase 1 resolve):**
- Schedule -> Execution (sem trigger automatico)
- Feedback -> Payout (wiring incompleto)
- Payout -> Transfer (crons nao bootados)
- TODOS -> Communication (provider null)

---

## PARTE 3: FASE 0 - CORRECOES DE EMERGENCIA (COMPLETA)

Executada em 2026-02-18. Todos os 10 fixes aplicados e verificados.

| # | Fix | Arquivo | Status |
|---|-----|---------|--------|
| 1 | `number_format(null)` crash | `DashboardController.php:108` | CORRIGIDO |
| 2 | `DateTimeImmutable` type mismatch | `Professional.php:260` | CORRIGIDO |
| 3 | `formatRecipientType(null)` crash | `PayoutsPage.php:334` | CORRIGIDO |
| 4 | Constructor arg count ExecutePayout | `PayoutsPage.php:267` | CORRIGIDO |
| 5 | `curlRequest()` headers bug | `EfiPaymentProvider.php:278` | CORRIGIDO |
| 6 | Migrations expostas via HTTP | `run-*.php (5 arquivos)` | CORRIGIDO |
| 7 | AJAX nopriv sem nonce (Feedback) | `CustomerFeedbackPage.php:627` | CORRIGIDO |
| 8 | AJAX nopriv sem nonce (Briefing) | `CustomerBriefingPage.php:478` | CORRIGIDO |
| 9 | permission_callback string | `ProfessionalDocumentController.php` | CORRIGIDO |
| 10a | UserRoles::unregister no activation | `limpvix-core.php:169` | CORRIGIDO |
| 10b | $this->orderId inexistente | `Execution.php:161` | CORRIGIDO |

**Verificacao pos-Fase 0:**
- wp-load.php: OK (zero fatal errors)
- Migration HTTP access: 403 (bloqueado)
- Syntax check todos os arquivos: No errors detected

---

## PARTE 4: FASE 1 - CONECTAR CADEIA OPERACIONAL (PENDENTE)

### 1.1 Reconectar Schedule -> Execution (F4->F5)
- Implementar trigger automatico: quando Schedule e criada, criar Execution
- Arquivo: novo listener `ScheduleToExecutionListener`

### 1.2 Reconectar Feedback -> Payout (F7->F6)
- Garantir que FeedbackApproved dispara PayoutReconciliationService::approvePayout()
- Completar wiring existente

### 1.3 Boot Payout Crons (F6)
- Chamar `PayoutReconciliationService::registerCronHooks()` no boot
- Chamar `PayoutReconciliationService::scheduleCronJobs()` no boot

### 1.4 Ativar Comunicacao (F9)
- Resolver `communicationProvider = null`
- Injetar TwilioProvider/NVoipProvider como CommunicationProvider

### 1.5 Ativar Cobranca Recorrente (F8)
- Descomentar callback do cron em `ContractBootstrap.php:461`
- Verificar ChargeRecurringPayment UseCase funciona

### 1.6 Ativar Feedback REST API (F7)
- Descomentar registro da API REST em `FeedbackBootstrap`

### 1.7 Fix State Machines
- Adicionar 'expired' ao ContractStatus VO (F3)
- Fazer renew()/expire() usar ensureCanTransitionTo() (F3)
- Unificar ExecutionStatusEnum e ExecutionStatus (F5)

### 1.8 Purge Booknetic
- Remover $isBookneticActive e corrigir $allPluginsActive
- DROP 21 tabelas wp_bkntc_*
- rm -rf booknetic/ no container

### 1.9 Fix Settings/Bootstrap
- Corrigir nesting bug Twilio/Exato em AdminBootstrap:365-381
- Criar logError() no Kernel.php
- Fix submenus orfaos

---

## PARTE 5: FASE 2 - ADMIN UI PROFISSIONAL (PENDENTE)

### Reorganizacao de Menu por Fluxo Operacional:
```
LimpVix
+-- Dashboard (KPIs reais, alertas, quick actions)
+-- Operacional
|   +-- Briefings (F2)
|   +-- Contratos (F3)
|   +-- Agendamentos (F4)
|   +-- Execucoes [NOVA] (F5)
+-- Pessoas
|   +-- Profissionais (F1)
|   +-- Clientes
+-- Financeiro
|   +-- Orders (F6)
|   +-- Payouts (F6)
|   +-- Relatorio Financeiro
+-- Qualidade
|   +-- Feedback (F7)
|   +-- KYC/Documentos
+-- Comunicacao (F9)
|   +-- Templates
|   +-- Log de Mensagens
+-- Configuracoes
    +-- Geral
    +-- Integracoes
    +-- Cron Jobs
    +-- Seguranca
```

---

## RESUMO EXECUTIVO

| Metrica | Antes | Apos Fase 0 | Apos Fase 1 | Apos Fase 2 |
|---------|-------|-------------|-------------|-------------|
| Fatal Errors | 5 | 0 | 0 | 0 |
| Fluxos Funcionais E2E | 3/9 | 3/9 | 9/9 | 9/9 |
| Links Quebrados | 4 | 4 | 0 | 0 |
| Comunicacao | 0% | 0% | 100% | 100% |
| Score Geral | 72/100 | 80/100 | 92/100 | 98/100 |
| Admin UI | Desorganizada | Sem crashes | Organizada | Profissional |

**Ordem de execucao:** Fase 0 (COMPLETA) -> Fase 1 (1 semana) -> Fase 2 (2-3 semanas)
**Prioridade absoluta:** Reconectar os 4 links quebrados da cadeia operacional (Fase 1)
