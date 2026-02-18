# 07 - AUDITORIA COMPLETA: ADMIN UI & MENU ARCHITECTURE

**Data:** 2026-02-18
**Escopo:** Menu admin WordPress, abas, controllers, assets, settings e proposta de reorganizacao
**Arquivo principal auditado:** `src/Admin/Bootstrap/AdminBootstrap.php` (7.124 linhas)

---

## 1. RESUMO EXECUTIVO

O admin do limpvix-core cresceu organicamente ao longo de varios sprints, resultando numa estrutura funcional porem com divida tecnica significativa na camada UI. O arquivo AdminBootstrap.php concentra 7.124 linhas com logica de renderizacao HTML inline, tornando manutencao dificil. A pagina de Configuracoes (limpvix-settings) contem 13 abas -- um God Page que mistura concerns distintos. Paginas como ProfessionalManagementPage (3.660 linhas) tambem ultrapassam o limite saudavel.

### Pontos Fortes
- Sistema de capabilities (RBAC) bem definido com 11 capabilities distintas
- Nonce verification presente em todos os formularios criticos
- Feature Flags controlam ativacao de modulos via Kernel
- Settings classes (EFI, MP, Twilio, NVoip, Firebase, PPID) bem encapsuladas com AJAX handlers
- UI visualmente moderna com gradients, cards e grids CSS inline

### Problemas Criticos
- **God File:** AdminBootstrap.php = 7.124 linhas (render HTML + logica + SQL inline)
- **God Page:** limpvix-settings com 13 abas cobrindo concerns completamente distintos
- **Duplicacao de registro de menus:** FeedbackBootstrap e CommunicationBootstrap registram submenus sob parent `limpvix-orders` (slug inexistente como parent -- provavel orfao)
- **CSS inline massivo:** ~5.000 linhas de estilos inline dentro do PHP
- **Assets desorganizados:** 3 locais distintos (assets/, src/Admin/Settings/assets/, inline no PHP)
- **Nao ha separacao View/Controller:** HTML embutido diretamente nos metodos render*

---

## 2. MAPA ATUAL DO MENU ADMIN

### 2.1 Arvore de Menus Registrados

```
LimpVix (Position 30)                          [limpvix-finance]  [limpvix_finance_view]
|   Icon: dashicons-chart-line
|   Callback: AdminBootstrap::renderDashboardPage()
|   Controller: DashboardController
|
+-- Dashboard                                   [limpvix-finance]  [limpvix_finance_view]
|   (mesmo slug do parent - comportamento padrao WP)
|
+-- Orders                                      [limpvix-orders]  [limpvix_finance_view]
|   Callback: AdminBootstrap::renderOrdersPage()
|   Controller: OrdersListController
|
+-- Sync Validator                              [limpvix-sync-validator]  [limpvix_finance_view]
|   Callback: AdminBootstrap::renderSyncValidatorPage()
|   Controller: SyncValidatorController
|
+-- Relatorio Financeiro                        [limpvix-financial-report]  [limpvix_finance_view]
|   Callback: AdminBootstrap::renderFinancialReportPage()
|   Controller: FinancialReportController
|
+-- Briefings                                   [limpvix-briefings]  [manage_options]
|   Callback: AdminBootstrap::renderBriefingsPage()
|   Delegate: BriefingManagementPage::render()
|
+-- Feedback                                    [limpvix-feedback]  [limpvix_view_feedback]
|   Callback: AdminBootstrap::renderFeedbackManagementPage()
|   Delegate: FeedbackManagementPage::render()
|
+-- Documentos KYC                              [limpvix-document-review]  [manage_options]
|   Callback: AdminBootstrap::renderDocumentReviewPage()
|   Delegate: DocumentReviewPage::render()
|
+-- Configuracoes                               [limpvix-settings]  [limpvix_finance_manage]
|   Callback: AdminBootstrap::renderSettingsPage()
|   13 ABAS (detalhamento abaixo)
|
+-- Profissionais (*)                           [limpvix-professionals]  [manage_options]
|   Registrado via: ProfessionalBootstrap -> ProfessionalManagementPage::addMenu()
|   4 SUB-ABAS (detalhamento abaixo)
|
+-- Contratos (*)                               [limpvix-contracts]  [manage_options]
|   Registrado via: ContractManagementPage::addMenu() (via admin_menu hook)
|
+-- Clientes (*)                                [limpvix-customers]  [manage_options]
|   Registrado via: CustomersManagementPage::addMenu() (via admin_menu hook)
|
+-- Pacotes (*)                                 [limpvix-packages]  [manage_options]
|   Registrado via: PackageManagementPage::addMenu() (via admin_menu hook)
|
+-- Servicos (*)                                [limpvix-services]  [manage_options]
|   Registrado via: ServiceCatalogPage::addMenu() (via admin_menu hook)
|   2 abas internas: services | additionals

HIDDEN PAGES (parent = null):
+-- Detalhes da Order                           [limpvix-order-detail]  [limpvix_finance_view]
+-- Equipe LimpVix                              [limpvix-users]  [limpvix_manage_users]
    (redireciona para limpvix-settings&tab=limpvix-users)

ORPHAN SUBMENUS (parent = limpvix-orders -- NEM EXISTE COMO PARENT):
+-- Feedbacks (FeedbackBootstrap)               [limpvix-feedbacks]  [manage_options]
+-- Message Log (CommunicationBootstrap)        [limpvix-message-log]  [manage_options]
+-- Templates (CommunicationBootstrap)          [limpvix-message-templates]  [manage_options]
```

(*) = Registrados por classes separadas via add_action('admin_menu')

### 2.2 Problemas no Registro de Menus

| Problema | Descricao | Severidade |
|----------|-----------|------------|
| Orphan submenus | FeedbackBootstrap e CommunicationBootstrap registram sob `limpvix-orders` que nao e parent | ALTO |
| Capability inconsistente | Briefings e Contracts usam `manage_options`; Orders usa `limpvix_finance_view` | MEDIO |
| Menu item duplicado de Feedback | limpvix-feedback (AdminBootstrap) vs limpvix-feedbacks (FeedbackBootstrap) | ALTO |
| Debug error_log em producao | AdminBootstrap e ProfessionalManagementPage tem error_log extensivo no registerMenu | BAIXO |
| Parent slug hardcoded | `limpvix-finance` hardcoded em multiplos locais ao inves de constante compartilhada | MEDIO |

