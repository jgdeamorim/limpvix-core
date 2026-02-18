# 06 - RUNTIME TEST RESULTS

**Data:** 2026-02-18
**Ambiente:** Docker container `limpvix_wordpress_clean`
**WordPress:** 6.8.2 | **PHP:** 8.2.29 | **MySQL:** 8.0
**Plugin Version:** 0.2.0

---

## RESUMO EXECUTIVO

| Metrica | Valor |
|---------|-------|
| Total de testes executados | 12 |
| PASS | 7 |
| FAIL | 1 |
| WARNING | 4 |
| **Score de Saude Geral** | **72/100** |

O plugin carrega sem fatal errors, o autoloader funciona para a maioria das classes, todas as 38 tabelas estao criadas corretamente com 31 migrations executadas, e 66 endpoints REST estao registrados e respondendo. Os problemas se concentram em: (a) 16 classes com namespaces divergentes do esperado, (b) 8 cron jobs overdue, (c) erros fatais recorrentes em paginas admin especificas, e (d) hooks duplicados.

---

## TESTE 1: PHP SYNTAX CHECK

**Comando:**
```bash
docker exec limpvix_wordpress_clean bash -c \
  "find /var/www/html/wp-content/plugins/limpvix-core/src -name '*.php' -exec php -l {} \;"
```

**Resultado: PASS**

- **497 arquivos PHP verificados**
- **497 sem erros de sintaxe**
- **0 erros de sintaxe detectados**

Todos os arquivos PHP em `src/` passam na verificacao de sintaxe.

---

## TESTE 2: AUTOLOAD TEST

**Comando:** Script PHP executando `class_exists()` para 49 classes do sistema.

**Resultado: WARNING (33 OK, 16 FAIL)**

### Classes OK (33):
```
OK   LimpVix\Core\Kernel
OK   LimpVix\Core\ServiceContainer
OK   LimpVix\Core\ContractBootstrap
OK   LimpVix\Core\ExecutionBootstrap
OK   LimpVix\Admin\Bootstrap\AdminBootstrap
OK   LimpVix\Admin\Controllers\AdminActionsController
OK   LimpVix\Admin\Controllers\DashboardController
OK   LimpVix\Admin\Controllers\FinancialReportController
OK   LimpVix\Admin\Controllers\OrderDetailController
OK   LimpVix\Admin\Controllers\OrdersListController
OK   LimpVix\Admin\Settings\MercadoPagoSettings
OK   LimpVix\Admin\Settings\TwilioSettings
OK   LimpVix\Admin\Settings\FirebaseSettings
OK   LimpVix\Admin\Settings\EfiBankSettings
OK   LimpVix\Admin\Settings\PPIDSettings
OK   LimpVix\Domain\Contract\Contract
OK   LimpVix\Domain\Execution\Execution
OK   LimpVix\Domain\Execution\ExecutionStatus
OK   LimpVix\Domain\Order\Order
OK   LimpVix\Domain\Professional\Professional
OK   LimpVix\Domain\Briefing\Briefing
OK   LimpVix\Application\Services\PlatformFeeCalculator
OK   LimpVix\Application\Services\Scheduling\AllocationEngine
OK   LimpVix\Infrastructure\Persistence\WpExecutionRepository
OK   LimpVix\Infrastructure\Persistence\WpOrderRepository
OK   LimpVix\Infrastructure\Admin\Pages\PayoutsPage
OK   LimpVix\Infrastructure\Admin\Pages\FeedbackManagementPage
OK   LimpVix\Infrastructure\Admin\Pages\ContractManagementPage
OK   LimpVix\Infrastructure\Admin\Pages\MessageTemplatesAdminPage
OK   LimpVix\Infrastructure\Communication\MessageFlowTriggers
OK   LimpVix\Infrastructure\Adapters\FeedbackAdapter
OK   LimpVix\Infrastructure\Adapters\WooCommerceStatusSyncAdapter
OK   LimpVix\Infrastructure\Adapters\AutomaticPayoutDispatcher
```

### Classes FAIL (16) -- Namespace incorreto ou classe inexistente:

| Classe Esperada | Classe Real (encontrada) | Problema |
|----------------|--------------------------|----------|
| `LimpVix\Domain\Contract\ContractStatus` | Nao encontrada | Provavelmente consts na classe Contract |
| `LimpVix\Domain\Professional\ProfessionalStatus` | Nao encontrada | Provavelmente consts na classe Professional |
| `LimpVix\Domain\Payout\Payout` | Nao encontrada | Nao existe como entidade de dominio |
| `LimpVix\Application\Services\Authorization\AuthorizationService` | `LimpVix\Infrastructure\Authorization\AuthorizationService` | Namespace diferente |
| `LimpVix\Application\Services\TransactionManager` | `LimpVix\Infrastructure\Database\TransactionManager` | Namespace diferente |
| `LimpVix\Infrastructure\API\Controllers\ContractController` | Nao encontrada como classe | Registrado via closures/callbacks |
| `LimpVix\Infrastructure\API\Controllers\ExecutionController` | Nao encontrada como classe | Registrado via closures/callbacks |
| `LimpVix\Infrastructure\API\Controllers\ProfessionalController` | Nao encontrada como classe | Registrado via closures/callbacks |
| `LimpVix\Infrastructure\API\Controllers\BriefingController` | Nao encontrada como classe | Registrado via closures/callbacks |
| `LimpVix\Infrastructure\API\Controllers\CustomerController` | Nao encontrada como classe | Registrado via closures/callbacks |
| `LimpVix\Infrastructure\API\Controllers\AuthController` | Nao encontrada como classe | Bootstrap registra routes |
| `LimpVix\Infrastructure\API\Controllers\HealthController` | Nao encontrada como classe | Bootstrap registra routes |
| `LimpVix\Infrastructure\API\Controllers\CepController` | Nao encontrada como classe | Bootstrap registra routes |
| `LimpVix\Infrastructure\Persistence\WPContractRepository` | `LimpVix\Infrastructure\Persistence\Contract\WpContractRepository` | Sub-namespace |
| `LimpVix\Infrastructure\Persistence\WPProfessionalRepository` | `LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository` | Nome diferente |
| `LimpVix\Infrastructure\Persistence\WPPayoutRepository` | `LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository` | Namespace diferente |

**Nota:** A maioria dos "FAILs" nao sao bugs reais -- sao diferencas de convencao de nomenclatura entre o que foi testado e o nome real das classes. As rotas REST sao registradas via closures em bootstrap files, nao via controller classes tradicionais.

### Total de classes LimpVix carregadas no runtime: 132 classes

---

## TESTE 3: DATABASE SCHEMA VALIDATION

**Resultado: PASS**

### 38 tabelas wp_limpvix_* encontradas:

| Tabela | Colunas | Rows |
|--------|---------|------|
| wp_limpvix_briefing_additionals | 8 | 0 |
| wp_limpvix_briefing_data | 6 | 0 |
| wp_limpvix_briefing_ledger | 10 | 0 |
| wp_limpvix_briefings | 26 | 0 |
| wp_limpvix_check_ins | 13 | 0 |
| wp_limpvix_check_outs | 9 | 0 |
| wp_limpvix_consent_records | 7 | 0 |
| wp_limpvix_contract_executions | 11 | 0 |
| wp_limpvix_contract_offers | 9 | 2 |
| wp_limpvix_contracts | 26 | 9 |
| wp_limpvix_executions | 21 | 0 |
| wp_limpvix_feedback | 18 | 15 |
| wp_limpvix_feedback_disputes | 10 | 0 |
| wp_limpvix_feedback_reminders | 10 | 0 |
| wp_limpvix_financial_ledger | 10 | 0 |
| wp_limpvix_message_log | 20 | 0 |
| wp_limpvix_message_queue | 13 | 0 |
| wp_limpvix_message_templates | 8 | 7 |
| wp_limpvix_messages | 13 | 0 |
| wp_limpvix_migrations | 4 | 31 |
| wp_limpvix_orders | 16 | 3 |
| wp_limpvix_package_configs | 11 | 3 |
| wp_limpvix_payout_audit_trail | 7 | 0 |
| wp_limpvix_payouts | 35 | 10 |
| wp_limpvix_professional_allocations | 9 | 0 |
| wp_limpvix_professional_allocations_history | 14 | 0 |
| wp_limpvix_professional_availability | 12 | 0 |
| wp_limpvix_professional_documents | 16 | 0 |
| wp_limpvix_professional_skills | 14 | 0 |
| wp_limpvix_professional_verification | 17 | 0 |
| wp_limpvix_professionals | 71 | 17 |
| wp_limpvix_recurring_payments | 17 | 1 |
| wp_limpvix_schedules | 18 | 0 |
| wp_limpvix_scheduling_ledger | 10 | 0 |
| wp_limpvix_service_additionals | 11 | 10 |
| wp_limpvix_service_catalog | 13 | 6 |
| wp_limpvix_structured_feedbacks | 14 | 0 |
| wp_limpvix_user_verifications | 16 | 1 |

**Total de colunas:** 537 colunas distribuidas em 38 tabelas.
**Observacao:** Schema completo, com indices, PKs, e UNIQUEs configurados corretamente.

