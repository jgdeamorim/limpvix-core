# ANÁLISE PROFUNDA DETALHADA - LIMPVIX CORE

**Data:** 2026-02-12 00:00:22

---

## 📊 SUMÁRIO EXECUTIVO

- **Total de Arquivos PHP:** 613
- **Total de Classes:** 528
- **Total de Interfaces:** 47
- **Use Cases:** 75
- **Aggregates:** 8
- **Repositories:** 0
- **TODOs:** 70
- **FIXMEs:** 0
- **Classes Deprecated:** 15
- **Classes Órfãs:** 193

### Gaps Identificados

- 🔴 **Repositories sem implementação:** 0
- 🟠 **Use Cases sem execute():** 1
- 🟡 **Classes grandes (>500 linhas):** 31
- 🟡 **God Objects (>20 métodos públicos):** 18

---

## 🟠 GAP #2: USE CASES SEM MÉTODO execute()

Use Cases que não implementam o método padrão execute():

### `SelectPackageIntegrationTest`

- **Arquivo:** `tests/Integration/UseCases/SelectPackageIntegrationTest.php`
- **Métodos existentes:** setUp, tearDown, test_execute_selects_basic_package_successfully, test_execute_selects_standard_package_successfully, test_execute_selects_premium_package_successfully, test_execute_fails_for_nonexistent_briefing, test_execute_fails_for_invalid_package_type, test_execute_fails_for_locked_briefing, test_execute_registers_event_in_ledger, test_execute_allows_changing_package, createAndSaveBriefing, cleanupTestData
- **Severidade:** HIGH
- **Impacto:** Quebra convenção de Use Cases
- **Ação:** Adicionar método execute() ou renomear classe se não for Use Case

---

## 🟡 GAP #3: CLASSES MUITO GRANDES (>500 linhas)

Classes que violam Single Responsibility Principle:

### `AdminBootstrap` (3218 linhas)

- **Arquivo:** `src/Admin/Bootstrap/AdminBootstrap.php`
- **Severidade:** MEDIUM
- **Impacto:** Dificulta manutenção e testes
- **Ação:** Refatorar em classes menores

---

### `OAuth2` (1828 linhas)

- **Arquivo:** `vendor/google/auth/src/OAuth2.php`
- **Severidade:** MEDIUM
- **Impacto:** Dificulta manutenção e testes
- **Ação:** Refatorar em classes menores

---

### `ProfessionalManagementPage` (1738 linhas)

- **Arquivo:** `src/Infrastructure/Admin/Pages/ProfessionalManagementPage.php`
- **Severidade:** MEDIUM
- **Impacto:** Dificulta manutenção e testes
- **Ação:** Refatorar em classes menores

---

### `MimeType` (1259 linhas)

- **Arquivo:** `vendor/guzzlehttp/psr7/src/MimeType.php`
- **Severidade:** MEDIUM
- **Impacto:** Dificulta manutenção e testes
- **Ação:** Refatorar em classes menores

---

### `LimpVixSettingsPage` (1252 linhas)

- **Arquivo:** `src/Infrastructure/Admin/Pages/LimpVixSettingsPage.php`
- **Severidade:** MEDIUM
- **Impacto:** Dificulta manutenção e testes
- **Ação:** Refatorar em classes menores

---

### `MessageTemplatesPage` (1047 linhas)

- **Arquivo:** `src/Infrastructure/Admin/Pages/MessageTemplatesPage.php`
- **Severidade:** MEDIUM
- **Impacto:** Dificulta manutenção e testes
- **Ação:** Refatorar em classes menores

---

### `Hooks` (793 linhas)

- **Arquivo:** `src/Core/Hooks.php`
- **Severidade:** MEDIUM
- **Impacto:** Dificulta manutenção e testes
- **Ação:** Refatorar em classes menores

---

### `ContractBootstrap` (788 linhas)

- **Arquivo:** `src/Core/ContractBootstrap.php`
- **Severidade:** MEDIUM
- **Impacto:** Dificulta manutenção e testes
- **Ação:** Refatorar em classes menores

---

### `ProfessionalController` (751 linhas)