---

## 3. DETALHAMENTO DE CADA PAGINA E ABAS

### 3.1 Dashboard (limpvix-finance)

| Item | Valor |
|------|-------|
| Controller | DashboardController |
| Capability | limpvix_finance_view |
| Status | FUNCIONAL |
| Nonce | N/A (read-only) |
| Sanitizacao | N/A (read-only) |

**KPIs exibidos:**
- Orders Financeiras (total, pagas, autorizadas)
- Faturamento Total (total, taxa plataforma)
- Payouts (total, processados, pendentes)
- Profissionais ativos
- Appointments (hoje, semana)
- Health Score

**Problemas:** Controller faz SQL direto via `$wpdb` (nao usa Repository).

### 3.2 Orders (limpvix-orders)

| Item | Valor |
|------|-------|
| Controller | OrdersListController |
| Capability | limpvix_finance_view |
| Status | FUNCIONAL |
| Filtros | Status financeiro |
| Sanitizacao | sanitize_text_field em $_GET['status'] |

### 3.3 Order Detail (limpvix-order-detail)

| Item | Valor |
|------|-------|
| Controller | OrderDetailController |
| Capability | limpvix_finance_view |
| Parametro | ?uuid=XXX |
| Hidden | Sim (parent=null) |

### 3.4 Sync Validator (limpvix-sync-validator)

| Item | Valor |
|------|-------|
| Controller | SyncValidatorController |
| Capability | limpvix_finance_view |
| Status | FUNCIONAL (diagnostico read-only) |

### 3.5 Relatorio Financeiro (limpvix-financial-report)

| Item | Valor |
|------|-------|
| Controller | FinancialReportController |
| Capability | limpvix_finance_view |
| Filtros | Periodo (today/7d/30d/90d/custom) |
| Sanitizacao | sanitize_text_field para periodo e datas |

### 3.6 Briefings (limpvix-briefings)

| Item | Valor |
|------|-------|
| Page Class | BriefingManagementPage |
| Capability | manage_options |
| Status | FUNCIONAL |
| Features | Listagem, filtros (status/tipo/data), estatisticas, exportar CSV |

**Nota:** BriefingManagementPage tem `register()` que usa parent `limpvix` (outro parent diferente!), porem o menu e registrado via AdminBootstrap::renderBriefingsPage() diretamente.

### 3.7 Feedback (limpvix-feedback)

| Item | Valor |
|------|-------|
| Page Class | FeedbackManagementPage |
| Capability | limpvix_view_feedback |
| Status | FUNCIONAL |
| Secoes | c2 (feedback <=2 estrelas bloqueando payouts), reviews (todos pendentes) |
| Acoes POST | approve_feedback, reject_feedback |
| AJAX | limpvix_get_feedback_detail |

**Nota:** Existe duplicacao -- FeedbackBootstrap tambem registra `limpvix-feedbacks` como submenu.

### 3.8 Documentos KYC (limpvix-document-review)

| Item | Valor |
|------|-------|
| Page Class | DocumentReviewPage |
| Capability | manage_options |
| Status | FUNCIONAL |
| Features | Listagem, filtros (pending/expired/expiring_soon), aprovacao/rejeicao |

### 3.9 Profissionais (limpvix-professionals)

| Item | Valor |
|------|-------|
| Page Class | ProfessionalManagementPage (3.660 linhas) |
| Capability | manage_options |
| Registrado por | ProfessionalBootstrap::registerAdminPages() |
| Status | FUNCIONAL |

**Sub-abas internas:**

| Aba | Tab Param | Metodo Render | Status |
|-----|-----------|---------------|--------|
| Listagem | professionals | renderStatistics + renderProfessionalsTable | OK |
| KYC Biometrico | kyc | renderKycTab + renderKycDetails | OK |
| Risk Score | risk_score | renderRiskScoreTab | OK |
| Payouts | payouts | renderPayoutsTab | OK |

**Acoes disponiveis:** create, edit, verificar, suspender, enviar ofertas, KYC review.
**Nonce:** NONCE_ACTION = 'limpvix_professional_action' -- verificado.
**AJAX handlers:** Registrados via registerAjaxHandlers().

### 3.10 Contratos (limpvix-contracts)

| Item | Valor |
|------|-------|
| Page Class | ContractManagementPage (615 linhas) |
| Capability | manage_options |
| Status | FUNCIONAL |
| Nonce | Sim -- per-action (save/cancel/reactivate) |
| AJAX | limpvix_send_contract_offers (nonce verificado, cap verificado) |

### 3.11 Clientes (limpvix-customers)

| Item | Valor |
|------|-------|
| Page Class | CustomersManagementPage |
| Capability | manage_options |
| Status | FUNCIONAL (ONDA 2) |
| Views | list / view (detalhe por ID) |

### 3.12 Pacotes (limpvix-packages)

| Item | Valor |
|------|-------|
| Page Class | PackageManagementPage |
| Capability | manage_options |
| Status | FUNCIONAL |
| Acoes | create, edit, delete, toggle_status |
| Nonce | Sim -- per-action |

### 3.13 Servicos (limpvix-services)

| Item | Valor |
|------|-------|
| Page Class | ServiceCatalogPage |
| Capability | manage_options |
| Status | FUNCIONAL |
| Sub-abas | services / additionals |
| Nonce | Sim -- per-action |

---

## 4. CONFIGURACOES (limpvix-settings) -- 13 ABAS

A pagina de Configuracoes e o maior "God Page" do sistema. Todas as 13 abas sao renderizadas por metodos privados do AdminBootstrap.php.

### 4.1 Mapa de Abas