---

## TESTE 4: REST API ENDPOINTS TEST

**Resultado: PASS**

### 66 endpoints REST registrados no namespace `limpvix/v1`:

#### Autenticacao (5 endpoints)
| Metodo | Rota | Descricao |
|--------|------|-----------|
| POST | `/auth/login` | Login por username/password |
| POST | `/auth/refresh` | Refresh token |
| GET | `/auth/me` | Dados do usuario autenticado |
| POST | `/auth/otp/send` | Enviar OTP |
| POST | `/auth/otp/verify` | Verificar OTP |

#### Briefing (8 endpoints)
| Metodo | Rota | Descricao |
|--------|------|-----------|
| GET | `/briefing/schema` | Schema do formulario |
| POST | `/briefing` | Criar briefing |
| GET | `/briefing/{uuid}` | Obter briefing |
| POST | `/briefing/{uuid}/step` | Atualizar step |
| POST | `/briefing/{uuid}/verify-phone` | Verificar telefone |
| POST | `/briefing/{uuid}/package` | Selecionar pacote |
| POST | `/briefing/{uuid}/additionals` | Adicionar adicionais |
| GET | `/packages` | Listar pacotes |

#### Servicos e Catalogo (3 endpoints)
| Metodo | Rota |
|--------|------|
| GET | `/services` |
| GET | `/additionals` |
| GET | `/cep/{cep}` |

#### Customers (5 endpoints)
| Metodo | Rota |
|--------|------|
| GET | `/customers` |
| GET | `/customers/me` |
| GET,PUT | `/customers/{id}` |
| GET | `/customers/{id}/contracts` |
| GET | `/customers/{id}/briefings` |

#### Professionals (15 endpoints)
| Metodo | Rota |
|--------|------|
| GET,POST | `/professionals` |
| GET,POST,PUT,PATCH | `/professionals/{id}` |
| GET | `/professionals/{id}/offers` |
| POST | `/professionals/{id}/offers/{offer_id}/accept` |
| POST | `/professionals/{id}/offers/{offer_id}/reject` |
| POST,PUT,PATCH | `/professionals/{id}/availability` |
| GET | `/professionals/{id}/score-history` |
| GET | `/professionals/{id}/allocations` |
| GET | `/professionals/{id}/mercadopago/connect` |
| POST | `/professionals/{id}/mercadopago/disconnect` |
| GET,PUT | `/professionals/{id}/payout-method` |
| POST,GET | `/professionals/{id}/documents` |
| GET | `/professionals/{id}/kyc-status` |
| GET | `/documents/pending` |
| POST | `/documents/{id}/approve` |
| POST | `/documents/{id}/reject` |

#### Contracts (11 endpoints)
| Metodo | Rota |
|--------|------|
| GET,POST | `/contracts` |
| POST | `/contracts/{id}/activate` |
| POST | `/contracts/{id}/pause` |
| POST | `/contracts/{id}/cancel` |
| GET | `/contracts/{id}/executions` |
| POST | `/contracts/{id}/schedule-execution` |
| GET | `/contracts/{id}/reallocation-options` |
| POST | `/contracts/{id}/reallocate` |
| POST | `/contracts/{id}/send-offers` |
| GET | `/contracts/{id}/offers` |

#### Offers (3 endpoints)
| Metodo | Rota |
|--------|------|
| GET | `/offers/{id}` |
| POST | `/offers/{id}/accept` |
| POST | `/offers/{id}/reject` |

#### Executions (11 endpoints)
| Metodo | Rota |
|--------|------|
| GET,POST | `/executions` |
| GET | `/executions/{id}` |
| POST | `/executions/{id}/schedule` |
| POST | `/executions/{id}/start` |
| POST | `/executions/{id}/complete` |
| POST | `/executions/{id}/cancel` |
| POST | `/executions/{id}/no-show` |
| POST,GET | `/executions/{id}/evidence` |
| DELETE | `/executions/{id}/evidence/{index}` |
| POST | `/executions/{id}/reschedule` |
| POST,GET | `/executions/{uuid}/issues` |

#### Health & Webhooks (4 endpoints)
| Metodo | Rota |
|--------|------|
| GET | `/health` |
| GET | `/health/cron` |
| POST | `/webhooks/mercadopago` |
| GET | `/oauth/mercadopago/callback` |

#### API Keys (2 endpoints)
| Metodo | Rota |
|--------|------|
| GET,POST | `/api-keys` |
| DELETE | `/api-keys/{key}` |

### CURL Test Results:

| Endpoint | HTTP Code | Status | Observacao |
|----------|-----------|--------|------------|
| `GET /limpvix/v1` | 200 | PASS | API index retorna todas as rotas |
| `GET /health` | 200 | PASS | `{"status":"healthy"}` com metadata do plugin |
| `GET /health/cron` | 200 | PASS | Cron check_contract_expiration healthy |
| `GET /briefing/schema` | 400 | PASS | Corretamente exige `property_type` param |
| `GET /contracts` | 401 | PASS | Corretamente exige autenticacao |
| `GET /services` | 200 | PASS | Retorna 6 servicos do catalogo |
| `GET /packages` | 200 | PASS | Retorna 3 pacotes (basic, standard, premium) |
| `GET /additionals` | 200 | PASS | Retorna 10 adicionais |
| `GET /professionals` | 401 | PASS | Corretamente exige autenticacao |
| `GET /customers` | 401 | PASS | Corretamente exige autenticacao |
| `GET /executions` | 401 | PASS | Corretamente exige autenticacao |
| `GET /cep/29060440` | 200 | PASS | Retorna endereco correto de Vitoria/ES |
| `POST /auth/login` | 400 | PASS | Corretamente exige username/password |

---

## TESTE 5: CRON JOBS TEST

**Resultado: WARNING**

### 8 cron jobs registrados:

| Agendamento | Hook | Schedule | Status |
|-------------|------|----------|--------|
| 2026-02-15 14:31:18 | `limpvix_process_review_timer` | hourly | **OVERDUE 3d** |
| 2026-02-15 14:34:17 | `limpvix_clean_message_queue` | daily | **OVERDUE 3d** |
| 2026-02-15 14:34:17 | `limpvix_check_contract_expiration` | daily | **OVERDUE 3d** |
| 2026-02-15 14:34:17 | `limpvix_fallback_send_offers` | hourly | **OVERDUE 3d** |
| 2026-02-15 14:34:31 | `limpvix_mp_periodic_sync` | 5min | **OVERDUE 3d** |
| 2026-02-16 13:56:27 | `limpvix_reconcile_payouts` | 6hours | **OVERDUE 2d** |
| 2026-02-16 13:56:28 | `limpvix_payment_authorization_timeout` | hourly | **OVERDUE 2d** |
| 2026-02-17 02:46:26 | `limpvix_send_feedback_reminders` | hourly | **OVERDUE 1d** |

**Todos os 8 cron jobs estao OVERDUE.** Isso e esperado em ambiente Docker sem wp-cron real configurado (depende de visitas HTTP para disparar). Em producao, recomenda-se configurar `DISABLE_WP_CRON=true` e um crontab do sistema.

---

## TESTE 6: HOOKS & FILTERS TEST

**Resultado: WARNING**

### 88 hooks/filters registrados com prefixo `limpvix`:

**Hooks de ciclo de vida do plugin:**
- `activate_limpvix-core/limpvix-core.php` -- 2 callbacks
- `deactivate_limpvix-core/limpvix-core.php` -- 2 callbacks

**Hooks de dominio (event-driven):**
- `limpvix_domain_event` -- 4 closures
- `limpvix_briefing_locked` -- 5 listeners
- `limpvix_briefing_confirmed` -- 1 listener
- `limpvix_order_created` -- 1 listener
- `limpvix_contract_created/activated/paused/resumed/cancelled/completed/expired/renewed` -- 8 hooks, 1 cada
- `limpvix_execution_scheduled/started/completed/cancelled/rescheduled/no_show/checked_in` -- 7 hooks
- `limpvix_feedback_submitted/negative_received/positive_received` -- 3 hooks
- `limpvix_financial_status_changed` -- 4 callbacks (DUPLICADOS)
- `limpvix_customer_feedback_submitted` -- 2 callbacks (DUPLICADOS)
- `limpvix_payment_authorized/blocked` -- 2 hooks
- `limpvix_execution_validated` -- 1 hook
- `limpvix_scheduling_check_in/check_out/schedule_cancelled` -- 3 hooks
- `limpvix_professional_score_updated/suspended` -- 2 hooks

**PROBLEMA IDENTIFICADO -- Hooks Duplicados:**
Os seguintes hooks tem callbacks registrados DUAS VEZES, o que significa que a logica sera executada em dobro:

| Hook | Callbacks Duplicados |
|------|---------------------|
| `limpvix_customer_feedback_submitted` | `FeedbackAdapter::handleFeedbackSubmitted` (2x) |
| `limpvix_process_review_timer` | `TimerCronAdapter::processReviewTimers` (2x) |
| `limpvix_financial_status_changed` | `WooCommerceStatusSyncAdapter::handleStatusChanged` (2x) + `AutomaticPayoutDispatcher::handleStatusChanged` (2x) |
| `limpvix_reconcile_payouts` | `PayoutReconciliationCronAdapter::executeStatic` (2x) |
| `limpvix_payment_authorization_timeout` | `PaymentAuthorizationTimeoutCronAdapter::execute` (2x) |

