# MAPEAMENTO COMPLETO DE DEPENDENCIAS PARA PURGE

| Campo | Valor |
|---|---|
| **Data** | 2026-02-18 |
| **Agente** | 1/4 - Mapeamento de Dependencias |
| **Plugin** | limpvix-core 0.2.0 |
| **Ambiente** | WordPress 6.8.2 / PHP 8.2.29 / MySQL 8.0 |
| **Container** | limpvix_wordpress_clean |

---

## RESUMO EXECUTIVO

O plugin limpvix-core possui residuos do Booknetic em **4 arquivos PHP ativos**, **3 testes E2E**, **5 migrations SQL**, **1 arquivo CSS** e **2 arquivos backup**. A interface `BookingEngineInterface` permanece orfao sem nenhuma implementacao. No banco de dados existem **21 tabelas `wp_bkntc_*`** com dados desprezaveis (apenas 8 rows totais -- 7 em `appearance` e 1 em `timesheet`). O diretorio do Booknetic no container ocupa **25 MB** com **1874 arquivos** -- totalmente desnecessarios.

O MercadoPago, ao contrario do Booknetic, e uma dependencia **ativa e funcional** com **38 arquivos** no `src/` referenciando-o. Atua como **fallback** do EFI Bank tanto para Cash-In (PaymentProvider) quanto para Cash-Out (PayoutProvider). Sua remocao NaO e recomendada agora -- apenas a **remodelagem como provider secundario** devidamente abstraido atras das interfaces `PaymentProviderInterface` e `PayoutProviderInterface`.

### Numeros-chave

| Item | Quantidade |
|---|---|
| Booknetic - referencias em codigo PHP ativo | 5 linhas em 2 arquivos |
| Booknetic - referencias em backups (.backup.twilio, .broken) | 50+ linhas em 2 arquivos |
| Booknetic - referencias em migrations SQL | 8 linhas em 4 arquivos |
| Booknetic - referencias em testes E2E | 6 linhas em 3 arquivos |
| Booknetic - referencias em CSS | 1 linha em 1 arquivo |
| Booknetic - interface orfao (BookingEngineInterface) | 1 arquivo inteiro |
| Booknetic - tabelas no banco (wp_bkntc_*) | 21 tabelas, 8 rows totais |
| Booknetic - diretorio no container | 25 MB, 1874 arquivos |
| MercadoPago - arquivos PHP em src/ | 38 arquivos |
| MercadoPago - migrations com referencia mp_ | 3 arquivos |
| MercadoPago - e dependencia ATIVA | SIM (fallback do EFI Bank) |

---

## 1. BOOKNETIC -- MAPEAMENTO EXAUSTIVO

### 1.1 Codigo PHP Ativo (src/)

| Arquivo | Linha | Conteudo | Tipo | Impacto se Removido | Acao |
|---|---|---|---|---|---|
| `src/Admin/Bootstrap/AdminBootstrap.php` | 520 | `$isBookneticActive = false; // agendador externo nao usado` | ATIVO | SAFE | Remover variavel |
| `src/Admin/Bootstrap/AdminBootstrap.php` | 524 | `$allPluginsActive = $isBookneticActive && $isWooCommerceActive && $isMercadoPagoActive;` | ATIVO | NEEDS_REPLACEMENT | Reescrever: `$allPluginsActive = $isWooCommerceActive;` |
| `src/Admin/Bootstrap/AdminBootstrap.php` | 1934 | `'booknetic' => false, // Booknetic removido` | ATIVO | SAFE | Remover entrada do array |
| `src/Infrastructure/BookingEngine/BookingEngineInterface.php` | 1-112 | Interface completa (112 linhas) | ATIVO | SAFE | Deletar arquivo inteiro |

**NOTA CRITICA**: A linha 524 contem logica QUEBRADA. `$allPluginsActive` e SEMPRE `false` pois `$isBookneticActive` e hardcoded como `false`. Isso faz com que:
- Linha 568: `$readyForGoLive = ... && $allPluginsActive && ...` -- SEMPRE `false`
- Linha 592: Quick Stats mostra `$allPluginsActive ? 'checkmark' : 'X'` -- SEMPRE `X`

### 1.2 Arquivos Backup (nao executados mas poluem o repositorio)

| Arquivo | Linhas com ref. | Tipo | Acao |
|---|---|---|---|
| `src/Admin/Bootstrap/AdminBootstrap.php.backup.twilio` | 443, 447, 477-498, 563-922, 1108, 1268 | BACKUP | Deletar arquivo inteiro |
| `src/Admin/Bootstrap/AdminBootstrap.php.broken` | 386-387 | BACKUP | Deletar arquivo inteiro |