| # | Tab Slug | Titulo | Metodo Render | Linhas ~aprox | Status |
|---|----------|--------|---------------|---------------|--------|
| 1 | geral | Geral | renderGeralTab() | ~600 | OK |
| 2 | conexoes | Conexoes | renderConexoesTab() | ~480 | OK |
| 3 | comunicacao | Comunicacao | renderComunicacaoTab() | ~330 | OK |
| 4 | briefing | Briefing | renderBriefingTab() | ~120 | OK |
| 5 | profissionais | Profissionais | renderProfissionaisTab() | ~1500 | OK |
| 6 | templates | Templates | renderTemplatesTab() | delegate | OK |
| 7 | fluxos | Fluxos | renderFluxosTab() | ~520 | OK |
| 8 | pagamentos | Pagamentos | renderPagamentosTab() | ~420 | OK |
| 9 | cron | Cron & Acoes | renderCronTab() | ~500 | OK |
| 10 | dependencias | Dependencias | renderDependenciasTab() | ~880 | OK |
| 11 | risk | Risk | renderRiskTab() | ~85 | OK |
| 12 | feedback-management | Feedback C2 | renderFeedbackManagementTab() | delegate | OK |
| 13 | limpvix-users | Equipe | renderUsersTab() | delegate | OK |

### 4.2 Detalhamento por Aba

**Geral (geral):**
- Feature Flags toggle (core_enabled, briefing_enabled, financial_workflow, etc.)
- Dashboard de status do sistema com metricas dinamicas
- GAPs implementados (P0/P1)
- Fluxos operacionais completos
- Processamento: POST com nonce `limpvix_save_feature_flags`

**Conexoes (conexoes):**
- Status de 6 integradores: Firebase, Google Business, PPID KYC, Exato Digital, Twilio, NVoip
- Formularios de configuracao para cada provider (delegam para Settings classes)
- Deteccao de OTP provider ativo
- Processamento: Twilio via TwilioSettings::save(), Exato via POST direto

**Comunicacao (comunicacao):**
- Status NVoip e Twilio como dual provider
- Fluxos automaticos (C1-C3, P1-P3)
- Fallback automatico entre providers
- Read-only (configuracao real fica em Conexoes)

**Briefing (briefing):**
- Tabela m2 por comodo
- Fatores de tempo por tipo de limpeza
- Buffer operacional, preco por m2
- Processamento: POST com nonce `limpvix_briefing_settings`

**Profissionais (profissionais):**
- Requisitos KYC (ID verification, background check)
- Score thresholds
- Area de cobertura
- Configuracoes de disponibilidade

**Templates (templates):**
- Delega para MessageTemplatesAdminPage::render()
- Gerenciamento de templates de mensagem

**Fluxos (fluxos):**
- Toggle de fluxos C1-C3 (cliente) e P1-P3 (equipe)
- Timing configuravel (C1: 3 tentativas com intervalos)
- Status operacional dinamico
- Processamento: admin_post_limpvix_update_flows

**Pagamentos (pagamentos):**
- EFI Bank como primario, MercadoPago como fallback
- Estatisticas de payouts (total, pendente, processando, falhas)
- Configuracao de credenciais EFI e MP
- Delega renderizacao para EfiBankSettings e MercadoPagoSettings

**Cron & Acoes (cron):**
- Monitor dinamico de todos os cron jobs limpvix_*
- Status por job (healthy/warning/overdue)
- Metadata com descricoes amigaveis para cada hook

**Dependencias (dependencias):**
- Plugins requeridos (WooCommerce, MercadoPago)
- Scorecard de componentes (Bridge, Mapper, Guards, UI, Finance, Comms)
- Ambiente (PHP, MySQL, WP versions)
- Go-Live readiness check

**Risk (risk):**
- Status PPID e Exato Digital
- Policy Engine: categorias de antecedentes que geram UNDER_REVIEW
- Bloqueadores imutaveis (SEXUAL_CRIME, VIOLENT_CRIME)
- Processamento: POST com nonce `limpvix_risk_settings`

**Feedback C2 (feedback-management):**
- Delega para FeedbackManagementPage::renderTabContent()
- Requires: limpvix_view_feedback

**Equipe (limpvix-users):**
- Delega para LimpVixUsersPage::renderTabContent()
- Gestao de equipe interna (Gerente Nacional/Estadual/Regional, Financeiro)
- Requires: limpvix_manage_users

### 4.3 Bug Encontrado no renderSettingsPage()

Na linha 365-381 do AdminBootstrap.php, ha um **if aninhado incorreto** -- os blocos de processamento de Twilio e Exato estao DENTRO do bloco de briefing:

```php
if ($activeTab === 'briefing' && isset($_POST['limpvix_save_briefing_settings'])) {
    $this->handleBriefingSave();

    // ERRO: Estes ifs estao DENTRO do bloco de briefing (falta fechar })
    if ($activeTab === 'conexoes' && isset($_POST['limpvix_save_twilio_settings'])) {
        \LimpVix\Admin\Settings\TwilioSettings::save();
    }

    if ($activeTab === 'conexoes' && isset($_POST['limpvix_save_exato_settings'])) {
        // ...
    }
} // <-- fecha o bloco de briefing
```

**Impacto:** Os saves de Twilio e Exato NUNCA serao executados pois `$activeTab` nao pode ser simultaneamente `briefing` e `conexoes`. Isso e um bug de escopo.

---

## 5. AUDIT DE ASSETS (CSS/JS)

### 5.1 Localizacoes de Assets

| Local | Arquivos | Uso |
|-------|----------|-----|
| `assets/css/limpvix-admin.css` | 356 linhas | CSS legacy, enqueued globalmente em paginas limpvix |
| `assets/css/limpvix-admin-modern.css` | 679 linhas | CSS moderno, enqueued globalmente |
| `assets/finance.css` | 45 linhas | CSS financeiro (NAO enqueued -- possivelmente orfao) |
| `assets/finance.js` | 12 linhas | JS financeiro (NAO enqueued -- possivelmente orfao) |
| `assets/js/message-templates.js` | ? | Enqueued como limpvix-templates com localize limpvixAdmin |
| `assets/js/admin/send-offers.js` | ? | Enqueued por ContractManagementPage |
| `assets/js/modules/address-autofill.js` | ? | Modulo de autofill de endereco |
| `src/Admin/Settings/assets/css/limpvix-mp.css` | ? | Inline load por MercadoPagoSettings |
| `src/Admin/Settings/assets/js/limpvix-mp.js` | ? | Inline load por MercadoPagoSettings |

### 5.2 Enqueue de Assets

