# AUDITORIA 1/3: ARQUITETURA, ESTRUTURA E RESIDUOS BOOKNETIC

| Campo | Valor |
|-------|-------|
| **Data** | 2026-02-18 |
| **Versao do Plugin** | 0.2.0 |
| **Escopo** | Arquitetura DDD, estrutura de diretorios, residuos Booknetic/BookingKit, bootstrap, autoload |
| **Auditor** | Claude Opus 4.6 (Auditoria Automatizada) |
| **Base Path** | `/media/jeffer/.../wp-content/plugins/limpvix-core/` |

---

## RESUMO EXECUTIVO

O plugin limpvix-core passou por uma remocao do Booknetic como dependencia obrigatoria, porem a limpeza ficou **incompleta**. Foram identificados **34 achados** de auditoria, sendo **3 CRITICAL**, **8 HIGH**, **12 MEDIUM**, **7 LOW** e **4 INFO**.

Os problemas mais graves sao:
1. **Bug logico no activation hook** que registra e imediatamente desregistra user roles (CRITICAL)
2. **Bug de nesting no renderSettingsPage** que impede salvamento de configuracoes Twilio e Exato Digital (CRITICAL)
3. **Metodo logError() ausente no Kernel** causando fatal error em producao quando variaveis de ambiente faltam (CRITICAL)
4. **Residuos Booknetic** em comentarios de codigo ativo, SQL migrations, testes E2E e documentacao (HIGH)
5. **Duplicacao de namespace UseCase vs UseCases** com classe `RenewContract` existindo em ambos (HIGH)
6. **Inicializacao dupla do AdapterBootstrap** sem protecao contra re-boot (HIGH)
7. **AdminBootstrap.php com 7.124 linhas** -- God Object evidente (HIGH)
8. **Violacoes DDD na camada Application** importando classes concretas de Infrastructure (HIGH)

### Metricas Gerais

| Metrica | Valor |
|---------|-------|
| Total de arquivos PHP em `src/` (ativos) | 497 |
| Total de arquivos PHP em `src/` (backups/broken) | 13 |
| Total de classes/interfaces/enums em `src/` | ~490 |
| Arquivos PHP em `tests/` | 49 |
| Arquivos de migracao SQL | 20+ |
| Diretorios vazios em `src/` | 5 |
| Chamadas `error_log()` em `src/` | 454 ocorrencias em 119 arquivos |
| Linhas do AdminBootstrap.php | 7.124 |

### Distribuicao por Camada DDD

| Camada | Arquivos PHP |
|--------|-------------|
| Domain | 163 |
| Application | 135 |
| Infrastructure | 162 |
| Admin | 18 |
| Core (bootstrap) | 16 |

### Tabela de Achados por Severidade

| Severidade | Quantidade |
|------------|-----------|
| CRITICAL | 3 |
| HIGH | 8 |
| MEDIUM | 12 |
| LOW | 7 |
| INFO | 4 |
| **TOTAL** | **34** |

---

## 1. RESIDUOS BOOKNETIC/BOOKINGKIT

### 1.1 Residuos em Codigo PHP Ativo (src/)

**Severidade: HIGH**

Tres referencias a Booknetic permanecem no codigo PHP ativo:

| Arquivo | Linha | Conteudo | Tipo |
|---------|-------|----------|------|
| `src/Admin/Bootstrap/AdminBootstrap.php` | 520 | `$isBookneticActive = false; // agendador externo nao usado` | Variavel residual |
| `src/Admin/Bootstrap/AdminBootstrap.php` | 524 | `$allPluginsActive = $isBookneticActive && $isWooCommerceActive && $isMercadoPagoActive;` | Logica quebrada (sempre false) |
| `src/Admin/Bootstrap/AdminBootstrap.php` | 1934 | `'booknetic' => false, // Booknetic removido` | Chave residual no health check |

**Impacto:** A variavel `$allPluginsActive` na linha 524 eh SEMPRE `false` porque `$isBookneticActive = false`. Isso afeta a aba Dependencias inteira:
- Linha 568: `$readyForGoLive` sera sempre false
- Linha 592: Mostra icone de erro permanente
- Linha 619: Mostra alerta de plugins faltando sempre
- Linha 699-701: Cor de borda e mensagem sempre indicam erro

**Recomendacao:** Remover `$isBookneticActive` completamente e ajustar `$allPluginsActive` para depender apenas de WooCommerce e MercadoPago.

---

### 1.2 Residuos em Migrations SQL

**Severidade: MEDIUM**