### 1.3 Migrations SQL

| Arquivo | Linha | Conteudo | Tipo | Acao |
|---|---|---|---|---|
| `database-migrations/001_create_orders_table.sql` | 16 | `appointment_id BIGINT UNSIGNED NULL COMMENT 'ID do appointment no Booknetic'` | MIGRATION | Manter coluna, mudar COMMENT para 'Legacy appointment ID (unused)' |
| `database-migrations/015_create_financial_ledger_table.sql` | 27 | `appointment_id BIGINT(20) UNSIGNED NULL COMMENT 'ID do appointment Booknetic'` | MIGRATION | Manter coluna, mudar COMMENT |
| `database-migrations/013_create_scheduling_tables.sql` | 54 | `professional_id INT NOT NULL COMMENT 'Staff ID do Booknetic'` | MIGRATION | Mudar COMMENT para 'Professional ID' |
| `database-migrations/013_create_scheduling_tables.sql` | 76, 83, 108, 144, 175 | FKs comentadas referenciando `wp_bkntc_staff` | COMENTADO | Remover linhas comentadas |
| `database-migrations/014_create_structured_feedback_tables.sql` | 81 | FK comentada referenciando `wp_bkntc_staff` | COMENTADO | Remover linha comentada |
| `database-migrations/MIGRATIONS-AUDIT-REPORT.md` | 53 | Texto sobre FKs para `wp_bkntc_staff` | STRING/DOC | Atualizar documentacao |

### 1.4 Testes E2E

| Arquivo | Linhas | Conteudo | Tipo | Acao |
|---|---|---|---|---|
| `tests/E2E/ExecutionCompleteFlowTest.php` | 235, 446 | INSERT/DELETE em `bkntc_staff` | TEST | Substituir por INSERT em `limpvix_professionals` |
| `tests/E2E/ProfessionalCompleteFlowTest.php` | 220, 403 | INSERT/DELETE em `bkntc_staff` | TEST | Substituir por INSERT em `limpvix_professionals` |
| `tests/E2E/ContractCompleteFlowTest.php` | 321, 483 | INSERT/DELETE em `bkntc_staff` | TEST | Substituir por INSERT em `limpvix_professionals` |

### 1.5 Assets

| Arquivo | Linha | Conteudo | Tipo | Acao |
|---|---|---|---|---|
| `assets/css/limpvix-admin.css` | 3 | `* Baseado no design Booknetic` | STRING/COMMENT | Remover referencia do comentario |

### 1.6 composer.json

| Arquivo | Linha | Conteudo | Tipo | Acao |
|---|---|---|---|---|
| `composer.json` | 3 | `"description": "LimpVix Core - Camada de governanca sobre Booknetic"` | STRING | Mudar para: `"Motor de negocios da plataforma LimpVix"` |

### 1.7 Docs e Markdown (raiz do plugin)

Multiplos arquivos `.md` na raiz e em `docs/` referenciam Booknetic para contexto historico. Estes sao documentacao de auditoria e NAO afetam execucao. Decisao: manter como historico ou limpar em batch separado.

Arquivos com referencias:
- `docs/00-AUDIT-EXECUTIVE-SUMMARY.md`
- `docs/01-AUDIT-ARCHITECTURE-STRUCTURE.md`
- `DEPENDENCIAS_OBSERVACOES.md`
- `CHANGELOG.md`
- Outros docs de auditoria

---

## 2. MERCADOPAGO -- MAPEAMENTO EXAUSTIVO

### 2.1 Arquivos Core (Providers)

| Arquivo | Tipo | Funcao | Pode Remover? |
|---|---|---|---|
| `src/Infrastructure/Finance/Providers/MercadoPagoPayoutProvider.php` | PROVIDER | Cash-Out (payout para profissionais) via API MP | NAO -- fallback ativo do EFI Bank |
| `src/Infrastructure/Finance/Providers/MercadoPagoPaymentProvider.php` | PROVIDER | Cash-In (cobranca de clientes) via API MP | NAO -- fallback ativo do EFI Bank |
| `src/Domain/Finance/PayoutProviderInterface.php` | INTERFACE | Contrato abstrato para payouts | MANTER (interface, nao e MP-especifico) |
| `src/Domain/Finance/PaymentProviderInterface.php` | INTERFACE | Contrato abstrato para payments | MANTER (interface, nao e MP-especifico) |

### 2.2 Admin Settings