**AdminBootstrap::registerAssets()** (global para hook contendo 'limpvix'):
- `limpvix-admin-modern` (CSS v1.1.0)
- `limpvix-admin` (CSS v1.0.0)
- `limpvix-templates` (JS v1.0.0, dep: jquery)
- Localiza `limpvixAdmin` (ajaxUrl, nonce, capabilities)

**ProfessionalManagementPage::enqueueAssets()** (condicional):
- `limpvix-professionals` CSS (se arquivo existir)
- `limpvix-professionals` JS (se arquivo existir)
- Localiza `limpvixProfessionals`

**ContractManagementPage::enqueueScripts()** (condicional):
- `limpvix-send-offers` JS
- Localiza `limpvixContracts`

**MercadoPagoSettings** (inline via PHP echo):
- `limpvix-mp.css` (inline link tag)
- `limpvix-mp.js` (inline script tag)
- `limpvixMPData` (inline JS var)

### 5.3 Problemas de Assets

| Problema | Descricao | Severidade |
|----------|-----------|------------|
| CSS inline massivo | ~5.000+ linhas de style="..." no AdminBootstrap | ALTO |
| Assets orfaos | finance.css e finance.js nao parecem ser enqueued | BAIXO |
| Assets em src/ | limpvix-mp.css e .js estao em src/Admin/Settings/assets/ (devem estar em assets/) | MEDIO |
| Inline loading | MP Settings carrega CSS/JS via echo inline (nao via wp_enqueue) | MEDIO |
| Sem versionamento por hash | Todos usam versao estatica, nao hash de conteudo | BAIXO |
| Sem minificacao | CSS/JS nao sao minificados | BAIXO |
| Booknetic assets | Nenhum asset do Booknetic encontrado (OK -- removido corretamente) | N/A |

---

## 6. AUDIT DE CONTROLLERS

### 6.1 src/Admin/Controllers/

| Controller | Linhas | Responsibility | Nonce | Cap Check | Sanitize | Escape |
|-----------|--------|---------------|-------|-----------|----------|--------|
| DashboardController | ~150 | Dashboard KPIs | N/A | canView() | N/A | Parcial (number_format) |
| OrdersListController | ~200 | Lista de orders | N/A | canView() | sanitize_text_field | Parcial |
| OrderDetailController | ~150 | Detalhe de order | N/A | canView() | sanitize_text_field | Parcial |
| FinancialReportController | ~200 | Relatorio financeiro | N/A | canView() | sanitize_text_field | Parcial |
| SyncValidatorController | ~200 | WC vs LV sync check | N/A | canView() | N/A | Parcial |
| AdminActionsController | ~300 | AJAX actions | Sim | Per-action | Sim | JSON response |

### 6.2 AJAX Handlers (AdminActionsController)

| Handler | Cap Required | Nonce |
|---------|-------------|-------|
| limpvix_block_order | limpvix_finance_manage | validateRequest() |
| limpvix_unblock_order | limpvix_finance_manage | validateRequest() |
| limpvix_manual_authorize | limpvix_finance_manage | validateRequest() |
| limpvix_execute_payout | limpvix_finance_payout | validateRequest() |
| limpvix_refund_order | limpvix_finance_payout | validateRequest() |
| limpvix_mark_pix_paid | validateRequest() | validateRequest() |
| limpvix_resolve_feedback_and_payout | validateRequest() | validateRequest() |
| limpvix_authorize_payout | validateRequest() | validateRequest() |

### 6.3 Outras AJAX Handlers

| Classe | Actions | Nonce | Cap |
|--------|---------|-------|-----|
| EfiBankSettings | efi_save_settings, efi_test_connection, efi_check_wc_plugin, efi_sync_wc_credentials, efi_upload_cert | Sim | manage_options |
| MercadoPagoSettings | mp_toggle_environment, mp_check_sync, mp_test_connection, mp_manual_sync | Sim | manage_options |
| FirebaseSettings | firebase_test_connection, firebase_save | Sim | manage_options |
| NVoipSettings | nvoip_save, nvoip_test | Sim | manage_options |
| TwilioSettings | test_otp_send | check_ajax_referer | manage_options |
| FeedbackManagementPage | get_feedback_detail, approve_feedback, reject_feedback | Sim | limpvix_view_feedback |
| ManualPayoutAjaxHandler | (registrado condicionalmente) | Sim | manage_options |
| ContractManagementPage | send_contract_offers | Sim | manage_options |

---

## 7. AUDIT DE SETTINGS

### 7.1 Quadro de Settings

| Classe | Onde Aparece | Salva | Validacao | Nonce | Status |
|--------|-------------|-------|-----------|-------|--------|
| MercadoPagoSettings | Aba Pagamentos | Via AJAX | Sim | Sim | OK |
| MercadoPagoDetector | Background sync | Via hooks | N/A | N/A | OK |
| EfiBankSettings | Aba Pagamentos | Via AJAX | Sim | Sim | OK |
| TwilioSettings | Aba Conexoes | Via POST | Parcial | Parcial | BUG (save inalcancavel) |
| NVoipSettings | Aba Conexoes | Via AJAX | Sim | Sim | OK |
| FirebaseSettings | Aba Conexoes | Via AJAX | Sim | Sim | OK |
| GoogleBusinessSettings | Aba Conexoes | Via option | Sim | Sim | OK |
| PPIDSettings | Aba Conexoes | Via options.php | settings_fields | Sim | OK |
| DialogSettings | NAO APARECE (deprecated?) | saveSettings() | sanitize_text_field | N/A | ORFAO? |
| TestVendorsManager | DESABILITADO | N/A | N/A | N/A | Inativo |

### 7.2 Bug no Save de TwilioSettings

Conforme documentado na secao 4.3, o bloco de save do Twilio esta aninhado incorretamente dentro do bloco de save do Briefing. O save NUNCA sera executado via formulario web. Apenas o AJAX handler `handleTestOtpSend` funciona.

---

## 8. DASHBOARD WIDGETS

### 8.1 Widgets Existentes

| Widget | Classe | Registro | Status |
|--------|--------|----------|--------|
| Cron Jobs Status | CronHealthWidget | wp_dashboard_setup | OK |
| Feedback Window Monitor (24h) | FeedbackWindowMonitorWidget | wp_dashboard_setup | OK |