- **Arquivo:** `src/Infrastructure/API/ProfessionalController.php`
- **Severidade:** MEDIUM
- **Impacto:** Dificulta manutenção e testes
- **Ação:** Refatorar em classes menores

---

### `BriefingDetailPage` (744 linhas)

- **Arquivo:** `src/Infrastructure/Admin/Pages/BriefingDetailPage.php`
- **Severidade:** MEDIUM
- **Impacto:** Dificulta manutenção e testes
- **Ação:** Refatorar em classes menores

---

*... e mais 21 classes grandes*

## 🟡 GAP #4: GOD OBJECTS (>20 métodos públicos)

Classes com responsabilidades excessivas:

### `OAuth2` (79 métodos públicos)

- **Arquivo:** `vendor/google/auth/src/OAuth2.php`
- **Severidade:** MEDIUM
- **Impacto:** Interface complexa, dificulta uso
- **Ação:** Dividir responsabilidades em classes menores

---

### `Uri` (40 métodos públicos)

- **Arquivo:** `vendor/guzzlehttp/psr7/src/Uri.php`
- **Severidade:** MEDIUM
- **Impacto:** Interface complexa, dificulta uso
- **Ação:** Dividir responsabilidades em classes menores

---

### `Contract` (38 métodos públicos)

- **Arquivo:** `src/Domain/Contract/Contract.php`
- **Severidade:** MEDIUM
- **Impacto:** Interface complexa, dificulta uso
- **Ação:** Dividir responsabilidades em classes menores

---

### `AdminBootstrap` (35 métodos públicos)

- **Arquivo:** `src/Admin/Bootstrap/AdminBootstrap.php`
- **Severidade:** MEDIUM
- **Impacto:** Interface complexa, dificulta uso
- **Ação:** Dividir responsabilidades em classes menores

---

### `Schedule` (33 métodos públicos)

- **Arquivo:** `src/Domain/Scheduling/Schedule.php`
- **Severidade:** MEDIUM
- **Impacto:** Interface complexa, dificulta uso
- **Ação:** Dividir responsabilidades em classes menores

---

### `ProfessionalManagementPage` (32 métodos públicos)

- **Arquivo:** `src/Infrastructure/Admin/Pages/ProfessionalManagementPage.php`
- **Severidade:** MEDIUM
- **Impacto:** Interface complexa, dificulta uso
- **Ação:** Dividir responsabilidades em classes menores

---

### `Briefing` (31 métodos públicos)

- **Arquivo:** `src/Domain/Briefing/Briefing.php`
- **Severidade:** MEDIUM
- **Impacto:** Interface complexa, dificulta uso
- **Ação:** Dividir responsabilidades em classes menores

---

### `Execution` (29 métodos públicos)

- **Arquivo:** `src/Domain/Execution/Execution.php`
- **Severidade:** MEDIUM
- **Impacto:** Interface complexa, dificulta uso
- **Ação:** Dividir responsabilidades em classes menores

---

### `Hooks` (27 métodos públicos)

- **Arquivo:** `src/Core/Hooks.php`
- **Severidade:** MEDIUM
- **Impacto:** Interface complexa, dificulta uso
- **Ação:** Dividir responsabilidades em classes menores

---

### `Professional` (27 métodos públicos)

- **Arquivo:** `src/Domain/Scheduling/Professional.php`
- **Severidade:** MEDIUM
- **Impacto:** Interface complexa, dificulta uso
- **Ação:** Dividir responsabilidades em classes menores

---

*... e mais 8 God Objects*

## 📝 TODOs PENDENTES (70)

### `AdminBootstrap`

- **Arquivo:** `src/Admin/Bootstrap/AdminBootstrap.php`
- **TODO:** Check real API connection

---

### `AdminBootstrap`

- **Arquivo:** `src/Admin/Bootstrap/AdminBootstrap.php`
- **TODO:** Check real API connection

---

### `OrderDetailController`

- **Arquivo:** `src/Admin/Controllers/OrderDetailController.php`
- **TODO:** Detectar anomalias

---

### `PPIDSettings`

- **Arquivo:** `src/Admin/Settings/PPIDSettings.php`
- **TODO:** Encrypt password before storing

