# SPRINT PLAN - LimpVix Core Plugin
## v1.0 (2026-02-19)

> Plano consolidado de ajustes baseado em auditorias profundas:
> - 11-CONSOLIDATED-ENGINEERING-AUDIT-v2.0
> - GAPS-AJUSTES-FINAIS.md (17 gaps, 16 fechados)
> - FLUXOGRAMA-BRIEFING-TO-PAYOUT-v1.2 (45 gaps catalogados)
> - Deep audit: FluxosTab, DependenciasTab, ConexoesTab, CronTab
> - Code scan: TODO/FIXME/STUB em toda a codebase

---

## SCORE ATUAL: ~96% (Backend)

| Categoria | Score | Detalhe |
|-----------|-------|---------|
| Domain Layer | 98% | Aggregates, VOs, Enums completos |
| Application Layer | 95% | Use Cases funcionais, poucos TODOs |
| Infrastructure | 92% | Providers reais (EFI, PPID, Exato), falta webhook EFI |
| Security | 85% | Credenciais em plaintext, Firebase mock |
| Communication | 80% | Twilio+360Dialog reais, message queue incompleta |
| Admin UI | 95% | 4 tabs funcionais, FluxosTab desatualizada |
| Frontend | 10% | Wizard eh esqueleto (somente backend pronto) |

---

## SPRINT 1: SEGURANCA & FLUXOS CRITICOS
**Prioridade:** IMEDIATA (bloqueiam producao segura)
**Estimativa:** 5 itens

### S1.1 - Criptografia de Credenciais PPID
- **Arquivo:** `src/Admin/Settings/PPIDSettings.php:310`
- **Gap:** Senha PPID armazenada em plaintext no wp_options
- **Solucao:** Usar `openssl_encrypt/decrypt` com chave derivada de AUTH_KEY do wp-config.php
- **Impacto:** SEGURANCA CRITICA
- **Status:** PENDENTE

### S1.2 - Criptografia de Tokens OAuth MercadoPago
- **Arquivo:** `src/Infrastructure/API/ProfessionalOAuthController.php:497-498`
- **Gap:** access_token e refresh_token em plaintext na tabela limpvix_professionals
- **Solucao:** Mesma classe de criptografia do S1.1
- **Impacto:** SEGURANCA CRITICA
- **Status:** PENDENTE

### S1.3 - PaymentAuthorizationTimeout Real
- **Arquivo:** `src/Infrastructure/Cron/PaymentAuthorizationTimeoutCronAdapter.php:211,262`
- **Gap:** capturePayment() e cancelAuthorization() sao TODO/stub - nao chamam gateway real
- **Solucao:** Injetar WooCommerce payment gateway para capture/cancel. Para EFI Bank usar API direta.
- **Impacto:** FINANCEIRO CRITICO (pagamentos podem expirar sem captura)
- **Status:** PENDENTE

### S1.4 - Webhook EFI Bank PIX
- **Arquivo:** NOVO `src/Infrastructure/API/Controllers/EfiBankWebhookController.php`
- **Gap:** Nao existe endpoint webhook para receber notificacoes de status PIX da EFI Bank
- **Solucao:** Criar controller REST similar ao MercadoPagoWebhookController. Endpoint: `POST /limpvix/v1/webhooks/efi-bank`
- **Impacto:** FINANCEIRO CRITICO (status de payouts nao atualiza automaticamente)
- **Status:** PENDENTE

### S1.5 - Atualizar FluxosTab (Provider Status)
- **Arquivo:** `src/Admin/Settings/Tabs/FluxosTab.php`
- **Gap:** Dashboard mostra KYC e Background como STUB, mas foram implementados com providers reais
- **Solucao:** Atualizar textos de gaps e severity levels para refletir estado atual
- **Impacto:** BAIXO (visual, mas causa confusao ao admin)
- **Status:** PENDENTE

---

## SPRINT 2: COMUNICACAO & EVENTOS
**Prioridade:** ALTA (funcionalidades incompletas)
**Estimativa:** 5 itens

### S2.1 - Firebase Phone Verification Adapter
- **Arquivo:** `src/Application/UseCases/Briefing/VerifyBriefingPhone.php:80,129`
- **Gap:** Verificacao de telefone aceita qualquer token (mock). TODO Phase 3.
- **Solucao:** Criar FirebaseAuthAdapter que valida token via Firebase REST API `verifyPhoneNumber`
- **Impacto:** SEGURANCA ALTA (qualquer pessoa pode passar verificacao de telefone)
- **Status:** PENDENTE

### S2.2 - Document Event Dispatching
- **Arquivos:**
  - `src/Application/UseCases/Professional/ReviewDocument.php:47,82`
  - `src/Application/UseCases/Professional/UploadDocument.php:89`
- **Gap:** Eventos DocumentApproved, DocumentRejected, DocumentUploaded nao sao dispatched
- **Solucao:** Adicionar `do_action('limpvix_document_approved/rejected/uploaded', $data)` nos pontos marcados
- **Impacto:** MEDIO (notificacoes ao profissional nao disparam)
- **Status:** PENDENTE

### S2.3 - SendTemplatedMessage Injection
- **Arquivo:** `src/Core/SchedulingBootstrap.php:278`
- **Gap:** `$sendMessage = null; // TODO: Inject SendTemplatedMessage when available`
- **Solucao:** Instanciar e injetar SendTemplatedMessage no FeedbackReminderCronAdapter
- **Impacto:** ALTO (feedback reminders nao enviam mensagens reais)
- **Status:** PENDENTE