### 8.2 KPIs Implementados vs Necessarios

| KPI | Dashboard Principal | Widget | Status |
|-----|-------------------|--------|--------|
| Orders total/pagas/autorizadas | Sim | Nao | OK |
| Faturamento total | Sim | Nao | OK |
| Payouts total/pendentes | Sim | Nao | OK |
| Profissionais ativos | Sim | Nao | OK |
| Health Score | Sim | Nao | OK |
| Cron jobs health | Nao | Sim | OK |
| Feedback windows ativas | Nao | Sim | OK |
| Contratos ativos/expirando | Nao | Nao | AUSENTE |
| Execucoes de hoje | Nao | Nao | AUSENTE |
| Revenue por periodo (grafico) | Nao | Nao | AUSENTE |
| Briefings pendentes | Nao | Nao | AUSENTE |
| NPS / Score medio | Nao | Nao | AUSENTE |
| SLA violations | Nao | Nao | AUSENTE |

---

## 9. PROPOSTA DE REORGANIZACAO

### 9.1 Principios da Reorganizacao

1. **Separacao de concerns:** Cada item de menu deve corresponder a uma entidade de dominio
2. **Hierarquia intuitiva:** Fluxo operacional Briefing -> Contrato -> Execucao -> Pagamento
3. **Settings enxuto:** Apenas configuracoes reais, nao dashboards de status
4. **View layer separado:** Templates PHP em diretorio dedicado (views/)
5. **Capabilities consistentes:** Usar capabilities LimpVix em vez de manage_options

### 9.2 Estrutura Proposta

```
LimpVix (Dashboard com KPIs)                    [limpvix-dashboard]    [limpvix_finance_view]
|   Controller: DashboardController (refatorado com KPIs completos)
|   Status: EXISTE (refatorar)
|   Prioridade: P1
|
+-- Briefings                                    [limpvix-briefings]    [limpvix_manage_briefings]
|   |   Lista + novo + detalhes
|   |   Controller: BriefingManagementPage (refatorado)
|   |   Status: EXISTE
|   |   Prioridade: P1
|   |
|   +-- Detalhe do Briefing (hidden)             [limpvix-briefing-detail]  [limpvix_manage_briefings]
|
+-- Contratos                                    [limpvix-contracts]    [limpvix_manage_contracts]
|   |   Lista + CRUD + renovacao + enviar ofertas
|   |   Controller: ContractManagementPage (refatorado)
|   |   Status: EXISTE
|   |   Prioridade: P1
|
+-- Execucoes                                    [limpvix-executions]   [limpvix_manage_executions]
|   |   Lista + timeline + check-in/out + evidencias
|   |   Controller: ExecutionManagementPage (NOVO)
|   |   Status: AUSENTE (dados existem via ExecutionBootstrap)
|   |   Prioridade: P0
|
+-- Profissionais                                [limpvix-professionals]  [limpvix_manage_professionals]
|   |   Lista + perfil + KYC + score + payouts
|   |   Controller: ProfessionalManagementPage (refatorado, extrair views)
|   |   Sub-abas: Listagem | KYC | Risk Score | Payouts
|   |   Status: EXISTE
|   |   Prioridade: P1
|
+-- Clientes                                     [limpvix-customers]    [limpvix_view_customers]
|   |   Lista + perfil + historico
|   |   Controller: CustomersManagementPage (existente)
|   |   Status: EXISTE
|   |   Prioridade: P2
|
+-- Financeiro                                   [limpvix-finance]      [limpvix_finance_view]
|   |   Submenu agrupador
|   |   Prioridade: P1
|   |
|   +-- Orders                                   [limpvix-orders]       [limpvix_finance_view]
|   |   Controller: OrdersListController
|   |   Status: EXISTE
|   |
|   +-- Payouts                                  [limpvix-payouts]      [limpvix_view_payouts]
|   |   Controller: PayoutsPage (promover a pagina standalone)
|   |   Status: PARCIAL (embutido em Profissionais)
|   |
|   +-- Relatorio Financeiro                     [limpvix-financial-report]  [limpvix_finance_view]
|   |   Controller: FinancialReportController
|   |   Status: EXISTE
|   |
|   +-- Ledger                                   [limpvix-ledger]       [limpvix_finance_view]
|       Controller: LedgerController (NOVO)
|       Status: AUSENTE (LedgerEntry existe no dominio)
|       Prioridade: P2
|
+-- Qualidade                                    [limpvix-quality]      [limpvix_view_feedback]
|   |   Submenu agrupador
|   |
|   +-- Feedback                                 [limpvix-feedback]     [limpvix_view_feedback]
|   |   Controller: FeedbackManagementPage
|   |   Status: EXISTE
|   |
|   +-- Documentos KYC                           [limpvix-document-review]  [limpvix_review_evidence]
|       Controller: DocumentReviewPage
|       Status: EXISTE
|
+-- Comunicacao                                  [limpvix-communication]  [limpvix_manage_settings]
|   |
|   +-- Templates                                [limpvix-templates]    [limpvix_manage_settings]
|   |   Controller: MessageTemplatesAdminPage
|   |   Status: EXISTE
|   |
|   +-- Fluxos                                   [limpvix-flows]        [limpvix_manage_settings]
|   |   Controller: FlowsController (extrair de AdminBootstrap)
|   |   Status: PARCIAL (tab em Settings)
|   |
|   +-- Message Log                              [limpvix-message-log]  [limpvix_manage_settings]
|       Status: EXISTE (orfao, re-parentear)
|
+-- Catalogo                                     [limpvix-catalog]      [manage_options]
|   |
|   +-- Servicos                                 [limpvix-services]     [manage_options]
|   |   Controller: ServiceCatalogPage
|   |   Status: EXISTE
|   |
|   +-- Pacotes                                  [limpvix-packages]     [manage_options]
|       Controller: PackageManagementPage
|       Status: EXISTE
|
+-- Configuracoes                                [limpvix-settings]     [limpvix_manage_settings]
    |   APENAS configuracoes, nao dashboards
    |   Prioridade: P1
    |
    +-- Geral (feature flags, parametros briefing)
    +-- Integracoes (Twilio, NVoip, Firebase, PPID, Exato, EFI, MP, Google Business)
    +-- Taxas & Fees (platform fee, profissionais config)
    +-- Risk & Policy Engine
    +-- Equipe LimpVix
    +-- Cron Jobs Monitor
    +-- Diagnostico (sync validator, dependencias)
```