| Arquivo | Tipo | Funcao | Pode Remover? |
|---|---|---|---|
| `src/Admin/Settings/MercadoPagoSettings.php` | SETTINGS | UI de configuracao MP no admin | PRECISA_ABSTRACAO -- manter como modulo opcional |
| `src/Admin/Settings/MercadoPagoDetector.php` | DETECTION | Detecta plugin oficial woocommerce-mercadopago, sincroniza credenciais | PRECISA_ABSTRACAO -- importante para auto-config |
| `src/Admin/Settings/assets/css/limpvix-mp.css` | UI | Estilos do painel MP | Acompanha MercadoPagoSettings |
| `src/Admin/Settings/assets/js/limpvix-mp.js` | UI | Scripts AJAX do painel MP | Acompanha MercadoPagoSettings |

### 2.3 Webhook e API

| Arquivo | Tipo | Funcao | Pode Remover? |
|---|---|---|---|
| `src/Infrastructure/API/Controllers/MercadoPagoWebhookController.php` | WEBHOOK | Recebe callbacks do MP (payment.updated) | NAO -- essencial para fluxo MP |
| `src/Infrastructure/API/ProfessionalOAuthController.php` | PROVIDER | OAuth para profissionais conectarem conta MP | PRECISA_ABSTRACAO -- util para payout direto |

### 2.4 Bootstrap e Inicializacao

| Arquivo | Linha(s) | Referencia | Tipo |
|---|---|---|---|
| `src/Admin/Bootstrap/AdminBootstrap.php` | 522 | `$isMercadoPagoActive = is_plugin_active('woocommerce-mercadopago/...')` | BOOTSTRAP |
| `src/Admin/Bootstrap/AdminBootstrap.php` | 524 | Parte da logica `$allPluginsActive` | BOOTSTRAP |
| `src/Admin/Bootstrap/AdminBootstrap.php` | 1933 | `'Mercado Pago' => MercadoPagoDetector::isOfficialPluginConnected()` | BOOTSTRAP |
| `src/Core/ContractBootstrap.php` | 222-229 | EFI primario, MP como fallback para PaymentProvider | BOOTSTRAP |
| `src/Core/ContractBootstrap.php` | 395-408 | Registro do MercadoPagoWebhookController | BOOTSTRAP |
| `src/Core/Hooks.php` | 142-164 | Comentarios sobre MercadoPagoPayoutProvider | STRING |
| `src/Core/Kernel.php` | 212 | `// Nao obrigar MercadoPago pois pode vir do WooCommerce plugin` | STRING |
| `src/Infrastructure/Adapters/AdapterBootstrap.php` | 25, 86-88 | `MercadoPagoPayoutProvider` import + instanciacao como fallback | BOOTSTRAP |

### 2.5 Use Cases e Domain

| Arquivo | Referencia | Tipo |
|---|---|---|
| `src/Application/UseCases/Finance/ChargeRecurringPayment.php` | Usa `PaymentProviderInterface` (agnositco) | ABSTRATO |
| `src/Application/UseCases/Finance/ProcessPaymentWebhook.php` | Usa `PaymentProviderInterface` (agnostico) | ABSTRATO |
| `src/Application/UseCases/Finance/RetryFailedPayment.php` | Usa `PaymentProviderInterface` (agnostico) | ABSTRATO |
| `src/Application/UseCases/Financial/ExecutePayout.php` | Usa `PayoutProviderInterface` (agnostico) | ABSTRATO |
| `src/Application/UseCases/Financial/CreateManualPayout.php` | Referencia MP em comentarios | STRING |
| `src/Application/Services/PayoutReconciliationService.php` | Referencia MP em comentarios | STRING |
| `src/Application/UseCases/ExecuteTransfer.php` | Referencia MP em comentarios | STRING |

### 2.6 Database Migrations com MercadoPago

| Arquivo | Conteudo | Tipo |
|---|---|---|
| `database-migrations/027_add_payout_dual_mode_fields.sql` | Colunas `mp_oauth_status`, `mp_access_token`, `mp_refresh_token`, `mp_user_id`, `mp_oauth_connected_at`, `mp_oauth_expires_at` em `limpvix_professionals`; coluna `payout_method` ENUM('mp_oauth','pix_manual') em `limpvix_payouts` | MIGRATION |
| `database-migrations/run-027-migration.php` | PHP runner para migration 027 | MIGRATION |
| `database-migrations/018_add_recurring_payments.sql` | Comentarios sobre MercadoPago no design da tabela | STRING |
| `database-migrations/029_add_recurring_payment_execution_fields.sql` | Coluna `gateway` com valor `'mercadopago'` como opcao | MIGRATION |
| `src/Infrastructure/Database/Migrations/CreateMercadoPagoPayoutsTable.php` | Cria tabela `wp_limpvix_payouts` (nome generico, apesar do nome da classe) | MIGRATION |