---

### `AllocationEngine`

- **Arquivo:** `src/Application/Services/Scheduling/AllocationEngine.php`
- **TODO:** s devem estar disponíveis no mesmo horário

---

### `VerifyBriefingPhone`

- **Arquivo:** `src/Application/UseCases/Briefing/VerifyBriefingPhone.php`
- **TODO:** Implementar FirebaseAuthAdapter na FASE 3

---

### `ExecuteTransfer`

- **Arquivo:** `src/Application/UseCases/ExecuteTransfer.php`
- **TODO:** Migrar para tabela limpvix_professionals quando implementada

---

### `ChargeRecurringPayment`

- **Arquivo:** `src/Application/UseCases/Finance/ChargeRecurringPayment.php`
- **TODO:** Implement proper payment method storage in Contract aggregate

---

### `RetryFailedPayment`

- **Arquivo:** `src/Application/UseCases/Finance/RetryFailedPayment.php`
- **TODO:** Update contract status to payment_failed

---

### `RetryFailedPayment`

- **Arquivo:** `src/Application/UseCases/Finance/RetryFailedPayment.php`
- **TODO:** Implement proper payment method storage in Contract aggregate

---

### `ScheduleOrder`

- **Arquivo:** `src/Application/UseCases/ScheduleOrder.php`
- **TODO:** Implementar quando Adapter estiver pronto

---

### `ScheduleOrder`

- **Arquivo:** `src/Application/UseCases/ScheduleOrder.php`
- **TODO:** Implementar quando Repository estiver pronto

---

### `ScheduleOrder`

- **Arquivo:** `src/Application/UseCases/ScheduleOrder.php`
- **TODO:** Implementar event dispatcher

---

### `ScheduleOrder`

- **Arquivo:** `src/Application/UseCases/ScheduleOrder.php`
- **TODO:** Outras validações

---

### `ScheduleOrder`

- **Arquivo:** `src/Application/UseCases/ScheduleOrder.php`
- **TODO:** Persistir em tabela de auditoria

---

### `TransitionFinancialStatus`

- **Arquivo:** `src/Application/UseCases/TransitionFinancialStatus.php`
- **TODO:** capturar UUID real do ledger

---

### `CommunicationBootstrap`

- **Arquivo:** `src/Core/CommunicationBootstrap.php`
- **TODO:** Implementar WhatsApp360DialogProvider

---

### `Hooks`

- **Arquivo:** `src/Core/Hooks.php`
- **TODO:** Implementar validações

---

### `Hooks`

- **Arquivo:** `src/Core/Hooks.php`
- **TODO:** Aplicar políticas de agendamento

---

### `Hooks`

- **Arquivo:** `src/Core/Hooks.php`
- **TODO:** Verificar SLA de antecedência

---

*... e mais 50 TODOs*

## ⚠️ CÓDIGO DEPRECATED (15)

### `AdminBootstrap`

- **Arquivo:** `src/Admin/Bootstrap/AdminBootstrap.php`
- **Ação:** Remover ou atualizar referências

---

### `Hooks`

- **Arquivo:** `src/Core/Hooks.php`
- **Ação:** Remover ou atualizar referências

---

### `ContractAutomation`

- **Arquivo:** `src/Infrastructure/Automation/ContractAutomation.php`
- **Ação:** Remover ou atualizar referências

---

### `is`

- **Arquivo:** `vendor/composer/InstalledVersions.php`
- **Ação:** Remover ou atualizar referências

---

### `implements`

- **Arquivo:** `vendor/google/auth/src/ApplicationDefaultCredentials.php`
- **Ação:** Remover ou atualizar referências

---

### `is`

- **Arquivo:** `vendor/google/auth/src/Credentials/AppIdentityCredentials.php`
- **Ação:** Remover ou atualizar referências

---

### `CredentialsLoader`

- **Arquivo:** `vendor/google/auth/src/CredentialsLoader.php`
- **Ação:** Remover ou atualizar referências

---

### `Iam`

- **Arquivo:** `vendor/google/auth/src/Iam.php`
- **Ação:** Remover ou atualizar referências

---

### `OAuth2`