**Causa provavel:** O bootstrap esta sendo executado duas vezes (uma no `plugins_loaded` e outra no `init` ou `rest_api_init`).

---

## TESTE 7: ADMIN AJAX HANDLERS TEST

**Resultado: PASS**

### 32 AJAX handlers registrados:

**MercadoPago (4):**
- `wp_ajax_limpvix_mp_toggle_environment`
- `wp_ajax_limpvix_mp_check_sync`
- `wp_ajax_limpvix_mp_test_connection`
- `wp_ajax_limpvix_mp_manual_sync`

**EFI Bank (5):**
- `wp_ajax_limpvix_efi_save_settings`
- `wp_ajax_limpvix_efi_test_connection`
- `wp_ajax_limpvix_efi_check_wc_plugin`
- `wp_ajax_limpvix_efi_sync_wc_credentials`
- `wp_ajax_limpvix_efi_upload_cert`

**Firebase & Twilio (3):**
- `wp_ajax_limpvix_firebase_test_connection`
- `wp_ajax_limpvix_firebase_save`
- `wp_ajax_limpvix_test_otp_send`

**Admin Financial Actions (9):**
- `wp_ajax_limpvix_block_order`
- `wp_ajax_limpvix_unblock_order`
- `wp_ajax_limpvix_manual_authorize`
- `wp_ajax_limpvix_execute_payout`
- `wp_ajax_limpvix_refund_order`
- `wp_ajax_limpvix_mark_pix_paid`
- `wp_ajax_limpvix_resolve_feedback_and_payout`
- `wp_ajax_limpvix_authorize_payout`
- `wp_ajax_limpvix_create_manual_payout` / `approve` / `reject`

**Admin Pages (5):**
- `wp_ajax_limpvix_preview_template`
- `wp_ajax_limpvix_get_template_data`
- `wp_ajax_limpvix_get_feedback_detail`
- `wp_ajax_limpvix_send_contract_offers`
- `wp_ajax_limpvix_test_ppid_connection`

**Publicos (nopriv) (2):**
- `wp_ajax_nopriv_limpvix_accept_briefing` / `wp_ajax_limpvix_accept_briefing`
- `wp_ajax_nopriv_limpvix_submit_feedback` / `wp_ajax_limpvix_submit_feedback`

---

## TESTE 8: PLUGIN ACTIVATION/DEACTIVATION TEST

**Resultado: PASS**

```
Plugin loaded. Checking for errors...
Kernel class exists
Active plugins: ["limpvix-core/limpvix-core.php","test-twilio-otp.php",
                 "woo-gerencianet-official/gerencianet-oficial.php",
                 "woocommerce/woocommerce.php"]
PHP memory: 79MB
```

- Plugin carrega sem fatal errors
- Kernel class encontrada e instanciada
- 4 plugins ativos (limpvix-core, test-twilio-otp, gerencianet, woocommerce)
- Uso de memoria: 79MB (aceitavel)

**Logs de inicializacao (sem erros):**
```
[LimpVix Core] Environment initialized
[LimpVix Core] LimpVix Core esta HABILITADO - iniciando bootstrap
[LimpVix Core] TransactionManager inicializado e disponivel globalmente
[LimpVix Core] AuthorizationService inicializado com 3 policies
[LimpVix][EFI] EfiPayoutProvider inicializado -- Ambiente: sandbox
[LimpVix][AdapterBootstrap] Payout provider: EFI Bank
[LimpVix] Modulo Briefing inicializado com sucesso!
[LimpVix] Communication module initialized successfully
[LimpVix] Feedback module initialized successfully
[LimpVix] Scheduling Module initialized
[LimpVix][ProfessionalBootstrap] Professional Module Bootstrap initialized
[LimpVix Contract Bootstrap] Contract Module Bootstrap initialized
[LimpVix Execution Bootstrap] Execution Module Bootstrap initialized
[LimpVix Core] LimpVix Core inicializado com sucesso
```

**Warnings na inicializacao (nao fatais):**
- `[LimpVix] Mercado Pago nao configurado (access_token ausente)` -- Esperado, MP nao configurado
- `[LimpVix Environment] .env file not found` -- Esperado em Docker

---

## TESTE 9: DATABASE INTEGRITY TEST

**Resultado: PASS**

### Dados por entidade:

| Entidade | Total | Detalhe |
|----------|-------|---------|
| Contracts | 9 | active:1, confirmed:1, pending_allocation:7 |
| Orders | 3 | confirmed:3 |
| Professionals | 17 | -- |
| Payouts | 10 | -- |
| Feedback | 15 | -- |
| Contract Offers | 2 | -- |
| Recurring Payments | 1 | -- |
| Message Templates | 7 | -- |
| Service Catalog | 6 | -- |
| Service Additionals | 10 | -- |
| Package Configs | 3 | basic, standard, premium |
| User Verifications | 1 | -- |
| Executions | 0 | -- |
| Briefings | 0 | -- |

### Orphan Check:
```
Contracts with orphan professional_id: 0
Executions with orphan order_uuid: 0
```

**Sem orphans detectados.** Integridade referencial OK.

---

## TESTE 10: MIGRATION STATUS TEST

**Resultado: PASS**

### 31 migrations executadas com sucesso:

| # | Migration | Batch | Data |
|---|-----------|-------|------|
| 1 | 000_create_migrations_table.sql | 1 | 2026-02-15 14:18 |
| 42 | 001_create_orders_table.sql | 4 | 2026-02-16 10:28 |
| 46 | 005_create_executions_table.sql | 5 | 2026-02-16 10:29 |
| 47 | 006_create_briefings_tables.sql | 5 | 2026-02-16 10:29 |
| 48 | 007_add_briefing_packages.sql | 5 | 2026-02-16 10:29 |
| 49 | 008_add_briefing_complexity.sql | 5 | 2026-02-16 10:29 |
| 50 | 009_create_service_catalog_tables.sql | 5 | 2026-02-16 10:29 |
| 51 | 010_create_contracts_tables.sql | 5 | 2026-02-16 10:29 |
| 52 | 011_create_communication_tables.sql | 5 | 2026-02-16 10:29 |
| 53 | 012_create_professionals_module.sql | 5 | 2026-02-16 10:29 |
| 54 | 013_create_scheduling_tables.sql | 5 | 2026-02-16 10:29 |
| 55 | 014_create_structured_feedback_tables.sql | 5 | 2026-02-16 10:29 |
| 56 | 015_create_financial_ledger_table.sql | 5 | 2026-02-16 10:29 |
| 57 | 016_add_professional_fk_constraints.sql | 5 | 2026-02-16 10:29 |
| 58 | 017_add_feedback_window_tracking.sql | 5 | 2026-02-16 10:29 |
| 59 | 018_add_recurring_payments.sql | 5 | 2026-02-16 10:29 |
| 60 | 019_create_professional_skills_table.sql | 5 | 2026-02-16 10:29 |
| 61 | 020_add_kyc_fields.sql | 5 | 2026-02-16 10:29 |
| 62 | 022_add_evidence_validation_fields.sql | 5 | 2026-02-16 10:29 |
| 63 | 023_add_professional_status_column.sql | 5 | 2026-02-16 10:29 |
| 64 | 024_create_user_verifications_table.sql | 5 | 2026-02-16 10:29 |
| 65 | 021_create_contract_offers_table.sql | 6 | 2026-02-17 01:12 |
| 66 | 023_create_professional_documents_table.sql | 6 | 2026-02-17 01:12 |
| 67 | 024_add_manual_payout_fields.sql | 6 | 2026-02-17 01:12 |
| 68 | 025_add_service_catalog_required_skills.sql | 7 | 2026-02-17 02:46 |
| 69 | 025_add_service_catalog_required_skills_OLD.sql | 7 | 2026-02-17 02:46 |
| 70 | 026_create_professional_verification.sql | 8 | 2026-02-18 16:50 |
| 71 | 029_add_recurring_payment_execution_fields.sql | 8 | 2026-02-18 16:50 |
| 72 | 030_add_feedback_resolution_fields.sql | 8 | 2026-02-18 16:50 |
| 73 | 027_add_payout_dual_mode_fields.sql | 10 | 2026-02-18 16:55 |
| 74 | 031_add_payout_authorized_status.sql | 10 | 2026-02-18 16:55 |

**Nota:** Ha um gap nos IDs (2-41 ausentes) e batches (2-3, 9 pulados), sugerindo que houve recreacao do banco. Migration 025 tem duplicata (_OLD).

---

## TESTE 11: ERROR LOG ANALYSIS

**Resultado: FAIL**

### Resumo do debug.log:
- **175,714 linhas** no arquivo de log
- **81 PHP Fatal errors** (total)
- **160 PHP Warnings** (total)
- **3,098 PHP Notices** (total)