### S2.4 - ApproveManualPayout Notification
- **Arquivo:** `src/Application/UseCases/Financial/ApproveManualPayout.php:243`
- **Gap:** Profissional nao eh notificado quando payout manual eh aprovado
- **Solucao:** Adicionar envio SMS/Email ao profissional apos aprovacao
- **Impacto:** MEDIO (UX profissional degradada)
- **Status:** PENDENTE

### S2.5 - Message Queue Processor
- **Arquivos:** `src/Infrastructure/Persistence/WpMessageQueueRepository.php` + novo processor
- **Gap:** Infraestrutura de fila existe mas processor nao esta fully wired
- **Solucao:** Criar ProcessMessageQueueCronAdapter que processa mensagens pendentes
- **Impacto:** MEDIO (mensagens podem nao ser entregues)
- **Status:** PENDENTE

---

## SPRINT 3: INFRAESTRUTURA & ROBUSTEZ
**Prioridade:** MEDIA (qualidade e completude)
**Estimativa:** 5 itens

### S3.1 - RetryFailedPayment Contract Status
- **Arquivo:** `src/Application/UseCases/Finance/RetryFailedPayment.php:258`
- **Gap:** Quando retry falha 3x, contract status nao eh atualizado para payment_failed
- **Solucao:** Adicionar `$contract->markPaymentFailed()` apos max retries
- **Impacto:** MEDIO (contratos ficam em estado inconsistente)
- **Status:** PENDENTE

### S3.2 - ReallocateProfessional Eligibility
- **Arquivo:** `src/Application/UseCases/Contract/ReallocateProfessional.php:110`
- **Gap:** Eligibility checks incompletos (TODO: mais verificacoes)
- **Solucao:** Adicionar checks de: skills match, distancia maxima, disponibilidade horario
- **Impacto:** MEDIO (profissional inadequado pode ser alocado)
- **Status:** PENDENTE

### S3.3 - Schedule Repository Tolerance
- **Arquivo:** `src/Infrastructure/Persistence/WpScheduleRepository.php:300,374`
- **Gap:** Tolerance hardcoded 60min, allocation_score nao armazenado
- **Solucao:** Calcular tolerance baseado em duracao do servico; persistir allocation_score
- **Impacto:** BAIXO (otimizacao)
- **Status:** PENDENTE

### S3.4 - FrontendGuards Form + Audit
- **Arquivo:** `src/Frontend/FrontendGuards.php:113,185`
- **Gap:** Formulario nao implementado; audit logging nao persiste
- **Solucao:** Implementar form handler e persistir audit em tabela
- **Impacto:** BAIXO
- **Status:** PENDENTE

### S3.5 - Order Anomaly Detection
- **Arquivo:** `src/Admin/Controllers/OrderDetailController.php:118`
- **Gap:** `has_anomalies => false` hardcoded
- **Solucao:** Implementar deteccao de anomalias (duracao muito longa/curta, valores fora do esperado)
- **Impacto:** BAIXO (admin feature)
- **Status:** PENDENTE

---

## SPRINT 4: FRONTEND & EXPERIENCIA (FUTURO)
**Prioridade:** BAIXA (nao bloqueia go-live backend)
**Estimativa:** 3 itens

### S4.1 - Frontend Briefing Wizard
- **Arquivo:** `web-app/app/cliente/novo-briefing.tsx`
- **Gap:** Esqueleto (10% pronto). Backend 100% pronto com schema dinamico.
- **Solucao:** Rebuild completo consumindo `/limpvix/v1/briefing/schema`
- **Impacto:** CRITICO para UX (unico item que falta para go-live completo)
- **Status:** PENDENTE

### S4.2 - Push Notifications
- **Gap:** Nenhum provider de push implementado. Feature flag `notifications` = OFF.
- **Solucao:** Integrar Firebase Cloud Messaging (FCM)
- **Impacto:** MEDIO (UX mobile)
- **Status:** PENDENTE

### S4.3 - GeolocationAdapter Real
- **Gap:** Geocoding usa CEP hardmap local, sem Google Maps integration
- **Solucao:** Integrar Google Maps Geocoding API ou BrasilAPI
- **Impacto:** BAIXO (CEP resolve 95% dos casos)
- **Status:** PENDENTE

---

## RESUMO CONSOLIDADO

| Sprint | Itens | Prioridade | Impacto |
|--------|-------|------------|---------|
| Sprint 1 | 5 | IMEDIATA | Seguranca + Financeiro |
| Sprint 2 | 5 | ALTA | Comunicacao + Eventos |
| Sprint 3 | 5 | MEDIA | Qualidade + Robustez |
| Sprint 4 | 3 | BAIXA | Frontend + UX |
| **Total** | **18** | | |

### Score Projetado Apos Sprints:
- Apos Sprint 1: ~97% (seguranca resolvida)
- Apos Sprint 2: ~98% (comunicacao completa)
- Apos Sprint 3: ~99% (todos TODOs resolvidos)
- Apos Sprint 4: ~100% (frontend + push)

---

## HISTORICO

| Versao | Data | Descricao |
|--------|------|-----------|
| 1.0 | 2026-02-19 | Plano inicial: 4 sprints, 18 itens, baseado em auditoria profunda |