### 2.7 Infraestrutura Auxiliar

| Arquivo | Referencia | Tipo |
|---|---|---|
| `src/Infrastructure/Security/TokenEncryption.php` | Encripta tokens MP OAuth | PROVIDER |
| `src/Infrastructure/Providers/WordPressCredentialProvider.php` | Busca credenciais MP de wp_options | PROVIDER |
| `src/Infrastructure/Cron/PayoutReconciliationCronAdapter.php` | Sincroniza status de payouts com MP | PROVIDER |
| `src/Infrastructure/Cron/PaymentAuthorizationTimeoutCronAdapter.php` | Timeout de autorizacoes MP | PROVIDER |
| `src/Domain/Staff/StaffFinancialStatusResolver.php` | Referencia MP OAuth status | PROVIDER |
| `src/Infrastructure/Admin/Pages/PayoutsPage.php` | UI de payouts mostra info MP | UI |
| `src/Admin/Settings/TestVendorsManager.php` | Testa vendor MP | SETTINGS |

### 2.8 Configuracao (.env)

| Chave | Descricao |
|---|---|
| `LIMPVIX_MP_ACCESS_TOKEN_PROD` | Token producao |
| `LIMPVIX_MP_PUBLIC_KEY_PROD` | Public key producao |
| `LIMPVIX_MP_ACCESS_TOKEN_TEST` | Token teste |
| `LIMPVIX_MP_PUBLIC_KEY_TEST` | Public key teste |
| `LIMPVIX_MP_ENVIRONMENT` | Ambiente (sandbox/production) |

### 2.9 Avaliacao MercadoPago

O MercadoPago esta **bem abstraido** atras de interfaces (`PaymentProviderInterface`, `PayoutProviderInterface`). Os use cases (ChargeRecurringPayment, ExecutePayout, ProcessPaymentWebhook, RetryFailedPayment) sao **agnosticos ao provider** -- recebem a interface, nao a implementacao concreta.

A selecao de provider acontece em apenas **2 pontos**:
1. `src/Core/ContractBootstrap.php` linhas 222-229 (EFI primario, MP fallback para Cash-In)
2. `src/Infrastructure/Adapters/AdapterBootstrap.php` linhas 84-88 (EFI primario, MP fallback para Cash-Out)

**Conclusao**: MercadoPago NAO deve ser removido agora. E o provider de fallback. A arquitetura ja esta correta com interfaces abstratas.

---

## 3. TABELAS BOOKNETIC NO BANCO (wp_bkntc_*)

### 3.1 Listagem Completa

| Tabela | Rows | Referenciada no limpvix-core? | Acao |
|---|---|---|---|
| `wp_bkntc_appearance` | 7 | NAO | DROP |
| `wp_bkntc_appointment_extras` | 0 | NAO | DROP |
| `wp_bkntc_appointment_prices` | 0 | NAO | DROP |
| `wp_bkntc_appointments` | 0 | NAO (era via appointment_id na orders) | DROP |
| `wp_bkntc_cart` | 0 | NAO | DROP |
| `wp_bkntc_customers` | 0 | NAO | DROP |
| `wp_bkntc_data` | 0 | NAO | DROP |
| `wp_bkntc_holidays` | 0 | NAO | DROP |
| `wp_bkntc_locations` | 0 | NAO | DROP |
| `wp_bkntc_service_categories` | 0 | NAO | DROP |
| `wp_bkntc_service_extra_categories` | 0 | NAO | DROP |
| `wp_bkntc_service_extras` | 0 | NAO | DROP |
| `wp_bkntc_service_staff` | 0 | NAO | DROP |
| `wp_bkntc_services` | 0 | NAO | DROP |
| `wp_bkntc_special_days` | 0 | NAO | DROP |
| `wp_bkntc_staff` | 0 | SIM (testes E2E apenas) | DROP |
| `wp_bkntc_timesheet` | 1 | NAO | DROP |
| `wp_bkntc_translations` | 0 | NAO | DROP |
| `wp_bkntc_workflow_actions` | 0 | NAO | DROP |
| `wp_bkntc_workflow_logs` | 0 | NAO | DROP |
| `wp_bkntc_workflows` | 0 | NAO | DROP |

**Total: 21 tabelas, 8 rows (dados irrelevantes).**

### 3.2 Queries limpvix-core que acessam tabelas bkntc_