### 9.3 Detalhamento da Proposta

| Menu Proposto | Slug WP | Cap Required | Controller | Status Atual | Prioridade |
|--------------|---------|-------------|------------|-------------|------------|
| Dashboard | limpvix-dashboard | limpvix_finance_view | DashboardController | EXISTE (refatorar) | P1 |
| Briefings | limpvix-briefings | limpvix_manage_briefings | BriefingManagementPage | EXISTE | P1 |
| Contratos | limpvix-contracts | limpvix_manage_contracts | ContractManagementPage | EXISTE | P1 |
| Execucoes | limpvix-executions | limpvix_manage_executions | ExecutionManagementPage | **AUSENTE** | **P0** |
| Profissionais | limpvix-professionals | limpvix_manage_professionals | ProfessionalManagementPage | EXISTE | P1 |
| Clientes | limpvix-customers | limpvix_view_customers | CustomersManagementPage | EXISTE | P2 |
| Orders | limpvix-orders | limpvix_finance_view | OrdersListController | EXISTE | P1 |
| Payouts | limpvix-payouts | limpvix_view_payouts | PayoutsPage | PARCIAL | P1 |
| Rel. Financeiro | limpvix-financial-report | limpvix_finance_view | FinancialReportController | EXISTE | P2 |
| Ledger | limpvix-ledger | limpvix_finance_view | LedgerController | **AUSENTE** | P2 |
| Feedback | limpvix-feedback | limpvix_view_feedback | FeedbackManagementPage | EXISTE | P1 |
| Doc KYC | limpvix-document-review | limpvix_review_evidence | DocumentReviewPage | EXISTE | P1 |
| Templates | limpvix-templates | limpvix_manage_settings | MessageTemplatesAdminPage | EXISTE | P2 |
| Fluxos | limpvix-flows | limpvix_manage_settings | FlowsController | PARCIAL (extrair) | P2 |
| Message Log | limpvix-message-log | limpvix_manage_settings | (existente) | EXISTE (re-parent) | P3 |
| Servicos | limpvix-services | manage_options | ServiceCatalogPage | EXISTE | P2 |
| Pacotes | limpvix-packages | manage_options | PackageManagementPage | EXISTE | P2 |
| Configuracoes | limpvix-settings | limpvix_manage_settings | SettingsController | EXISTE (refatorar) | P1 |

### 9.4 Novas Capabilities Propostas

| Capability | Descricao |
|-----------|-----------|
| limpvix_manage_briefings | Criar/editar briefings (substitui manage_options) |
| limpvix_manage_contracts | Gerenciar contratos (substitui manage_options) |
| limpvix_manage_executions | Gerenciar execucoes |
| limpvix_manage_professionals | Gerenciar profissionais (substitui manage_options) |
| limpvix_view_customers | Ver clientes |

---

## 10. WIREFRAMES ASCII DAS PAGINAS PRINCIPAIS

### 10.1 Dashboard Refatorado

```
+----------------------------------------------------------------------+
| LIMPVIX DASHBOARD                                    [Hoje: 18/02]   |
+----------------------------------------------------------------------+
|                                                                      |
| +-------------+ +-------------+ +-------------+ +-------------+     |
| | Briefings   | | Contratos   | | Execucoes   | | Receita     |     |
| | Pendentes   | | Ativos      | | Hoje        | | Mes         |     |
| |     12      | |     47      | |      8      | | R$ 45.230   |     |
| |   +3 hoje   | |  -2 semana  | |  2 concl.   | |  +12% vs m  |     |
| +-------------+ +-------------+ +-------------+ +-------------+     |
|                                                                      |
| +-----------------------------------+ +----------------------------+ |
| | TIMELINE DE EXECUCOES HOJE        | | ALERTAS                    | |
| |                                   | |                            | |
| | 08:00 - Ana S. -> Rua X #123     | | ! 3 payouts pendentes      | |
| |   [Em andamento] Check-in 08:05  | | ! 1 feedback C2 bloqueante | |
| | 09:30 - Pedro M. -> Av Y #456    | | ! Contrato #47 expirando   | |
| |   [Agendado] Aguardando          | | ! Cron sync_payouts atras  | |
| | 11:00 - Maria J. -> Rua Z #789   | |                            | |
| |   [Concluido] 10:45              | +----------------------------+ |
| |                                   |                                |
| +-----------------------------------+ +----------------------------+ |
|                                       | PROFISSIONAIS ATIVOS        | |
| +-----------------------------------+ |                            | |
| | RECEITA SEMANAL (GRAFICO)         | | 23 ativos / 5 disponiveis | |
| |                                   | | Score medio: 4.2          | |
| | ######                            | | 2 KYC pendente            | |
| | ######  ###                       | +----------------------------+ |
| | ######  ###  ##                   |                                |
| | Seg  Ter  Qua  Qui  Sex          |                                |
| +-----------------------------------+                                |
+----------------------------------------------------------------------+
```

### 10.2 Pagina de Execucoes (NOVA - P0)

```
+----------------------------------------------------------------------+
| EXECUCOES                               [+ Nova Execucao] [Filtros]  |
+----------------------------------------------------------------------+
| Status: [Todos v] Profissional: [Todos v] Data: [Hoje v] [Buscar]   |
+----------------------------------------------------------------------+
|                                                                      |
| +-------------+ +-------------+ +-------------+ +-------------+     |
| | Agendadas   | | Em Andamento| | Concluidas  | | No-Shows    |     |
| |     15      | |      3      | |     42      | |      1      |     |
| +-------------+ +-------------+ +-------------+ +-------------+     |
|                                                                      |
| +------------------------------------------------------------------+ |
| | ID   | Contrato | Profissional | Cliente      | Data     | Stat | |
| |------|----------|-------------|--------------|----------|------| |
| | E-89 | C-47     | Ana Silva   | Joao Souza   | 18/02 08h| Em And| |
| | E-88 | C-45     | Pedro M.    | Maria Lima   | 18/02 09h| Agend | |
| | E-87 | C-43     | Julia C.    | Carlos R.    | 17/02 14h| Concl | |
| +------------------------------------------------------------------+ |
|                                                                      |
| [< Anterior]  Pagina 1 de 5  [Proximo >]                           |
+----------------------------------------------------------------------+
```