| Arquivo | Linha | Conteudo |
|---------|-------|----------|
| `database-migrations/001_create_orders_table.sql` | 16 | `appointment_id BIGINT UNSIGNED NULL COMMENT 'ID do appointment no Booknetic'` |
| `database-migrations/013_create_scheduling_tables.sql` | 54 | `professional_id INT NOT NULL COMMENT 'Staff ID do Booknetic'` |
| `database-migrations/013_create_scheduling_tables.sql` | 76 | `-- FOREIGN KEY (professional_id) REFERENCES wp_bkntc_staff` |
| `database-migrations/013_create_scheduling_tables.sql` | 83 | `professional_id INT NOT NULL COMMENT 'Staff ID do Booknetic'` |
| `database-migrations/013_create_scheduling_tables.sql` | 108,144,175 | FKs comentadas referenciando `wp_bkntc_staff` |
| `database-migrations/014_create_structured_feedback_tables.sql` | 81 | FK comentada referenciando `wp_bkntc_staff` |
| `database-migrations/015_create_financial_ledger_table.sql` | 27 | `COMMENT 'ID do appointment Booknetic'` |
| `database-migrations/MIGRATIONS-AUDIT-REPORT.md` | 53 | Referencia a `wp_bkntc_staff` |

**Recomendacao:** Atualizar os COMENTs nas migrations para refletir terminologia nativa (ex: "ID do profissional LimpVix"). FKs comentadas sao inofensivas mas devem ser removidas para clareza.

---

### 1.3 Residuos em Testes E2E

**Severidade: MEDIUM**

| Arquivo | Linhas | Conteudo |
|---------|--------|----------|
| `tests/E2E/ExecutionCompleteFlowTest.php` | 235, 446 | `$wpdb->insert($wpdb->prefix . 'bkntc_staff', ...)` e `$wpdb->delete(...)` |
| `tests/E2E/ContractCompleteFlowTest.php` | 321, 483 | Idem |
| `tests/E2E/ProfessionalCompleteFlowTest.php` | 220, 403 | Idem |

**Impacto:** Testes E2E falharao se executados em ambiente sem tabela `bkntc_staff`.

**Recomendacao:** Refatorar testes para usar tabela nativa `wp_limpvix_professionals`.

---

### 1.4 Residuos em Arquivos de Backup

**Severidade: LOW**

| Arquivo | Ocorrencias |
|---------|-------------|
| `src/Admin/Bootstrap/AdminBootstrap.php.backup.twilio` | 47 referencias a Booknetic |
| `src/Admin/Bootstrap/AdminBootstrap.php.broken` | 2 referencias |
| `src/Admin/Bootstrap/AdminBootstrap.php.backup.before_reorganize` | (nao verificado - provavel conteudo similar) |

**Recomendacao:** Remover todos os 13 arquivos de backup em `src/`:
- `src/Admin/Bootstrap/AdminBootstrap.php.backup.before_reorganize`
- `src/Admin/Bootstrap/AdminBootstrap.php.backup.twilio`
- `src/Admin/Bootstrap/AdminBootstrap.php.broken`
- `src/Application/UseCase/Auth/SendOtp.php.backup`
- `src/Application/UseCase/Auth/VerifyOtp.php.backup`
- `src/Core/Kernel.php.bak`
- `src/Infrastructure/Admin/Pages/KYCManagementPage.php.bak`
- `src/Infrastructure/Admin/Pages/ProfessionalManagementPage.php.backup`
- `src/Infrastructure/Admin/Pages/ProfessionalManagementPage.php.bak`
- `src/Infrastructure/API/BriefingApiBootstrap.php.backup`
- `src/Infrastructure/API/OtpController.php.backup.twilio`
- `src/Infrastructure/Finance/Repositories/WpPayoutRepository.php.bak`
- `src/Infrastructure/SMS/NVoipOtpProvider.php.backup`

---

### 1.5 Residuos em Assets

**Severidade: LOW**

| Arquivo | Linha | Conteudo |
|---------|-------|----------|
| `assets/css/limpvix-admin.css` | 3 | `* Baseado no design Booknetic` |

**Recomendacao:** Atualizar comentario.

---

### 1.6 Residuos em Documentacao e composer.json

**Severidade: MEDIUM**

| Arquivo | Conteudo |
|---------|----------|
| `composer.json` | `"description": "LimpVix Core - Camada de governanca sobre Booknetic"` |
| `README.md` | Multiplas referencias - Booknetic como requisito, documentacao de integracao |
| `ANALISE_ABA_DEPENDENCIAS.md` | Extenso conteudo sobre Booknetic |
| `DEPENDENCIAS_OBSERVACOES.md` | Documentacao completa de integracao Booknetic |
| `STATUS_ABA_DEPENDENCIAS_DINAMICA.md` | Metodos `getBookneticHooksStatus()`, etc. |
| `CHANGELOG.md` | Historico com Booknetic (aceitavel - eh historico) |
| `docs/SMOKE_TESTS_REPORT.md` | Referencias a `wp_bkntc_staff` |