Verificacao exaustiva no `src/`: **NENHUMA query SQL no codigo-fonte ativo do limpvix-core acessa tabelas `bkntc_`**. As unicas referencias estao em:
- Testes E2E (INSERT/DELETE em `bkntc_staff` para setup de fixtures)
- Comentarios/FKs comentadas em migrations SQL
- Coluna `appointment_id` em `limpvix_orders` e `limpvix_financial_ledger` (coluna existe mas nao e populada)

### 3.3 Migracao de dados bkntc_ para limpvix_

NAO existe nenhum script de migracao de dados do Booknetic para tabelas limpvix_. Todos os profissionais ja estao na tabela `wp_limpvix_professionals` (nativa). A coluna `appointment_id` em `limpvix_orders` e NULL em todos os registros (confirmado pela contagem de 0 rows em `bkntc_appointments`).

---

## 4. BOOKNETIC FILES NO CONTAINER

| Metrica | Valor |
|---|---|
| Diretorio | `/var/www/html/wp-content/plugins/booknetic/` |
| Total de arquivos | 1874 |
| Tamanho total | 25 MB |
| Plugin ativo? | NAO (desativado) |
| Necessario para limpvix-core? | NAO |

**Acao**: Remover diretorio inteiro do container.

---

## 5. DEPENDENCIAS CRUZADAS BOOKNETIC

### Funcoes do Booknetic usadas: NENHUMA
### Classes do Booknetic usadas: NENHUMA
### Tabelas do Booknetic acessadas em runtime: NENHUMA
### Hooks/Filters do Booknetic registrados: NENHUMA
### Assets do Booknetic carregados: NENHUMA

A unica dependencia residual e **estrutural/textual** (variaveis, comentarios, strings). Nao ha dependencia funcional.

---

## 6. OUTRAS DEPENDENCIAS EXTERNAS

### 6.1 WooCommerce

| Aspecto | Detalhe |
|---|---|
| **Tipo** | HARD dependency |
| **Declarada em** | `limpvix-core.php` linha 9: `Requires Plugins: woocommerce` |
| **Check de ativacao** | `limpvix-core.php` linhas 124-128: `wp_die()` se WC nao esta ativo |
| **Funcoes usadas** | `wc_get_order()`, `WC()`, `WC_Product_Simple`, `WC()->cart->add_to_cart()` |
| **Adapters** | `WooCommercePaymentAdapter`, `WooCommerceStatusSyncAdapter`, `WooCommerceBriefingAdapter`, `BriefingPaymentAdapter` |
| **Guard** | `AdapterBootstrap.php` linha 108: `if (!class_exists('WooCommerce'))` -- adapters WC nao registrados |
| **Impacto sem WC** | Plugin NAO ativa. Fatal error no activation hook. |

**Conclusao**: WooCommerce e dependencia HARD e OBRIGATORIA. Nao pode ser removida.

### 6.2 EFI Bank / Gerencianet

| Aspecto | Detalhe |
|---|---|
| **Tipo** | SOFT dependency (configuravel) |
| **Providers** | `EfiPayoutProvider` (Cash-Out), `EfiPaymentProvider` (Cash-In) |
| **Settings** | `EfiBankSettings` -- gerencia credenciais no admin |
| **WC Plugin** | `woo-gerencianet-official` -- detectado/sincronizado, nao obrigatorio |
| **Impacto sem EFI** | MercadoPago assume como fallback. Sistema funciona normalmente. |
| **Configuracao** | wp_options: `limpvix_efi_client_id`, `limpvix_efi_client_secret`, `limpvix_efi_pix_key`, etc. |

**Conclusao**: SOFT dependency. O sistema tem graceful degradation via fallback para MercadoPago.

### 6.3 Twilio

| Aspecto | Detalhe |
|---|---|
| **Tipo** | SOFT dependency |
| **Provider** | `TwilioOtpProvider` -- OTP via Twilio Verify API |
| **Outro Provider** | `TwilioSmsProvider` -- SMS direto via Twilio Messages API |
| **Configuracao** | wp_options ou constantes: `LIMPVIX_TWILIO_ACCOUNT_SID`, `LIMPVIX_TWILIO_AUTH_TOKEN`, etc. |
| **Impacto sem Twilio** | OTP e SMS nao funcionam via Twilio. NVoip pode ser alternativa. |
| **Usa SDK?** | NAO -- usa `wp_remote_post()` direto para API REST do Twilio |

**Conclusao**: SOFT dependency. Alternativa NVoip disponivel.

### 6.4 Firebase