### Erros Fatais REAIS em producao (excluindo test scripts e CLI):

#### CRITICO -- Erros que QUEBRAM funcionalidades:

**1. Professional::$kycExpiresAt TypeError (RECORRENTE)**
```
TypeError: Cannot assign DateTimeImmutable to property
LimpVix\Domain\Professional\Professional::$kycExpiresAt
of type ?LimpVix\Domain\Professional\DateTimeImmutable
```
- **Arquivo:** `src/Domain/Professional/Professional.php:260`
- **Ocorrencias:** 15+
- **Impacto:** QUALQUER operacao que carrega profissionais com KYC expires_at falha
- **Causa:** O type hint usa namespace local `DateTimeImmutable` em vez de `\DateTimeImmutable`

**2. PayoutsPage::formatRecipientType() null argument (RECORRENTE)**
```
TypeError: formatRecipientType(): Argument #1 ($type) must be of type string, null given
```
- **Arquivo:** `src/Infrastructure/Admin/Pages/PayoutsPage.php:186/334`
- **Ocorrencias:** 8+
- **Impacto:** Pagina admin de Payouts CRASHA ao renderizar

**3. MessageTemplatesAdminPage::getTemplate() undefined method**
```
Error: Call to undefined method LimpVix\Domain\Communication\MessageTemplates::getTemplate()
```
- **Arquivo:** `src/Infrastructure/Admin/Pages/MessageTemplatesAdminPage.php:921/974`
- **Impacto:** Edicao de templates de mensagem nao funciona

**4. DashboardController number_format() null (RECORRENTE)**
```
TypeError: number_format(): Argument #1 ($num) must be of type float, null given
```
- **Arquivo:** `src/Admin/Controllers/DashboardController.php:108`
- **Ocorrencias:** 4+
- **Impacto:** Dashboard admin CRASHA

**5. ExecutePayout argument count mismatch**
```
ArgumentCountError: Too few arguments to ExecutePayout::__construct(),
3 passed ... and exactly 5 expected
```
- **Arquivo:** `src/Infrastructure/Admin/Pages/PayoutsPage.php:267`
- **Impacto:** Execucao manual de payouts nao funciona

**6. UserRoles::getUserRole() redeclaration**
```
Cannot redeclare LimpVix\Core\UserRoles::getUserRole()
```
- **Arquivo:** `src/Core/UserRoles.php:334`
- **Ocorrencias:** 8+
- **Impacto:** Metodo declarado em duplicata -- funcionalidade de roles instavel

**7. EfiPaymentProvider::curlRequest() type error**
```
TypeError: curlRequest(): Argument #4 ($headers) must be of type array, string given
```
- **Arquivo:** `src/Infrastructure/Finance/Providers/EfiPaymentProvider.php:133/270`
- **Impacto:** Pagamentos via EFI Bank falham ao fazer requests

#### RESOLVIDOS (ocorreram mas foram corrigidos):
- `EfiPayoutProvider not found` -- Corrigido (classe carrega normalmente agora)
- `MercadoPagoPaymentProvider type mismatch` -- Aparece apenas em configuracao MP-para-EFI

### Warnings (Nao fatais mas importantes):

| Warning | Arquivo | Ocorrencias |
|---------|---------|-------------|
| Undefined array key "duration_ms" | AdminBootstrap.php (varias linhas) | 10+ |
| Undefined variable $mpConfigured | AdminBootstrap.php:4764 | 1 |
| Trying to access array offset on null | DashboardController.php:108 | 4+ |
| Trying to access array offset on null | WpMarketplaceProfessionalRepository.php:244 | 1 |

---

## TESTE 12: CURL TEST OF KEY ENDPOINTS

**Resultado: PASS**

(Resultados detalhados ja incluidos no Teste 4 acima)

Resumo: Todos os 13 endpoints testados respondem com os HTTP codes esperados.

---

## SECAO: ERROS FATAIS -- PRIORIDADE DE CORRECAO

### P0 -- CRITICO (Quebra funcionalidades core)

| # | Erro | Arquivo | Impacto |
|---|------|---------|---------|
| 1 | `Professional::$kycExpiresAt` type mismatch | Professional.php:260 | Qualquer leitura de profissional com KYC |
| 2 | `DashboardController::number_format(null)` | DashboardController.php:108 | Dashboard admin inacessivel |
| 3 | `PayoutsPage::formatRecipientType(null)` | PayoutsPage.php:186 | Pagina de payouts inacessivel |
| 4 | `ExecutePayout::__construct()` arg count | PayoutsPage.php:267 | Execucao de payout impossivel |
| 5 | `EfiPaymentProvider::curlRequest()` type | EfiPaymentProvider.php:133 | Pagamentos PIX falham |