**Recomendacao:** Atualizar `composer.json` description e `README.md` para refletir arquitetura atual (motor nativo). Documentos de analise podem ser arquivados.

---

### 1.7 BookingEngineInterface Sem Implementacao

**Severidade: MEDIUM**

| Arquivo | Detalhes |
|---------|----------|
| `src/Infrastructure/BookingEngine/BookingEngineInterface.php` | Interface definida com 10 metodos, NENHUMA implementacao encontrada |

**Impacto:** Interface orfao. Era prevista para abstrair Booknetic mas nunca foi implementada.

**Recomendacao:** Remover ou implementar. Se o plano eh ter motor de agendamento nativo, implementar `NativeBookingEngine`. Se nao, remover para evitar confusao.

---

## 2. ESTRUTURA DE DIRETORIOS E INCONSISTENCIAS

### 2.1 Duplicacao UseCase (singular) vs UseCases (plural)

**Severidade: HIGH**

Existem DUAS pastas com Use Cases:

```
src/Application/UseCase/     (42 arquivos, 6 subdiretorios)
src/Application/UseCases/    (55 arquivos, 10 subdiretorios)
```

**Subdiretorios duplicados** (existem em ambos):

| Subdiretorio | UseCase (singular) | UseCases (plural) |
|-------------|-------------------|-------------------|
| Briefing | 1 arquivo (SendOffers) | 10 arquivos |
| Contract | 14 arquivos | 2 arquivos (CreateContractFromBriefing, RenewContract) |
| Execution | 10 arquivos | 8 arquivos |
| Professional | 12 arquivos | 3 arquivos (ListDocuments, ReviewDocument, UploadDocument) |

**CLASSE DUPLICADA CRITICA:**

| Classe | UseCase/Contract/ | UseCases/Contract/ |
|--------|------------------|-------------------|
| `RenewContract` | Auto-renewal (v0.8.0) `namespace LimpVix\Application\UseCase\Contract` | Manual renewal (v0.12.0) `namespace LimpVix\Application\UseCases\Contract` |

Ambas sao classes `RenewContract` com funcionalidades DIFERENTES:
- `UseCase\Contract\RenewContract` -- renovacao automatica (estende contrato existente)
- `UseCases\Contract\RenewContract` -- renovacao manual (cria novo contrato com alteracoes)

**Referencia ativa:** `ContractBootstrap.php` importa `UseCase\Contract\RenewContract` (auto-renewal).

**Recomendacao:** Consolidar tudo em `UseCases/` (plural, padrao DDD). Renomear classes homonimas (ex: `AutoRenewContract` vs `ManualRenewContract`).

---

### 2.2 Finance vs Financial (Namespace Split)

**Severidade: MEDIUM**

| Namespace | Conteudo |
|-----------|----------|
| `UseCases/Finance/` | `ChargeRecurringPayment`, `ProcessPaymentWebhook`, `RetryFailedPayment` |
| `UseCases/Financial/` | `ApproveManualPayout`, `CreateManualPayout`, `ExecutePayout` |

Dois namespaces para conceitos financeiros sem separacao semantica clara.

**Recomendacao:** Consolidar em um unico namespace `UseCases/Finance/` com subpastas por responsabilidade se necessario (ex: `Finance/Payments/`, `Finance/Payouts/`).

---

### 2.3 Diretorios Vazios

**Severidade: LOW**

| Diretorio | Observacao |
|-----------|-----------|
| `src/Admin/Settings/assets/images/` | Provavelmente aguardando assets |
| `src/Database/Migrations/` | Confuso: migrations estao em `src/Infrastructure/Database/Migrations/` E `database-migrations/` |
| `src/Infrastructure/API/Middleware/` | Middleware planejado mas nao implementado |
| `src/Infrastructure/Notification/` | Notificacao planejada mas nao implementada |
| `src/Integration/` | Vazio -- integracao esta em `src/Infrastructure/Integration/` |

Tambem: `modules/` na raiz esta vazio (modulos antigos foram migrados).

**Recomendacao:** Remover diretorios vazios que nao serao usados. Para diretorios planejados, adicionar arquivo `.gitkeep` com comentario.

---

### 2.4 Tres Locais de Migrations

**Severidade: MEDIUM**