| Aspecto | Detalhe |
|---|---|
| **Tipo** | SOFT dependency (Composer: `firebase/php-jwt`) |
| **Uso 1** | `FirebaseAuthAdapter` -- valida tokens Firebase para auth de briefings |
| **Uso 2** | `JwtService` -- usa `Firebase\JWT\JWT` e `Firebase\JWT\Key` para JWT interno |
| **Composer** | `"firebase/php-jwt": "^6.0"` |
| **Impacto sem Firebase** | JWT interno quebra (usa a lib php-jwt). Auth de briefings via Firebase nao funciona. |

**Conclusao**: MIXED. A lib `firebase/php-jwt` e HARD (usada para JWT interno). A integracao Firebase Authentication e SOFT.

### 6.5 Google Auth / Google Business

| Aspecto | Detalhe |
|---|---|
| **Tipo** | SOFT dependency |
| **Composer** | `"google/auth": "^1.28"` |
| **Uso** | `FirebaseAuthAdapter` -- busca chaves publicas via `Google\Auth\AccessToken` |
| **Google Business** | `GoogleBusinessSettings` -- configuracao de Place ID para reviews |
| **Impacto sem google/auth** | `FirebaseAuthAdapter` falha na validacao de tokens Firebase |

**Conclusao**: SOFT (mas necessario se usar Firebase Auth).

### 6.6 NVoip

| Aspecto | Detalhe |
|---|---|
| **Tipo** | SOFT dependency |
| **Settings** | `NVoipSettings` -- configuracao no admin |
| **Uso** | OTP via WhatsApp/SMS, alternativa ao Twilio |
| **Impacto sem NVoip** | Twilio e a alternativa. Sem ambos, comunicacao OTP nao funciona. |

**Conclusao**: SOFT dependency. Alternativa do Twilio.

### 6.7 Exato Digital (KYC)

| Aspecto | Detalhe |
|---|---|
| **Tipo** | SOFT dependency |
| **Uso** | `PPIDProviderFactory` -- verificacao KYC de profissionais |
| **Pipeline** | `RunVerificationPipeline` -- OTP -> KYC -> Background -> Risk Engine |
| **Impacto sem KYC** | Profissionais nao passam por verificacao automatica de identidade |

**Conclusao**: SOFT dependency. Pode operar sem KYC com aprovacao manual.

---

## 7. PLANO DE PURGE STEP-BY-STEP

### FASE 1: Booknetic Purge (Estimativa: 2-3 horas)

#### Step 1.1: Remover logica quebrada no AdminBootstrap (30 min)

**Arquivo**: `src/Admin/Bootstrap/AdminBootstrap.php`

- Linha 520: Remover `$isBookneticActive = false;`
- Linha 524: Reescrever para: `$allPluginsActive = $isWooCommerceActive;`
  - Ou melhor: eliminar a variavel e usar `$isWooCommerceActive` diretamente
- Linha 568: Ajustar `$readyForGoLive` para nao depender de `$allPluginsActive` da forma antiga
- Linha 1934: Remover entrada `'booknetic' => false,` do array de integracoes

#### Step 1.2: Deletar interface orfao (5 min)

- Deletar: `src/Infrastructure/BookingEngine/BookingEngineInterface.php`
- Deletar diretorio vazio: `src/Infrastructure/BookingEngine/`

#### Step 1.3: Deletar arquivos backup (5 min)

- Deletar: `src/Admin/Bootstrap/AdminBootstrap.php.backup.twilio`
- Deletar: `src/Admin/Bootstrap/AdminBootstrap.php.broken`

#### Step 1.4: Atualizar testes E2E (30 min)

Nos 3 arquivos de teste, substituir INSERT/DELETE em `bkntc_staff` por INSERT/DELETE em `limpvix_professionals`:
- `tests/E2E/ExecutionCompleteFlowTest.php`
- `tests/E2E/ProfessionalCompleteFlowTest.php`
- `tests/E2E/ContractCompleteFlowTest.php`

#### Step 1.5: Limpar migrations SQL (20 min)

- `001_create_orders_table.sql` linha 16: Mudar COMMENT de 'ID do appointment no Booknetic' para 'Legacy appointment ID (deprecated, unused)'
- `015_create_financial_ledger_table.sql` linha 27: Mudar COMMENT de 'ID do appointment Booknetic' para 'Legacy appointment ID (deprecated, unused)'
- `013_create_scheduling_tables.sql` linhas 54, 76, 83, 108, 144, 175: Mudar COMMENTs e remover FKs comentadas para bkntc_staff
- `014_create_structured_feedback_tables.sql` linha 81: Remover FK comentada

#### Step 1.6: Limpar CSS e composer.json (5 min)

- `assets/css/limpvix-admin.css` linha 3: Remover "Baseado no design Booknetic"
- `composer.json` linha 3: Mudar description de "Camada de governanca sobre Booknetic" para "Motor de negocios da plataforma LimpVix"