- **Arquivo:** `vendor/google/auth/src/OAuth2.php`
- **Ação:** Remover ou atualizar referências

---

### `Client`

- **Arquivo:** `vendor/guzzlehttp/guzzle/src/Client.php`
- **Ação:** Remover ou atualizar referências

---

### `for`

- **Arquivo:** `vendor/guzzlehttp/guzzle/src/ClientInterface.php`
- **Ação:** Remover ou atualizar referências

---

### `CurlFactory`

- **Arquivo:** `vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php`
- **Ação:** Remover ou atualizar referências

---

### `Utils`

- **Arquivo:** `vendor/guzzlehttp/guzzle/src/Utils.php`
- **Ação:** Remover ou atualizar referências

---

### `is`

- **Arquivo:** `vendor/guzzlehttp/guzzle/src/functions.php`
- **Ação:** Remover ou atualizar referências

---

### `Header`

- **Arquivo:** `vendor/guzzlehttp/psr7/src/Header.php`
- **Ação:** Remover ou atualizar referências

---

## 🗑️ CÓDIGO ÓRFÃO - CLASSES NÃO REFERENCIADAS (193)

Classes que não são referenciadas por nenhum outro código:

### `AdminBootstrap`

- **Arquivo:** `src/Admin/Bootstrap/AdminBootstrap.php`
- **Tipo:** Class
- **Ação:** Verificar se é código morto ou falta integração

---

### `DashboardController`

- **Arquivo:** `src/Admin/Controllers/DashboardController.php`
- **Tipo:** Class
- **Ação:** Verificar se é código morto ou falta integração

---

### `FinancialReportController`

- **Arquivo:** `src/Admin/Controllers/FinancialReportController.php`
- **Tipo:** Class
- **Ação:** Verificar se é código morto ou falta integração

---

### `PPIDSettings`

- **Arquivo:** `src/Admin/Settings/PPIDSettings.php`
- **Tipo:** Class
- **Ação:** Verificar se é código morto ou falta integração

---

### `ActivateContractRequest`

- **Arquivo:** `src/Application/DTO/Request/ActivateContractRequest.php`
- **Tipo:** Class
- **Ação:** Verificar se é código morto ou falta integração

---

### `for`

- **Arquivo:** `vendor/psr/cache/src/CacheItemInterface.php`
- **Tipo:** Interface
- **Ação:** Verificar se é código morto ou falta integração

---

### `CancelContractRequest`

- **Arquivo:** `src/Application/DTO/Request/CancelContractRequest.php`
- **Tipo:** Class
- **Ação:** Verificar se é código morto ou falta integração

---

### `PauseContractRequest`

- **Arquivo:** `src/Application/DTO/Request/PauseContractRequest.php`
- **Tipo:** Class
- **Ação:** Verificar se é código morto ou falta integração

---

### `CommunicationProviderInterface`

- **Arquivo:** `src/Application/Services/Communication/CommunicationProviderInterface.php`
- **Tipo:** Interface
- **Ação:** Verificar se é código morto ou falta integração

---

### `PayoutReconciliationService`

- **Arquivo:** `src/Application/Services/PayoutReconciliationService.php`
- **Tipo:** Class
- **Ação:** Verificar se é código morto ou falta integração

---

### `AvailabilityCalculator`

- **Arquivo:** `src/Application/Services/Scheduling/AvailabilityCalculator.php`
- **Tipo:** Class
- **Ação:** Verificar se é código morto ou falta integração

---

### `ProximityScorer`

- **Arquivo:** `src/Application/Services/Scheduling/ProximityScorer.php`
- **Tipo:** Class
- **Ação:** Verificar se é código morto ou falta integração

---

### `GetContractStatistics`

- **Arquivo:** `src/Application/UseCase/Contract/GetContractStatistics.php`
- **Tipo:** Use Case
- **Ação:** Verificar se é código morto ou falta integração

---

### `ListContracts`

- **Arquivo:** `src/Application/UseCase/Contract/ListContracts.php`
- **Tipo:** Use Case
- **Ação:** Verificar se é código morto ou falta integração

---

### `GetExecution`