| Local | Conteudo |
|-------|----------|
| `database-migrations/` | 20+ arquivos SQL -- migrations ATIVAS usadas pelo MigrationRunner |
| `src/Infrastructure/Database/Migrations/` | 3 arquivos PHP (CreateLedgerTable, CreateMercadoPagoPayoutsTable, CreateFeedbackTable) |
| `src/Database/Migrations/` | VAZIO |
| `src/Core/Migrations/` | CreateOrdersTable.php |

**Recomendacao:** Consolidar todas as migrations em um unico local (`database-migrations/`). Os arquivos PHP em `src/Infrastructure/Database/Migrations/` e `src/Core/Migrations/` parecem ser versoes mais antigas/alternativas.

---

## 3. BOOTSTRAP E ENTRY POINTS

### 3.1 Cadeia de Inicializacao

A cadeia de boot eh:

```
limpvix-core.php
  |-- add_filter('cron_schedules', ...)  [ANTES de plugins_loaded]
  |-- add_action('plugins_loaded', 'limpvix_core_init', 20)
       |-- Kernel::getInstance()->boot()
       |    |-- Environment::load()
       |    |-- FeatureFlags()
       |    |-- TransactionManager()
       |    |-- AuthorizationService()
       |    |-- Hooks->register()
       |    |    |-- registerFinancialAdapters()  --> AdapterBootstrap->boot()  [1a VEZ]
       |    |    |-- registerPayoutModule()       --> return (desabilitado)
       |    |    |-- registerAdminInterface()     --> AdminBootstrap->boot()
       |    |    |-- registerGlobalRestApi()      --> CepController
       |    |    |-- registerAjaxHandlers()       --> PPID test
       |    |-- AuthBootstrap::init()
       |    |-- BriefingBootstrap::init()
       |    |-- CommunicationBootstrap::init()
       |    |-- FeedbackBootstrap::init()
       |    |-- SchedulingBootstrap::init()
       |    |-- ProfessionalBootstrap::init()
       |    |-- ContractBootstrap::init()
       |    |-- ExecutionBootstrap::init()
       |    |-- ContractAutomation::init()
       |    |-- BriefingContractListener::register()
       |-- CommunicationBootstrap::boot()        [2a inicializacao - redundante?]
       |-- AdapterBootstrap->boot()              [2a VEZ!]
```

### 3.2 Inicializacao Dupla do AdapterBootstrap

**Severidade: HIGH**

O `AdapterBootstrap->boot()` eh chamado DUAS vezes:
1. Via `Hooks::registerFinancialAdapters()` (linha 129 de Hooks.php)
2. Via `limpvix_core_init()` (linha 102-104 de limpvix-core.php)

A classe `AdapterBootstrap` NAO tem protecao contra re-boot (nao ha flag `$booted`). Isso causa:
- Registro DUPLO de hooks WordPress
- Instanciacao DUPLA de repositorios e providers
- Potencial duplicacao de eventos/listeners

**Arquivo(s):**
- `limpvix-core.php` linhas 101-105
- `src/Core/Hooks.php` linhas 127-131
- `src/Infrastructure/Adapters/AdapterBootstrap.php` (sem protecao)

**Recomendacao:** Adicionar flag `$booted` no `AdapterBootstrap` OU remover a segunda chamada em `limpvix_core_init()`.

---

### 3.3 Bug Critico: Register + Unregister Roles no Activation Hook

**Severidade: CRITICAL**

No `limpvix-core.php`, linhas 154-172:

```php
// Registrar custom user roles
if (class_exists('LimpVix\\Core\\UserRoles')) {
    LimpVix\Core\UserRoles::register();    // Linha 156: REGISTRA
}

// ... codigo intermediario ...

// Remover custom user roles
if (class_exists('LimpVix\\Core\\UserRoles')) {
    LimpVix\Core\UserRoles::unregister();  // Linha 171: DESREGISTRA IMEDIATAMENTE
}
```

O activation hook REGISTRA e logo em seguida DESREGISTRA as user roles. Resultado: roles nunca sao persistidas.

**Recomendacao:** O `unregister()` deveria estar no **deactivation hook**, nao no activation hook. Mover linhas 169-172 para o `register_deactivation_hook`.

---

### 3.4 Bug Critico: Nesting Errado no renderSettingsPage

**Severidade: CRITICAL**

No `src/Admin/Bootstrap/AdminBootstrap.php`, linhas 365-381:

```php
if ($activeTab === 'briefing' && isset($_POST['limpvix_save_briefing_settings']) ...) {
    $this->handleBriefingSave();

    // ERRO: Os blocos abaixo estao DENTRO do if do briefing!
    if ($activeTab === 'conexoes' && isset($_POST['limpvix_save_twilio_settings'])) {
        \LimpVix\Admin\Settings\TwilioSettings::save();
    }

    if ($activeTab === 'conexoes' && isset($_POST['limpvix_save_exato_settings']) ...) {
        // ... salva opcoes Exato ...
    }
}  // <-- fecha o if do briefing na linha 381
```