#### Step 1.7: DROP tabelas bkntc_ no banco (10 min)

Executar script SQL (ver secao 8).

#### Step 1.8: Remover diretorio Booknetic do container (5 min)

```bash
docker exec limpvix_wordpress_clean rm -rf /var/www/html/wp-content/plugins/booknetic/
```

#### Step 1.9: Validacao (30 min)

- Ativar/desativar plugin limpvix-core
- Verificar pagina admin carrega sem erros
- Verificar aba Dependencias mostra status correto
- Executar testes E2E ajustados
- Verificar que `$readyForGoLive` agora pode ser `true`

### FASE 2: MercadoPago Cleanup (Estimativa: 1 hora -- OPCIONAL)

NAO remover MercadoPago. Apenas cleanup:

1. Remover referencia MP da variavel `$allPluginsActive` (ja nao faz sentido exigir plugin MP instalado)
2. Mover `$isMercadoPagoActive` para ser verificacao informativa apenas
3. Garantir que o fallback EFI -> MP esta documentado
4. Considerar renomear `CreateMercadoPagoPayoutsTable` para `CreatePayoutsTable` (a tabela ja se chama `limpvix_payouts`)

---

## 8. SCRIPT SQL PARA DROP DAS TABELAS bkntc_

```sql
-- =====================================================
-- DROP de tabelas Booknetic (wp_bkntc_*)
--
-- PRE-CONDICOES:
-- 1. Booknetic esta DESATIVADO
-- 2. Backup do banco feito antes de executar
-- 3. Nenhuma query do limpvix-core acessa estas tabelas
-- 4. Total de 8 rows (dados irrelevantes)
--
-- Data: 2026-02-18
-- Motivo: Booknetic removido do stack LimpVix
-- =====================================================

-- Desabilitar verificacao de FK temporariamente
SET FOREIGN_KEY_CHECKS = 0;

-- Tabelas com dados (8 rows total)
DROP TABLE IF EXISTS wp_bkntc_appearance;
DROP TABLE IF EXISTS wp_bkntc_timesheet;

-- Tabelas vazias (0 rows cada)
DROP TABLE IF EXISTS wp_bkntc_appointment_extras;
DROP TABLE IF EXISTS wp_bkntc_appointment_prices;
DROP TABLE IF EXISTS wp_bkntc_appointments;
DROP TABLE IF EXISTS wp_bkntc_cart;
DROP TABLE IF EXISTS wp_bkntc_customers;
DROP TABLE IF EXISTS wp_bkntc_data;
DROP TABLE IF EXISTS wp_bkntc_holidays;
DROP TABLE IF EXISTS wp_bkntc_locations;
DROP TABLE IF EXISTS wp_bkntc_service_categories;
DROP TABLE IF EXISTS wp_bkntc_service_extra_categories;
DROP TABLE IF EXISTS wp_bkntc_service_extras;
DROP TABLE IF EXISTS wp_bkntc_service_staff;
DROP TABLE IF EXISTS wp_bkntc_services;
DROP TABLE IF EXISTS wp_bkntc_special_days;
DROP TABLE IF EXISTS wp_bkntc_staff;
DROP TABLE IF EXISTS wp_bkntc_translations;
DROP TABLE IF EXISTS wp_bkntc_workflow_actions;
DROP TABLE IF EXISTS wp_bkntc_workflow_logs;
DROP TABLE IF EXISTS wp_bkntc_workflows;

-- Reabilitar verificacao de FK
SET FOREIGN_KEY_CHECKS = 1;

-- Verificacao pos-DROP
SELECT COUNT(*) AS remaining_bkntc_tables
FROM information_schema.tables
WHERE table_schema = DATABASE()
AND table_name LIKE 'wp_bkntc_%';
-- Esperado: 0
```

Para executar via Docker:
```bash
docker exec -i limpvix_wordpress_clean mysql -u root -p'ROOT_PASSWORD' wordpress < drop_booknetic_tables.sql
```

Ou via WP-CLI:
```bash
docker exec limpvix_wordpress_clean wp db query "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS wp_bkntc_appearance, wp_bkntc_timesheet, wp_bkntc_appointment_extras, wp_bkntc_appointment_prices, wp_bkntc_appointments, wp_bkntc_cart, wp_bkntc_customers, wp_bkntc_data, wp_bkntc_holidays, wp_bkntc_locations, wp_bkntc_service_categories, wp_bkntc_service_extra_categories, wp_bkntc_service_extras, wp_bkntc_service_staff, wp_bkntc_services, wp_bkntc_special_days, wp_bkntc_staff, wp_bkntc_translations, wp_bkntc_workflow_actions, wp_bkntc_workflow_logs, wp_bkntc_workflows; SET FOREIGN_KEY_CHECKS=1;"
```