- **Arquivo:** `src/Application/UseCase/Execution/GetExecution.php`
- **Tipo:** Use Case
- **Ação:** Verificar se é código morto ou falta integração

---

### `ListExecutions`

- **Arquivo:** `src/Application/UseCase/Execution/ListExecutions.php`
- **Tipo:** Use Case
- **Ação:** Verificar se é código morto ou falta integração

---

### `AcceptOffer`

- **Arquivo:** `src/Application/UseCase/Professional/AcceptOffer.php`
- **Tipo:** Use Case
- **Ação:** Verificar se é código morto ou falta integração

---

### `GetAllocationHistory`

- **Arquivo:** `src/Application/UseCase/Professional/GetAllocationHistory.php`
- **Tipo:** Use Case
- **Ação:** Verificar se é código morto ou falta integração

---

### `GetProfessionalStatistics`

- **Arquivo:** `src/Application/UseCase/Professional/GetProfessionalStatistics.php`
- **Tipo:** Use Case
- **Ação:** Verificar se é código morto ou falta integração

---

### `GetScoreHistory`

- **Arquivo:** `src/Application/UseCase/Professional/GetScoreHistory.php`
- **Tipo:** Use Case
- **Ação:** Verificar se é código morto ou falta integração

---

*... e mais 173 classes órfãs*

## 🔗 MAPA DE DEPENDÊNCIAS (Top 30 Classes Mais Usadas)

- ``: usado **308** vezes
- `RequestInterface`: usado **37** vezes
- `ResponseInterface`: usado **23** vezes
- `StreamInterface`: usado **22** vezes
- `ContractId`: usado **20** vezes
- `ContractRepositoryInterface`: usado **19** vezes
- `UriInterface`: usado **17** vezes
- `PromiseInterface`: usado **15** vezes
- `BriefingRepositoryInterface`: usado **14** vezes
- `Auth`: usado **14** vezes
- `FinancialStatus`: usado **13** vezes
- `Contract`: usado **13** vezes
- `ServiceLocation`: usado **13** vezes
- `Briefing`: usado **12** vezes
- `Professional`: usado **12** vezes
- `Result`: usado **12** vezes
- `ContractExecution`: usado **11** vezes
- `Utils`: usado **11** vezes
- `TimeSlot`: usado **10** vezes
- `ContractExecutionRepositoryInterface`: usado **10** vezes
- `BriefingStatus`: usado **10** vezes
- `Client`: usado **10** vezes
- `HandlerStack`: usado **10** vezes
- `Promise`: usado **10** vezes
- `TimeWindow`: usado **9** vezes
- `ProfessionalRepositoryInterface`: usado **9** vezes
- `ContractNotFoundException`: usado **9** vezes
- `WpMarketplaceProfessionalRepository`: usado **9** vezes
- `WeeklyAvailability`: usado **9** vezes
- `CacheItemPoolInterface`: usado **9** vezes

---

## 🎯 AGGREGATES IDENTIFICADOS (8)

### `Briefing`

- **Arquivo:** `src/Domain/Briefing/Briefing.php`
- **Linhas:** 534
- **Métodos:** 32

### `Contract`

- **Arquivo:** `src/Domain/Contract/Contract.php`
- **Linhas:** 609
- **Métodos:** 39

### `Execution`

- **Arquivo:** `src/Domain/Execution/Execution.php`
- **Linhas:** 438
- **Métodos:** 30

### `Financial`

- **Arquivo:** `src/Domain/Finance/Financial.php`
- **Linhas:** 267
- **Métodos:** 19

### `Order`

- **Arquivo:** `src/Domain/Order/Order.php`
- **Linhas:** 280
- **Métodos:** 21

### `Professional`

- **Arquivo:** `src/Domain/Professional/Professional.php`
- **Linhas:** 781
- **Métodos:** 82

### `Professional`

- **Arquivo:** `src/Domain/Scheduling/Professional.php`
- **Linhas:** 316
- **Métodos:** 28

### `Schedule`

- **Arquivo:** `src/Domain/Scheduling/Schedule.php`
- **Linhas:** 399
- **Métodos:** 34

---

**Fim do Relatório**