### 10.3 Configuracoes Reorganizadas

```
+----------------------------------------------------------------------+
| CONFIGURACOES LIMPVIX                                                |
+----------------------------------------------------------------------+
| [Geral] [Integracoes] [Taxas] [Risk] [Equipe] [Cron] [Diagnostico] |
+----------------------------------------------------------------------+
|                                                                      |
| ABA: INTEGRACOES                                                     |
|                                                                      |
| +----------------------------+ +----------------------------+        |
| | EFI Bank (Primario)        | | MercadoPago (Fallback)     |        |
| | Status: Conectado          | | Status: Conectado          |        |
| | Ambiente: Producao         | | Ambiente: Producao         |        |
| | [Configurar] [Testar]      | | [Configurar] [Testar]      |        |
| +----------------------------+ +----------------------------+        |
|                                                                      |
| +----------------------------+ +----------------------------+        |
| | Twilio (SMS/OTP)           | | NVoip (WhatsApp/SMS)       |        |
| | Status: Ativo              | | Status: Ativo              |        |
| | [Configurar] [Testar OTP]  | | [Configurar] [Testar]      |        |
| +----------------------------+ +----------------------------+        |
|                                                                      |
| +----------------------------+ +----------------------------+        |
| | Firebase Auth              | | PPID KYC                   |        |
| | Status: Pendente           | | Status: Pendente           |        |
| | [Configurar]               | | [Configurar]               |        |
| +----------------------------+ +----------------------------+        |
|                                                                      |
| +----------------------------+ +----------------------------+        |
| | Google Business            | | Exato Digital              |        |
| | Status: Pendente           | | Status: Pendente           |        |
| | [Configurar]               | | [Configurar]               |        |
| +----------------------------+ +----------------------------+        |
+----------------------------------------------------------------------+
```

---

## 11. ESTIMATIVA DE ESFORCO

### 11.1 Refatoracao Imediata (Sprint atual)

| Tarefa | Esforco | Prioridade |
|--------|---------|------------|
| Corrigir bug de aninhamento no renderSettingsPage (Twilio/Exato save) | 0.5h | P0 |
| Remover error_log de debug do registerMenu e addMenu | 0.5h | P0 |
| Re-parentear orphan submenus (FeedbackBootstrap, CommunicationBootstrap) | 1h | P0 |
| Remover registro duplicado de Feedback menu | 0.5h | P0 |
| **Subtotal** | **2.5h** | |

### 11.2 Fase 1 -- Extrair Views do AdminBootstrap (Sprint N+1)

| Tarefa | Esforco | Prioridade |
|--------|---------|------------|
| Criar diretorio src/Admin/Views/ e templates parciais | 2h | P1 |
| Extrair renderGeralTab para view template | 3h | P1 |
| Extrair renderConexoesTab para view template | 3h | P1 |
| Extrair renderComunicacaoTab para view template | 2h | P1 |
| Extrair renderBriefingTab para view template | 1h | P1 |
| Extrair renderProfissionaisTab para view template | 4h | P1 |
| Extrair renderFluxosTab para view template | 3h | P1 |
| Extrair renderPagamentosTab para view template | 3h | P1 |
| Extrair renderCronTab para view template | 3h | P1 |
| Extrair renderDependenciasTab para view template | 4h | P1 |
| Extrair renderRiskTab para view template | 1h | P1 |
| Mover CSS inline para limpvix-admin-modern.css | 8h | P1 |
| **Subtotal** | **37h (~5 dias)** | |

### 11.3 Fase 2 -- Reorganizacao de Menu (Sprint N+2)

| Tarefa | Esforco | Prioridade |
|--------|---------|------------|
| Criar ExecutionManagementPage (NOVA) | 16h | P0 |
| Refatorar menu principal (nova hierarquia) | 4h | P1 |
| Promover Payouts a pagina standalone | 3h | P1 |
| Mover Fluxos de Settings para pagina propria | 4h | P2 |
| Criar LedgerController (NOVO) | 8h | P2 |
| Refatorar Settings para 7 abas (de 13) | 6h | P1 |
| Unificar parent slug via constante compartilhada | 2h | P1 |
| Novas capabilities + migracao | 3h | P1 |
| **Subtotal** | **46h (~6 dias)** | |

### 11.4 Fase 3 -- Dashboard e Widgets (Sprint N+3)

| Tarefa | Esforco | Prioridade |
|--------|---------|------------|
| Refatorar DashboardController com KPIs completos | 8h | P1 |
| Widget: Contratos expirando | 3h | P2 |
| Widget: Execucoes de hoje | 3h | P1 |
| Widget: Revenue chart (periodo) | 6h | P2 |
| Widget: NPS / Score medio | 3h | P2 |
| Widget: SLA violations | 3h | P2 |
| **Subtotal** | **26h (~3.5 dias)** | |

### Total Estimado: ~111h (~14 dias de trabalho)

---

## 12. PRIORIDADES ALINHADAS COM FLUXOS OPERACIONAIS

### Fluxo operacional principal:
```
Briefing -> Contrato -> Alocacao -> Execucao -> Check-in/out -> Feedback -> Payout
```

### Mapeamento de prioridades por fluxo:

| Etapa do Fluxo | Pagina Admin | Status | Prioridade |
|----------------|-------------|--------|------------|
| 1. Briefing | limpvix-briefings | EXISTE | P1 |
| 2. Contrato | limpvix-contracts | EXISTE | P1 |
| 3. Alocacao | (embutido em contratos) | PARCIAL | P1 |
| 4. **Execucao** | **AUSENTE** | **AUSENTE** | **P0** |
| 5. Check-in/out | (embutido em execucao) | AUSENTE | P0 |
| 6. Feedback | limpvix-feedback | EXISTE | P1 |
| 7. Payout | (embutido em profissionais) | PARCIAL | P1 |
| 8. Relatorio | limpvix-financial-report | EXISTE | P2 |