---

## 9. ESTIMATIVA DE ESFORCO

| Tarefa | Esforco | Risco |
|---|---|---|
| **Step 1.1**: Corrigir logica AdminBootstrap | 30 min | BAIXO |
| **Step 1.2**: Deletar BookingEngineInterface | 5 min | NENHUM |
| **Step 1.3**: Deletar backups | 5 min | NENHUM |
| **Step 1.4**: Atualizar testes E2E | 30 min | MEDIO (precisa testar) |
| **Step 1.5**: Limpar migrations SQL | 20 min | BAIXO (apenas comentarios) |
| **Step 1.6**: Limpar CSS/composer | 5 min | NENHUM |
| **Step 1.7**: DROP tabelas bkntc_ | 10 min | BAIXO (dados irrelevantes) |
| **Step 1.8**: Remover diretorio Booknetic | 5 min | NENHUM |
| **Step 1.9**: Validacao | 30 min | -- |
| **TOTAL FASE 1 (Booknetic)** | **~2.5 horas** | **BAIXO** |
| **FASE 2 (MP cleanup)** | ~1 hora | BAIXO |
| **TOTAL GERAL** | **~3.5 horas** | **BAIXO** |

---

## 10. DIAGRAMA DE DEPENDENCIAS EXTERNAS

```
limpvix-core
    |
    |-- [HARD] WooCommerce ................. Plugin WP obrigatorio
    |     |-- wc_get_order(), WC_Product_Simple, WC()->cart
    |     |-- WooCommercePaymentAdapter, WooCommerceStatusSyncAdapter
    |     |-- WooCommerceBriefingAdapter, BriefingPaymentAdapter
    |
    |-- [HARD] firebase/php-jwt ............ Composer (JWT interno)
    |     |-- JwtService (encode/decode JWT tokens)
    |
    |-- [SOFT] EFI Bank .................... Provider PRIMARIO de pagamento
    |     |-- EfiPayoutProvider (Cash-Out PIX)
    |     |-- EfiPaymentProvider (Cash-In PIX QR)
    |     |-- EfiBankSettings (admin UI)
    |     |-- woo-gerencianet-official (WC plugin, detectado)
    |
    |-- [SOFT] MercadoPago ................. Provider FALLBACK de pagamento
    |     |-- MercadoPagoPayoutProvider (Cash-Out)
    |     |-- MercadoPagoPaymentProvider (Cash-In)
    |     |-- MercadoPagoSettings + Detector (admin UI)
    |     |-- MercadoPagoWebhookController (API)
    |     |-- ProfessionalOAuthController (API)
    |     |-- woocommerce-mercadopago (WC plugin, detectado)
    |
    |-- [SOFT] Twilio ...................... SMS/OTP
    |     |-- TwilioOtpProvider (Verify API)
    |     |-- TwilioSmsProvider (Messages API)
    |
    |-- [SOFT] NVoip ....................... SMS/WhatsApp/OTP (alternativa Twilio)
    |     |-- NVoipSettings
    |
    |-- [SOFT] Firebase Auth ............... Auth de briefings
    |     |-- FirebaseAuthAdapter
    |     |-- google/auth (Composer)
    |
    |-- [SOFT] Google Business ............. Reviews
    |     |-- GoogleBusinessSettings
    |
    |-- [SOFT] Exato Digital ............... KYC
    |     |-- PPIDProviderFactory
    |     |-- RunVerificationPipeline
    |
    |-- [MORTO] Booknetic .................. NENHUMA dependencia funcional
          |-- 21 tabelas no banco (pode dropar)
          |-- 1874 arquivos no container (pode remover)
          |-- Residuos textuais em 4 arquivos PHP
```

---

## CONCLUSAO

O Booknetic pode ser removido com **seguranca total** em ~2.5 horas de trabalho. Nao existe nenhuma dependencia funcional -- apenas residuos textuais e estruturais. As 21 tabelas no banco estao essencialmente vazias e podem ser descartadas.

O MercadoPago deve ser **mantido** como provider de fallback. A arquitetura ja esta corretamente abstraida com interfaces (`PaymentProviderInterface`, `PayoutProviderInterface`). Os use cases sao agnosticos ao provider. O unico cleanup necessario e remover a referencia de `$isMercadoPagoActive` da logica de `$allPluginsActive` no AdminBootstrap.