Os blocos de salvamento Twilio e Exato Digital estao aninhados dentro do if `$activeTab === 'briefing'`. Como `$activeTab` nunca pode ser 'briefing' E 'conexoes' ao mesmo tempo, esses blocos **NUNCA executam**.

**Impacto:** Configuracoes de Twilio OTP e Exato Digital NAO PODEM ser salvas via interface admin.

**Recomendacao:** Corrigir indentacao -- os blocos Twilio/Exato devem ser irmaos (siblings) do if briefing, nao filhos.

---

### 3.5 Metodo logError() Ausente no Kernel

**Severidade: CRITICAL**

No `src/Core/Kernel.php`, linha 216:

```php
$this->logError('Missing required environment variables: ' . $e->getMessage());
```

O metodo `logError()` NAO existe na classe `Kernel`. Existe apenas `logInfo()` (linha 339).

**Impacto:** Se a validacao de environment falhar em producao, ocorre um **Fatal Error: Call to undefined method** ao inves de um log gracioso.

**Recomendacao:** Adicionar metodo `private function logError(string $message): void` na classe Kernel, similar ao `logInfo()`.

---

### 3.6 Debug error_log() em Codigo de Producao

**Severidade: MEDIUM**

Existem **454 chamadas a `error_log()`** espalhadas em **119 arquivos** dentro de `src/`. Muitas NAO estao protegidas por `WP_DEBUG`:

Exemplos sem protecao:
- `src/Admin/Bootstrap/AdminBootstrap.php` linhas 141, 142, 149 (registerMenu)
- `src/Core/Hooks.php` linha 181 (registerAdminInterface)
- `src/Infrastructure/Adapters/WooCommercePaymentAdapter.php` (10 chamadas)
- `src/Infrastructure/Adapters/WooCommerceStatusSyncAdapter.php` (10 chamadas)

**Recomendacao:** Envolver todas as chamadas `error_log()` em condicional `WP_DEBUG`, ou centralizar logging em servico dedicado.

---

### 3.7 Hook Malformado em registerPasso3Hooks

**Severidade: LOW**

No `src/Core/Hooks.php`, linhas 99-103:

```php
add_action(
    [$this, 'onAppointmentCreated'],  // ERRO: array como nome do hook
    10,
    1
);
```

O primeiro argumento de `add_action()` deve ser uma string (nome do hook), nao um callable. O metodo nao eh chamado atualmente (esta em codigo morto), mas demonstra um bug latente.

**Recomendacao:** Corrigir a assinatura ou remover o metodo morto.

---

## 4. ARQUITETURA DDD

### 4.1 Separacao de Camadas

**Severidade: INFO**

A separacao Domain/Application/Infrastructure esta GERALMENTE respeitada:

- **Domain NAO importa Infrastructure:** CONFIRMADO (0 ocorrencias de `use LimpVix\Infrastructure\` em Domain)
- **Domain NAO importa Admin:** CONFIRMADO (0 ocorrencias)
- **Domain eh autocontido:** Entidades, Value Objects, Enums, Events, Exceptions, Repository Interfaces

### 4.2 Violacoes DDD: Application Importando Infrastructure Concreta

**Severidade: HIGH**

A camada Application importa **32 classes concretas** de Infrastructure, violando o Dependency Inversion Principle:

| Arquivo Application | Importa de Infrastructure |
|---------------------|--------------------------|
| `UseCases/Verification/RunVerificationPipeline.php` | `VerificationProviderFactory` |
| `UseCases/Financial/CreateManualPayout.php` | `PlatformFeeConfig`, `WpMarketplaceProfessionalRepository` |
| `UseCases/ExecuteTransfer.php` | `WpPayoutRepository`, `MercadoPagoPayoutProvider` |
| `UseCases/Feedback/CheckFeedbackWindowStatus.php` | `WpStructuredFeedbackRepository` |
| `UseCases/Briefing/AssessComplexity.php` | `WpBriefingRepository` |
| `UseCases/Briefing/CalculateProfessionalsRequired.php` | `WpBriefingLedgerRepository` |
| `UseCases/Briefing/SelectPackage.php` | `WpBriefingRepository` |
| `Services/CustomerNotifier.php` | `NVoipOtpProvider`, `TwilioOtpProvider` |
| `Services/PayoutReconciliationService.php` | `WpPayoutRepository`, `MercadoPagoPayoutProvider` |
| `UseCase/Auth/SendOtp.php` | `OTPServiceInterface` (aceite: eh interface) |
| `UseCase/Professional/AcceptOffer.php` | `WpMarketplaceProfessionalRepository` |
| `UseCase/Professional/ProcessKYC.php` | `PPIDProviderFactory` |
| `UseCase/Contract/CancelContract.php` | `WordPressEventDispatcher` |
| `UseCase/Contract/CompleteContract.php` | `WordPressEventDispatcher` |
| `UseCase/Contract/ExpireContract.php` | `WordPressEventDispatcher` |
| `UseCase/Contract/ReallocateProfessional.php` | `WpExecutionRepository` |
| `UseCase/Contract/RenewContract.php` | `WordPressEventDispatcher` |
| `UseCase/Contract/ResumeContract.php` | `WordPressEventDispatcher` |
| `UseCase/Professional/ListOffers.php` | `WpMarketplaceProfessionalRepository` |
| `UseCase/Professional/GetScoreHistory.php` | `WpMarketplaceProfessionalRepository` |
| `UseCase/Professional/UpdateAvailability.php` | `WpMarketplaceProfessionalRepository` |
| `UseCase/Professional/GetAllocationHistory.php` | `WpMarketplaceProfessionalRepository` |
| `UseCase/Professional/ListProfessionals.php` | `WpMarketplaceProfessionalRepository` |
| `UseCase/Professional/RejectOffer.php` | `WpMarketplaceProfessionalRepository` |

**Nota:** Importar `OTPServiceInterface` de Infrastructure eh aceitavel pois eh uma interface (nao violacao direta), mas idealmente interfaces que Application consome deveriam estar no Domain.

**Recomendacao:** Use Cases devem receber dependencias via constructor injection (interfaces definidas no Domain). O bootstrap/factory eh que resolve as implementacoes concretas.

---

### 4.3 Interface Sem Implementacao: IssueRepositoryInterface

**Severidade: MEDIUM**

| Interface | Local | Implementacao |
|-----------|-------|---------------|
| `IssueRepositoryInterface` | `src/Domain/Execution/IssueRepositoryInterface.php` | **NENHUMA** |

Nenhuma classe implementa `IssueRepositoryInterface`. O Use Case `ReportIssue` provavelmente nao funciona.

**Recomendacao:** Implementar `WpIssueRepository` ou remover a interface se nao sera usada.

---

### 4.4 Interface Sem Implementacao: BookingEngineInterface

**Severidade: MEDIUM**

| Interface | Local | Implementacao |
|-----------|-------|---------------|
| `BookingEngineInterface` | `src/Infrastructure/BookingEngine/BookingEngineInterface.php` | **NENHUMA** |

Era prevista para abstrair o motor de agendamento (Booknetic/nativo) mas nunca foi implementada.

**Recomendacao:** Implementar `NativeBookingEngine` ou remover se o conceito de "appointment" foi substituido por "execution".

---

### 4.5 OTPServiceInterface em Local Incorreto

**Severidade: LOW**

`OTPServiceInterface` esta em `src/Infrastructure/SMS/OTPServiceInterface.php`, mas sendo uma interface que Application consome, deveria estar em `src/Domain/` ou `src/Application/Contracts/`.

**Recomendacao:** Mover para `src/Domain/Communication/OTPServiceInterface.php`.

---

### 4.6 AuthorizationPolicyInterface em Local Incorreto

**Severidade: LOW**

`AuthorizationPolicyInterface` esta em `src/Infrastructure/Authorization/AuthorizationPolicyInterface.php`. Interfaces de contrato deveriam estar no Domain.

**Recomendacao:** Mover para `src/Domain/Authorization/AuthorizationPolicyInterface.php`.

---

### 4.7 Duas Interfaces ProfessionalRepositoryInterface

**Severidade: MEDIUM**

| Interface | Local | Implementacao |
|-----------|-------|---------------|
| `ProfessionalRepositoryInterface` | `src/Domain/Professional/` | `WpMarketplaceProfessionalRepository` |
| `ProfessionalRepositoryInterface` | `src/Domain/Scheduling/Repositories/` | `WpProfessionalRepository` |

Mesmo nome de interface em dois bounded contexts diferentes. Ambas tem implementacoes, mas o nome identico causa confusao no autoloader e em imports.

**Recomendacao:** Renomear para nomes mais especificos: `MarketplaceProfessionalRepositoryInterface` e `SchedulingProfessionalRepositoryInterface`.

---

## 5. DEPENDENCIAS E AUTOLOAD

### 5.1 composer.json

**Severidade: INFO**

```json
{
    "name": "limpvix/core",
    "description": "LimpVix Core - Camada de governanca sobre Booknetic",
    "autoload": {
        "psr-4": {
            "LimpVix\\": "src/"
        }
    }
}
```

O PSR-4 autoload esta correto: `LimpVix\` mapeia para `src/`.

Dependencias:
- `psr/log` ^1.1|^2.0|^3.0
- `firebase/php-jwt` ^6.0
- `google/auth` ^1.28

**Observacao:** Nao ha dependencia do Booknetic no require (correto).

---

### 5.2 Autoloader Fallback Manual

**Severidade: INFO**

O `limpvix-core.php` tem autoloader fallback manual (linhas 40-55) caso `vendor/autoload.php` nao exista. Eh funcional mas menos performatico que o otimizado do Composer.

---

### 5.3 God Object: AdminBootstrap.php

**Severidade: HIGH**

O arquivo `src/Admin/Bootstrap/AdminBootstrap.php` tem **7.124 linhas**. Isso eh um God Object classico que viola o Single Responsibility Principle.

Conteudo estimado:
- Registro de menus (~130 linhas)
- Registro de assets (~30 linhas)
- Controller lazy-loading (~50 linhas)
- renderSettingsPage com 12+ abas (~1500+ linhas de HTML inline)
- renderDependenciasTab (~400+ linhas)
- renderDashboardPage (~200+ linhas)
- Multiplos renders de tabs (~4000+ linhas total)
- Health check, cron monitor, etc.

**Recomendacao:** Extrair cada aba de Settings para classes dedicadas em `src/Admin/Settings/Tabs/`:
- `GeralTab.php`
- `ConexoesTab.php`
- `ComunicacaoTab.php`
- `BriefingTab.php`
- `ProfissionaisTab.php`
- `TemplatesTab.php`
- `FluxosTab.php`
- `PagamentosTab.php`
- `CronTab.php`
- `DependenciasTab.php`
- `RiskTab.php`
- `FeedbackTab.php`
- `EquipeTab.php`

---

### 5.4 Diretorios Orfaos

**Severidade: LOW**

| Diretorio | Status |
|-----------|--------|
| `src/Integration/` | Vazio -- pode ser removido (integracao real esta em `src/Infrastructure/Integration/`) |
| `src/Database/` | Contem `Migrations/` vazio -- pode ser removido |
| `src/Frontend/` | Apenas `FrontendGuards.php` -- verificar se esta ativo |
| `modules/` | Vazio na raiz -- modulo legado removido |

---

### 5.5 Inconsistencia: CommunicationBootstrap Duplicado

**Severidade: MEDIUM**

Existem DOIS CommunicationBootstrap:
- `src/Core/CommunicationBootstrap.php` -- referenciado pelo Kernel (linha 131-133)
- `src/Infrastructure/Communication/CommunicationBootstrap.php` -- referenciado pelo entry point (limpvix-core.php linhas 97-99, 160-162, 189-191)

Ambos sao inicializados na cadeia de boot, potencialmente duplicando registros.

**Recomendacao:** Unificar em um unico CommunicationBootstrap com protecao contra re-boot.

---

### 5.6 error_log em registerMenu Sem WP_DEBUG Guard

**Severidade: LOW**

No `AdminBootstrap.php`, linhas 141-149:

```php
public function registerMenu(): void
{
    error_log("=== AdminBootstrap::registerMenu() CALLED ===");
    error_log("FinanceCapabilities::canView(): " . ...);
    // ...
    error_log("Creating main menu with slug: " . self::MENU_SLUG);
```

Estas chamadas executam em TODA request admin, sem condicional `WP_DEBUG`.

**Recomendacao:** Remover ou envolver em `WP_DEBUG`.

---

## 6. ACHADOS ADICIONAIS

### 6.1 SQL Injection Potencial no renderDependenciasTab

**Severidade: MEDIUM**

Linha 528:
```php
$tableExists = $wpdb->get_var("SHOW TABLES LIKE '$tableName'") === $tableName;
```

O `$tableName` usa `$wpdb->prefix` que vem de configuracao, nao de input do usuario, portanto o risco eh BAIXO. Porem, a melhor pratica eh usar `$wpdb->prepare()`.

**Recomendacao:** Usar `$wpdb->prepare("SHOW TABLES LIKE %s", $tableName)`.

---

### 6.2 Uso de $GLOBALS para Dependency Injection

**Severidade: LOW**

O Kernel usa `$GLOBALS` para disponibilizar servicos:
- `$GLOBALS['limpvix_transaction_manager']`
- `$GLOBALS['limpvix_authorization_service']`
- `$GLOBALS['limpvix_environment']`

**Recomendacao:** Migrar para Service Container (`src/Core/ServiceContainer.php` ja existe).

---

## APENDICE A: LISTA COMPLETA DE ACHADOS

| # | Severidade | Secao | Resumo |
|---|-----------|-------|--------|
| 1 | CRITICAL | 3.3 | Bug: register + unregister roles no activation hook |
| 2 | CRITICAL | 3.4 | Bug: nesting errado impede salvar Twilio/Exato |
| 3 | CRITICAL | 3.5 | Metodo logError() ausente no Kernel |
| 4 | HIGH | 1.1 | Residuos Booknetic em codigo ativo (AdminBootstrap.php) |
| 5 | HIGH | 2.1 | Duplicacao UseCase vs UseCases com RenewContract duplicado |
| 6 | HIGH | 3.2 | AdapterBootstrap inicializado 2x sem protecao |
| 7 | HIGH | 4.2 | 32 violacoes DDD: Application importa Infrastructure concreta |
| 8 | HIGH | 5.3 | AdminBootstrap.php God Object (7.124 linhas) |
| 9 | HIGH | 2.2 | Finance vs Financial namespace split sem criterio |
| 10 | HIGH | 5.5 | CommunicationBootstrap duplicado (Core + Infrastructure) |
| 11 | HIGH | 1.1 | $allPluginsActive sempre false (logica quebrada) |
| 12 | MEDIUM | 1.2 | Residuos Booknetic em migrations SQL (comments) |
| 13 | MEDIUM | 1.3 | Residuos Booknetic em testes E2E (bkntc_staff) |
| 14 | MEDIUM | 1.6 | Residuos Booknetic em composer.json e README |
| 15 | MEDIUM | 1.7 | BookingEngineInterface sem implementacao |
| 16 | MEDIUM | 2.4 | Tres locais de migrations |
| 17 | MEDIUM | 3.6 | 454 error_log() em 119 arquivos (muitos sem WP_DEBUG) |
| 18 | MEDIUM | 4.3 | IssueRepositoryInterface sem implementacao |
| 19 | MEDIUM | 4.4 | BookingEngineInterface sem implementacao |
| 20 | MEDIUM | 4.7 | Duas interfaces ProfessionalRepositoryInterface homonimas |
| 21 | MEDIUM | 6.1 | SQL sem $wpdb->prepare() |
| 22 | MEDIUM | 5.5 | CommunicationBootstrap duplicado |
| 23 | LOW | 1.4 | 13 arquivos backup/broken no src/ |
| 24 | LOW | 1.5 | Residuo Booknetic em CSS comment |
| 25 | LOW | 2.3 | 5 diretorios vazios em src/ |
| 26 | LOW | 3.7 | Hook malformado em registerPasso3Hooks (codigo morto) |
| 27 | LOW | 4.5 | OTPServiceInterface em local incorreto (Infrastructure vs Domain) |
| 28 | LOW | 4.6 | AuthorizationPolicyInterface em local incorreto |
| 29 | LOW | 5.4 | Diretorios orfaos (Integration/, Database/) |
| 30 | LOW | 5.6 | error_log() sem guard em registerMenu |
| 31 | INFO | 4.1 | Domain isolado corretamente (0 imports de Infrastructure) |
| 32 | INFO | 5.1 | composer.json PSR-4 correto |
| 33 | INFO | 5.2 | Autoloader fallback funcional |
| 34 | INFO | 6.2 | $GLOBALS para DI (ServiceContainer existe mas nao usado) |

---

## APENDICE B: PRIORIDADE DE CORRECAO SUGERIDA

### Sprint Imediato (CRITICAL)

1. **Corrigir activation hook** -- mover `UserRoles::unregister()` para deactivation hook
2. **Corrigir nesting no renderSettingsPage** -- desaninhar blocos Twilio/Exato
3. **Adicionar metodo logError()** no Kernel

### Sprint Curto Prazo (HIGH)

4. Remover residuos Booknetic do AdminBootstrap.php (linhas 520, 524, 1934)
5. Corrigir logica `$allPluginsActive` (remover variavel Booknetic)
6. Adicionar flag `$booted` ao AdapterBootstrap ou remover chamada duplicada
7. Consolidar UseCase/ e UseCases/ em um unico diretorio
8. Renomear classes RenewContract homonimas

### Sprint Medio Prazo (MEDIUM)

9. Refatorar AdminBootstrap.php em classes menores
10. Atualizar comments nas migrations SQL
11. Refatorar testes E2E para nao usar bkntc_staff
12. Atualizar composer.json description e README.md
13. Implementar WpIssueRepository ou remover interface
14. Consolidar Finance/Financial namespaces
15. Centralizar logging
16. Consolidar migrations em um unico local

---

*Fim do documento de auditoria 1/3.*
*Proximo: 02-AUDIT-SECURITY-DATABASE-API (seguranca, banco de dados e API)*