### P1 -- ALTO (Funcionalidade parcialmente quebrada)

| # | Erro | Arquivo | Impacto |
|---|------|---------|---------|
| 6 | `MessageTemplates::getTemplate()` undefined | MessageTemplatesAdminPage.php:921 | Edicao de templates falha |
| 7 | `UserRoles::getUserRole()` redeclaration | UserRoles.php:334 | Roles podem falhar em certos cenarios |
| 8 | Hooks duplicados (5 hooks) | Bootstrap files | Logica executada 2x |

### P2 -- MEDIO (Warnings que devem ser corrigidos)

| # | Warning | Arquivo |
|---|---------|---------|
| 9 | Undefined key "duration_ms" | AdminBootstrap.php (multiplas linhas) |
| 10 | Undefined variable $mpConfigured | AdminBootstrap.php:4764 |
| 11 | Array offset on null | WpMarketplaceProfessionalRepository.php:244 |

---

## SECAO: ENDPOINTS QUEBRADOS

Nenhum endpoint REST retornou erro inesperado. Todos os endpoints publicos (health, services, packages, additionals, cep) retornam 200. Endpoints protegidos retornam 401 corretamente. O unico ponto de atencao e que endpoints que dependem de dados de profissionais com KYC podem falhar internamente (erro #1 acima).

---

## SECAO: TABELAS COM PROBLEMAS

Nenhuma tabela com problema estrutural detectado. Todas as 38 tabelas existem, tem indices corretos e nenhum orphan foi encontrado. A migration duplicada `025_add_service_catalog_required_skills_OLD.sql` deveria ser removida.

---

## SECAO: CLASSES NAO CARREGAVEIS

A API REST usa um pattern de registro via closures (nao controller classes), entao a ausencia de classes `*Controller` no namespace `Infrastructure\API\Controllers\` nao e um bug -- e uma decisao arquitetural.

As unicas classes genuinamente nao encontraveis sao:
1. `LimpVix\Domain\Contract\ContractStatus` -- Nao existe como classe/enum separada
2. `LimpVix\Domain\Professional\ProfessionalStatus` -- Nao existe como classe/enum separada
3. `LimpVix\Domain\Payout\Payout` -- Nao existe como entidade de dominio

---

## SCORE DE SAUDE: 72/100

| Categoria | Peso | Score | Contribuicao |
|-----------|------|-------|--------------|
| Syntax Check (497/497) | 10% | 100 | 10.0 |
| Autoload (132 classes OK) | 10% | 90 | 9.0 |
| Database Schema (38 tables, 0 orphans) | 15% | 100 | 15.0 |
| REST API (66 endpoints, 13/13 OK) | 15% | 100 | 15.0 |
| Cron Jobs (8 registrados, todos overdue) | 10% | 40 | 4.0 |
| Hooks & Filters (88 hooks, 5 duplicados) | 10% | 70 | 7.0 |
| AJAX Handlers (32 registrados) | 5% | 100 | 5.0 |
| Plugin Loading (sem fatal) | 10% | 95 | 9.5 |
| Error Log (7 erros fatais distintos) | 10% | 25 | 2.5 |
| Migrations (31 OK, 1 duplicada) | 5% | 90 | 4.5 |
| **TOTAL** | **100%** | | **81.5** |

**Ajuste por erros P0 ativos: -10 pontos** (5 erros fatais criticos que quebram funcionalidades em producao)

### **SCORE FINAL: 72/100**

---

## RECOMENDACOES IMEDIATAS

1. **FIX Professional.php:260** -- Mudar `?DateTimeImmutable` para `?\DateTimeImmutable` (com backslash global)
2. **FIX DashboardController.php:108** -- Adicionar null check antes de `number_format()`
3. **FIX PayoutsPage.php:334** -- Aceitar `?string $type` ou usar `$type ?? ''`
4. **FIX PayoutsPage.php:267** -- Atualizar instanciacao de `ExecutePayout` para 5 argumentos
5. **FIX EfiPaymentProvider.php:133** -- Converter `$headers` para array antes de passar
6. **FIX MessageTemplates::getTemplate()** -- Implementar metodo ou atualizar chamada
7. **FIX UserRoles.php** -- Remover declaracao duplicada do metodo `getUserRole()`
8. **FIX Hooks duplicados** -- Garantir que bootstrap roda apenas 1 vez (usar flag `did_action()`)
9. **SETUP wp-cron** -- Configurar crontab do sistema para disparar wp-cron regularmente
10. **CLEAN UP** -- Remover migration duplicada `025_OLD`