**A maior lacuna operacional e a ausencia de uma pagina dedicada para Execucoes.** Embora o dominio (ContractExecution, ExecutionStatus, Evidence, CheckIn/CheckOut) esteja completamente modelado no DDD, nao ha interface admin para que o operador acompanhe execucoes em tempo real, valide evidencias, ou gerencie no-shows.

### Ordem de execucao recomendada:

1. **IMEDIATO (P0):** Corrigir bugs (nesting, orphan menus, debug logs)
2. **Sprint N+1 (P0):** Criar ExecutionManagementPage
3. **Sprint N+1 (P1):** Extrair views do AdminBootstrap (reduzir 7.124 linhas)
4. **Sprint N+2 (P1):** Reorganizar menu, promover Payouts, refatorar Settings
5. **Sprint N+3 (P2):** Dashboard com KPIs completos, novos widgets, Ledger

---

## APENDICE A: LISTA COMPLETA DE ARQUIVOS ADMIN

### Controllers (src/Admin/Controllers/)
- `AdminActionsController.php` -- AJAX actions (block, payout, refund)
- `DashboardController.php` -- Dashboard KPIs
- `FinancialReportController.php` -- Relatorio financeiro
- `OrderDetailController.php` -- Detalhe de order
- `OrdersListController.php` -- Lista de orders
- `SyncValidatorController.php` -- Validacao WC vs LV

### Settings (src/Admin/Settings/)
- `DialogSettings.php` -- 360Dialog WhatsApp (possivelmente orfao)
- `EfiBankSettings.php` -- EFI Bank PIX
- `FirebaseSettings.php` -- Firebase Auth
- `GoogleBusinessSettings.php` -- Google Business
- `MercadoPagoDetector.php` -- Deteccao automatica MP
- `MercadoPagoSettings.php` -- Configuracao MP
- `NVoipSettings.php` -- NVoip OTP/SMS
- `PPIDSettings.php` -- PPID KYC biometrico
- `TestVendorsManager.php` -- (DESABILITADO)
- `TwilioSettings.php` -- Twilio SMS/OTP

### Pages (src/Infrastructure/Admin/Pages/)
- `BriefingDetailPage.php` -- Detalhe de briefing
- `BriefingManagementPage.php` -- Lista de briefings (219 linhas)
- `BriefingSettings.php` -- Config briefing (duplicado?)
- `CommunicationCenterPage.php` -- Centro de comunicacao (deprecated)
- `CommunicationSettingsPage.php` -- Config comunicacao
- `ContractManagementPage.php` -- Contratos (615 linhas)
- `CustomerBriefingPage.php` -- Briefing do cliente
- `CustomerFeedbackPage.php` -- Feedback do cliente
- `CustomersManagementPage.php` -- Clientes
- `DocumentReviewPage.php` -- Revisao KYC
- `ExecutionDetailsPage.php` -- Detalhe de execucao (EXISTE mas nao como pagina de menu)
- `FeedbackManagementPage.php` -- Feedback & Qualidade
- `LimpVixSettingsPage.php` -- Pagina de settings alternativa (registra menu separado!)
- `LimpVixUsersPage.php` -- Equipe interna
- `MessageFlowsAdminPage.php` -- Fluxos de mensagem (deprecated)
- `MessageTemplatesAdminPage.php` -- Templates de mensagem
- `MessageTemplatesPage.php` -- Templates (outra versao?)
- `PackageManagementPage.php` -- Pacotes
- `PayoutsPage.php` -- Payouts
- `ProfessionalAvailabilityPage.php` -- Disponibilidade
- `ProfessionalManagementPage.php` -- Profissionais (3.660 linhas)
- `ScheduleManagementPage.php` -- Agendamentos
- `ServiceCatalogPage.php` -- Catalogo de servicos

### Tables (src/Infrastructure/Admin/Tables/)
- `Contract_List_Table.php` -- WP_List_Table para contratos
- `Professional_List_Table.php` -- WP_List_Table para profissionais

### UI Components (src/Infrastructure/Admin/UI/)
- `UIComponents.php` -- Header, Badge, Portlet reutilizaveis

### Widgets (src/Infrastructure/Admin/Widgets/)
- `CronHealthWidget.php` -- Cron status no dashboard WP
- `FeedbackWindowMonitorWidget.php` -- Monitor de feedback 24h

### AJAX Handlers (src/Infrastructure/Admin/Ajax/)
- `DocumentReviewAjaxHandler.php` -- Approve/reject documentos
- `ManualPayoutAjaxHandler.php` -- Payout manual

### Assets
- `assets/css/limpvix-admin.css` (356 linhas)
- `assets/css/limpvix-admin-modern.css` (679 linhas)
- `assets/finance.css` (45 linhas -- possivelmente orfao)
- `assets/finance.js` (12 linhas -- possivelmente orfao)
- `assets/js/message-templates.js`
- `assets/js/admin/send-offers.js`
- `assets/js/modules/address-autofill.js`
- `src/Admin/Settings/assets/css/limpvix-mp.css`
- `src/Admin/Settings/assets/js/limpvix-mp.js`

## APENDICE B: POTENCIAL DUPLICACAO DE PAGINAS

| Arquivo | Slug | Registrado? | Conflito |
|---------|------|-------------|----------|
| LimpVixSettingsPage.php | limpvix-settings (add_menu_page + add_submenu_page) | Potencialmente sim | Conflita com AdminBootstrap::registerMenu() |
| BriefingSettings.php | parent 'limpvix' (add_submenu_page) | Via register() | Parent inexistente |
| BriefingManagementPage.php | parent 'limpvix' (add_submenu_page) | Via register() | Parent inexistente, mas renderBriefingsPage() usa callback direto |
| ScheduleManagementPage.php | parent 'limpvix-finance' | Via registerMenu() | OK |
| ProfessionalAvailabilityPage.php | parent 'limpvix-finance' | Via registerMenu() | Precisa verificar se esta ativo |

**Nota:** A LimpVixSettingsPage.php registra um `add_menu_page` SEPARADO com slug `limpvix-settings` e depois um submenu. Isso potencialmente conflita com o submenu `limpvix-settings` registrado pelo AdminBootstrap. Investigar se esta classe e carregada em producao.

---

*Documento gerado por auditoria automatizada. Revisao humana recomendada para decisoes de refatoracao.*
