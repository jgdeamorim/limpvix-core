# AUDITORIA DE SEGURANCA, BANCO DE DADOS E REST API

**Plugin:** limpvix-core
**Data:** 2026-02-18
**Versao:** 1.0
**Auditor:** Claude Opus 4.6
**Escopo:** Seguranca (SQLi, XSS, CSRF, AuthN/AuthZ, File Upload), Database Migrations, REST API
**Diretorio Base:** `/wp-content/plugins/limpvix-core/`
**Total de Arquivos PHP Analisados:** 497 (src/) + 12 (database-migrations/)

---

## RESUMO EXECUTIVO

O plugin limpvix-core apresenta uma arquitetura DDD bem estruturada com boas praticas de seguranca na maioria dos repositorios (uso consistente de `$wpdb->prepare()`). Porem, foram identificadas **vulnerabilidades criticas** em scripts de migracao de banco de dados acessiveis sem autenticacao, ausencia de protecao CSRF em handlers AJAX publicos, e falha de `permission_callback` em endpoint REST. O JWT utiliza fallback inseguro para chave secreta. A maioria dos achados e corrigivel com baixo esforco.

### Tabela de Resumo dos Achados

| Severidade | Quantidade | Descricao |
|-----------|-----------|-----------|
| CRITICAL  | 3         | Scripts de migracao sem autenticacao, AJAX nopriv sem nonce, permission_callback como string |
| HIGH      | 5         | SQL sem prepare(), JWT fallback inseguro, webhook expoe erros internos, health endpoint expoe versoes, ausencia IDOR em executions |
| MEDIUM    | 5         | echo sem escape em admin, upload de certificado sem MIME check, migracao duplicada 023/024, UUID gerado com mt_rand, informacao de erro no webhook |
| LOW       | 4         | .backup files expostos, honeypot nao implementado, ausencia de rate limiting em login, error_log verboso |
| INFO      | 3         | Uso de reflexao para set ID, inconsistencia UseCase/UseCases, tabela de migracoes sem sequencia |

**Total de Achados: 20**

---

## 1. SEGURANCA - SQL INJECTION (OWASP A03:2021 - Injection)

### 1.1 Queries sem `$wpdb->prepare()` - SchedulingBootstrap.php

**Severidade:** HIGH
**OWASP:** A03:2021 - Injection
**Arquivo:** `src/Core/SchedulingBootstrap.php`
**Linhas:** 140-145, 184-193

**Descricao:** O modulo SchedulingBootstrap executa queries SQL diretamente sem uso de `$wpdb->prepare()`, interpolando `{$table}` diretamente na string SQL. Embora a variavel `$table` seja construida internamente (via `$wpdb->prefix`), isso viola o principio de defesa em profundidade e cria um padrao que pode ser copiado incorretamente.

**Codigo Afetado:**
```php
// Linha 140-141
$inProgress = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$table} WHERE status = 'in_progress'"
);

// Linha 144-145
$slaViolations = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$table} WHERE sla_violation IS NOT NULL AND status != 'completed'"
);

// Linhas 184-186 - Mesmo padrao
'draft' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'draft'"),
'allocated' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'allocated'"),
'in_progress' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'in_progress'"),
```

**Impacto:** Risco baixo pois `$table` e construido internamente, mas viola boas praticas e phpcs rules do WordPress. Padrao perigoso se copiado.

**Recomendacao:**
```php
// CORRIGIDO - usar prepare() mesmo com valores literais para consistencia
$inProgress = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE status = %s",
        'in_progress'
    )
);
```

### 1.2 Queries de Migracao sem prepare()

**Severidade:** LOW (contexto de migracao, nao user-facing)
**Arquivos:**
- `src/Core/Migrations/CreateOrdersTable.php` (linha 117, 136)
- `src/Infrastructure/Database/Migrations/CreateLedgerTable.php` (linha 63, 88, 109, 120)
- `src/Infrastructure/Database/Migrations/CreateFeedbackTable.php` (linha 53, 98, 126, 132)

**Descricao:** Queries de DDL (CREATE TABLE, DROP TABLE, SHOW TABLES) nao usam `prepare()`. No contexto de migracoes, isso e aceitavel pois os nomes de tabelas sao construidos internamente. Porem, `SHOW TABLES LIKE '$tableName'` usa interpolacao direta.

**Codigo Afetado:**
```php
// CreateOrdersTable.php:136
$result = $wpdb->get_var("SHOW TABLES LIKE '$tableName'");

// CreateLedgerTable.php:63
if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
```

**Recomendacao:**
```php
$result = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tableName));
```

### 1.3 Avaliacao Positiva - Repositorios com prepare()

**INFO** - Os seguintes repositorios usam `$wpdb->prepare()` CORRETAMENTE em todas as queries:

- `WpOrderRepository.php` - Todas as 4 queries usam prepare()
- `WpExecutionRepository.php` - Todas as queries usam prepare()
- `WpContractRepository.php` - Todas as 7 queries usam prepare()
- `WpFinancialLedgerRepository.php` - Todas as 5 queries usam prepare()
- `WpFeedbackRepository.php` - Todas as 8 queries usam prepare()
- `WpBriefingRepository.php` - Verificado
- `WpCustomerRepository.php` - Verificado
- `WpProfessionalRepository.php` - Verificado
- `WpPayoutRepository.php` - Verificado
- `AdminActionsController.php` - Queries internas usam prepare()

---

## 2. SEGURANCA - XSS (OWASP A03:2021 - Injection)

### 2.1 `echo` sem escape em templates Admin

**Severidade:** MEDIUM
**OWASP:** A03:2021 - Cross-Site Scripting
**Arquivos Afetados:**
- `src/Admin/Controllers/DashboardController.php` (linhas 52-53, 81-82, 116, 124)
- `src/Core/FeedbackBootstrap.php` (linha 158)
- `src/Admin/Controllers/FinancialReportController.php` (linha 291)
- `src/Admin/Settings/EfiBankSettings.php` (multiplas linhas)
- `src/Admin/Settings/MercadoPagoSettings.php` (linhas 265, 273, 286)
- `src/Admin/Settings/NVoipSettings.php` (linha 103)

**Descricao:** Multiplas instancias de `echo $variable` sem funcoes de escape (`esc_html()`, `esc_attr()`). Embora essas variaveis sejam tipicamente valores numericos ou booleanos gerados internamente, a ausencia de escape viola boas praticas WordPress.

**Codigo Afetado (exemplos):**
```php
// DashboardController.php:52-53
<span class="status-badge status-paid"><?php echo $metrics['orders']['paid']; ?> Pagas</span>
<span class="status-badge status-authorized"><?php echo $metrics['orders']['authorized']; ?> Autorizadas</span>

// DashboardController.php:116
<div class="limpvix-dashboard-card <?php echo $this->getHealthClass($metrics['health']['score']); ?>">
```

**Impacto:** Baixo em contexto admin (atacante ja precisaria ser admin), mas viola padrao WordPress.

**Recomendacao:**
```php
<span class="status-badge status-paid"><?php echo esc_html($metrics['orders']['paid']); ?> Pagas</span>
```

### 2.2 Avaliacao Positiva - Templates Publicos

**INFO** - Os templates publicos `CustomerFeedbackPage.php` e `CustomerBriefingPage.php` usam `esc_html()`, `esc_attr()` e `esc_js()` CORRETAMENTE em todas as saidas de dados dinamicos.

---

## 3. SEGURANCA - AUTENTICACAO E AUTORIZACAO (OWASP A01:2021 - Broken Access Control)

### 3.1 CRITICAL - AJAX `nopriv` Handlers sem Nonce (CSRF)

**Severidade:** CRITICAL
**OWASP:** A01:2021 - Broken Access Control / A05:2021 - Security Misconfiguration
**Arquivos:**
- `src/Infrastructure/Admin/Pages/CustomerFeedbackPage.php` (linhas 23-24, 627-649)
- `src/Infrastructure/Admin/Pages/CustomerBriefingPage.php` (linhas 24-25, 478-499)

**Descricao:** Ambos os handlers registram acoes `wp_ajax_nopriv_*` (acessiveis por usuarios nao autenticados) e NAO verificam nonce CSRF. Qualquer atacante pode submeter feedback falso ou aceitar briefing em nome de qualquer order_id, bastando conhecer o ID do pedido.

**Codigo Afetado (CustomerFeedbackPage.php):**
```php
// Registro da acao - acessivel por qualquer pessoa
add_action('wp_ajax_nopriv_limpvix_submit_feedback', [__CLASS__, 'handleSubmit']);
add_action('wp_ajax_limpvix_submit_feedback', [__CLASS__, 'handleSubmit']);

// Handler SEM nonce check
public static function handleSubmit(): void
{
    $order_id = absint($_POST['order_id'] ?? 0);
    $rating = absint($_POST['rating'] ?? 0);
    // ... processa diretamente sem verificacao de nonce
}
```

**Codigo Afetado (CustomerBriefingPage.php):**
```php
add_action('wp_ajax_nopriv_limpvix_accept_briefing', [__CLASS__, 'handleAcceptance']);

public static function handleAcceptance(): void
{
    $order_id = absint($_POST['order_id'] ?? 0);
    $accepted = boolval($_POST['accepted'] ?? false);
    // ... processa diretamente sem verificacao de nonce
}
```

**Impacto:** ALTO - Um atacante pode:
1. Submeter avaliacoes falsas (5 estrelas para liberar pagamentos, ou 1 estrela para bloquear)
2. Aceitar briefings sem consentimento do cliente
3. Manipular scores de profissionais via avaliacoes forjadas
4. Causar impacto financeiro direto (ratings afetam liberacao de pagamento)

**Nota Atenuante:** A pagina publica usa hash de validacao (`validateHash()`), porem o AJAX handler bypassa essa validacao. O formulario HTML NAO envia o hash no submit AJAX.

**Recomendacao:**
```php
public static function handleSubmit(): void
{
    // Opcao 1: Verificar hash no handler AJAX
    $order_id = absint($_POST['order_id'] ?? 0);
    $hash = sanitize_text_field($_POST['hash'] ?? '');

    if (!self::validateHash($order_id, $hash)) {
        wp_send_json_error(['message' => 'Link invalido ou expirado.']);
        return;
    }

    // Opcao 2: Adicionar nonce no formulario e verificar
    // No render(): wp_nonce_field('limpvix_feedback_' . $order_id);
    // No handler: check_ajax_referer('limpvix_feedback_' . $order_id, '_wpnonce');

    $rating = absint($_POST['rating'] ?? 0);
    // ... continuar processamento
}
```

### 3.2 CRITICAL - `permission_callback` como String em vez de Callable

**Severidade:** CRITICAL
**OWASP:** A01:2021 - Broken Access Control
**Arquivo:** `src/Infrastructure/API/ProfessionalDocumentController.php`
**Linhas:** 93, 112, 121

**Descricao:** O `permission_callback` e definido como a string `'manage_options'` em vez de uma callable. WordPress espera que `permission_callback` seja uma callable que retorna `bool` ou `WP_Error`. Passar uma string nao-callable resultara em comportamento indefinido -- na maioria das versoes do WordPress, a string sera avaliada como `true` (truthy), permitindo acesso NAO autenticado a endpoints de administracao de documentos.

**Codigo Afetado:**
```php
// Linha 93 - Lista documentos pendentes (admin only)
register_rest_route($this->namespace, '/documents/pending', [
    [
        'methods' => 'GET',
        'callback' => [$this, 'listPendingDocuments'],
        'permission_callback' => 'manage_options', // BUG: string em vez de callable
    ],
]);

// Linha 112 - Aprova documento (admin only)
register_rest_route($this->namespace, '/documents/(?P<id>\d+)/approve', [
    [
        'permission_callback' => 'manage_options', // BUG
    ],
]);

// Linha 121 - Rejeita documento (admin only)
register_rest_route($this->namespace, '/documents/(?P<id>\d+)/reject', [
    [
        'permission_callback' => 'manage_options', // BUG
    ],
]);
```

**Impacto:** ALTO - Qualquer usuario (ou anonimo) pode listar documentos pendentes, aprovar ou rejeitar documentos KYC de profissionais.

**Recomendacao:**
```php
'permission_callback' => function() {
    return current_user_can('manage_options');
},
```

### 3.3 HIGH - JWT Secret Key com Fallback Inseguro

**Severidade:** HIGH
**OWASP:** A02:2021 - Cryptographic Failures
**Arquivo:** `src/Infrastructure/Auth/JwtService.php`
**Linha:** 26

**Descricao:** O servico JWT usa `AUTH_KEY` como chave secreta, com fallback para a string literal `'limpvix-default-secret'`. Se `AUTH_KEY` nao estiver definido (o que pode ocorrer em ambientes mal-configurados), todos os tokens JWT serao assinados com uma chave publica e previsivel.

**Codigo Afetado:**
```php
$this->secretKey = defined('AUTH_KEY') ? AUTH_KEY : 'limpvix-default-secret';
```

**Impacto:** Se AUTH_KEY nao estiver definido, qualquer atacante pode forjar tokens JWT validos e se autenticar como qualquer usuario.

**Recomendacao:**
```php
public function __construct()
{
    if (!defined('AUTH_KEY') || AUTH_KEY === 'put your unique phrase here') {
        throw new \RuntimeException(
            'AUTH_KEY must be properly configured for JWT authentication. '
            . 'Please set AUTH_KEY in wp-config.php'
        );
    }
    $this->secretKey = AUTH_KEY;
    $this->issuer = get_site_url();
}
```

### 3.4 HIGH - Ausencia de Verificacao IDOR em ExecutionController

**Severidade:** HIGH
**OWASP:** A01:2021 - Broken Access Control
**Arquivo:** `src/Infrastructure/API/ExecutionController.php`
**Linhas:** 725-733

**Descricao:** O `checkPermissions()` do ExecutionController apenas verifica se o usuario esta logado (`is_user_logged_in()`), sem verificar se o usuario tem permissao para acessar AQUELA execucao especifica. Qualquer usuario logado pode acessar, iniciar, completar ou cancelar qualquer execucao (IDOR).

**Codigo Afetado:**
```php
public function checkPermissions(): bool
{
    return is_user_logged_in(); // Qualquer usuario logado pode tudo
}
```

**Impacto:** Um profissional pode manipular execucoes de outros profissionais. Um cliente pode acessar execucoes que nao sao suas.

**Recomendacao:**
```php
public function checkPermissions(WP_REST_Request $request): bool
{
    if (!is_user_logged_in()) {
        return false;
    }

    if (current_user_can('manage_options')) {
        return true;
    }

    // Verificar ownership via AuthorizationService
    $executionId = $request->get_param('id');
    if ($executionId) {
        $execution = $this->executionRepository->findByUuid($executionId);
        if ($execution) {
            return $execution->getProfessionalId() === get_current_user_id()
                || $this->isOwnerOfOrder($execution->getOrderUuid());
        }
    }

    return true; // Para listagem, filtrar no handler
}
```

### 3.5 Avaliacao Positiva - Autenticacao e Autorizacao Adequada

Os seguintes componentes implementam autenticacao/autorizacao corretamente:

- **AuthController.php** - Login, refresh e me endpoints com permission callbacks adequados
- **JwtAuthMiddleware.php** - Middleware JWT com verificacao de capability
- **AdminActionsController.php** - Todos os handlers verificam nonce + capability
- **ContractController.php** - Separa checkPermissions (logado) de checkAdminPermissions (manage_options)
- **ProfessionalController.php** - Verifica ownership (profissional so acessa seu perfil)
- **OfferController.php** - Callbacks de permissao granulares por endpoint
- **ManualPayoutAjaxHandler.php** - Verifica nonce + manage_options em todos os handlers
- **DocumentReviewAjaxHandler.php** - Verifica nonce + manage_options

---

## 4. REST API - COMPLETUDE E COERENCIA

### 4.1 Inventario Completo de Endpoints REST

**Namespace:** `limpvix/v1`
**Total de Endpoints:** 54 registrados via `register_rest_route()`

| Controller | Endpoints | Auth |
|-----------|-----------|------|
| AuthController | 3 (login, refresh, me) | 2 publicos, 1 autenticado |
| BriefingController | 2 (create, get) | Autenticado |
| BriefingStepController | 1 (step) | Autenticado |
| BriefingSchemaController | 1 (schema) | Autenticado |
| BriefingPhoneController | 1 (verify-phone) | Autenticado |
| ContractController | 8 (CRUD + acoes) | Autenticado/Admin |
| ExecutionController | 13 (CRUD + acoes + evidencia + issues) | Autenticado/Admin |
| CustomerController | 5 (list, me, get, contracts, briefings) | Admin/Autenticado |
| ProfessionalController | 8 (CRUD + offers + availability + score) | Admin/Professional |
| ProfessionalDocumentController | 6 (upload, list, kyc, pending, approve, reject) | Professional/Admin |
| ProfessionalOAuthController | 5 (connect, callback, disconnect, payout-method GET/POST) | Admin/Publico(callback) |
| OfferController | 6 (send, list, get, accept, reject, professional-offers) | Contextual |
| OtpController | 2 (send, verify) | Logado |
| CepController | 1 (lookup) | Publico |
| ServiceCatalogController | 3 (services, additionals, briefing-additionals) | Publico/Autenticado |
| PackageController | 2 (list, briefing-package) | Publico/Autenticado |
| ApiKeyController | 2 (list/create, revoke) | Autenticado |
| HealthController | 2 (health, health/cron) | Publico |
| MercadoPagoWebhookController | 1 (webhooks/mercadopago) | Publico (signature) |

### 4.2 Endpoints Publicos Intencionais

Os seguintes endpoints sao `'permission_callback' => '__return_true'` por design:

| Endpoint | Justificativa | Risco |
|----------|---------------|-------|
| `/auth/login` | Endpoint de login | OK - by design |
| `/auth/refresh` | Renovacao de token | OK - requer refresh_token valido |
| `/cep/{cep}` | Consulta CEP | OK - dados publicos |
| `/services` | Catalogo de servicos | OK - dados publicos |
| `/additionals` | Adicionais do catalogo | OK - dados publicos |
| `/packages` | Pacotes disponiveis | OK - dados publicos |
| `/health` e `/health/cron` | Monitoramento | MEDIUM - ver item 4.4 |
| `/webhooks/mercadopago` | Webhook de pagamento | OK - validacao de assinatura |
| `/oauth/mercadopago/callback` | OAuth callback | OK - validacao de state |

### 4.3 Validacao de Entrada nos Endpoints

**Avaliacao:** A maioria dos endpoints define `args` com `type`, `required`, `validate_callback` e `sanitize_callback`. Exemplos positivos:

- ContractController: Define enum para status, validate_callback para user_id
- OtpController: validate_callback para formato de telefone, regex para codigo 6 digitos
- ProfessionalDocumentController: sanitize_callback = 'absint' para limit/offset

**Lacunas identificadas:**
- Alguns endpoints nao definem `args` (GET endpoints de listagem sem filtros)
- ExecutionController issues endpoint (linha 202-235) define args mas sem sanitize_callback em todos

### 4.4 HIGH - Health Endpoint Expoe Informacoes Sensiveis

**Severidade:** HIGH
**OWASP:** A01:2021 - Security Misconfiguration
**Arquivo:** `src/Infrastructure/API/HealthController.php`
**Linhas:** 134-138

**Descricao:** O endpoint publico `/health` expoe versoes de PHP, WordPress e plugin sem autenticacao.

**Codigo Afetado:**
```php
'metadata' => [
    'plugin_version' => defined('LIMPVIX_VERSION') ? LIMPVIX_VERSION : 'unknown',
    'wp_version' => get_bloginfo('version'),
    'php_version' => PHP_VERSION,
],
```

**Impacto:** Atacantes podem usar essa informacao para identificar vulnerabilidades conhecidas nas versoes especificas.

**Recomendacao:**
```php
// Remover metadata do endpoint publico
// Ou mover para endpoint autenticado separado
'metadata' => current_user_can('manage_options') ? [
    'plugin_version' => LIMPVIX_VERSION ?? 'unknown',
    'wp_version' => get_bloginfo('version'),
    'php_version' => PHP_VERSION,
] : null,
```

### 4.5 MEDIUM - Webhook Expoe Mensagens de Erro Internas

**Severidade:** MEDIUM
**OWASP:** A04:2021 - Insecure Design
**Arquivo:** `src/Infrastructure/API/Controllers/MercadoPagoWebhookController.php`
**Linha:** 162

**Descricao:** O webhook retorna `$e->getMessage()` diretamente na resposta em caso de excecao, potencialmente expondo detalhes internos.

**Codigo Afetado:**
```php
return new \WP_REST_Response([
    'error' => 'Internal server error',
    'message' => $e->getMessage(), // Pode expor detalhes internos
], 500);
```

**Recomendacao:**
```php
return new \WP_REST_Response([
    'error' => 'Internal server error',
    'message' => 'An unexpected error occurred',
], 500);
```

---

## 5. DATABASE - SCHEMA E MIGRATIONS

### 5.1 Inventario de Migrations

**Localizacao 1:** `database-migrations/` (31 arquivos SQL + 12 scripts PHP)
**Localizacao 2:** `src/Infrastructure/Database/Migrations/` (3 arquivos PHP)
**Localizacao 3:** `src/Core/Migrations/` (1 arquivo PHP)

**Sequencia SQL (000-031):**
```
000 - create_migrations_table
001 - create_orders_table
005 - create_executions_table
006 - create_briefings_tables
007 - add_briefing_packages
008 - add_briefing_complexity
009 - create_service_catalog_tables
010 - create_contracts_tables
011 - create_communication_tables
012 - create_professionals_module
013 - create_scheduling_tables
014 - create_structured_feedback_tables
015 - create_financial_ledger_table
016 - add_professional_fk_constraints
017 - add_feedback_window_tracking
018 - add_recurring_payments
019 - create_professional_skills_table
020 - add_kyc_fields
021 - create_contract_offers_table
022 - add_evidence_validation_fields
023 - add_professional_status_column (E create_professional_documents_table - DUPLICADO)
024 - create_user_verifications_table (E add_manual_payout_fields - DUPLICADO)
025 - add_service_catalog_required_skills (E versao _OLD)
026 - create_professional_verification
027 - add_payout_dual_mode_fields
029 - add_recurring_payment_execution_fields (pula 028!)
030 - add_feedback_resolution_fields
031 - add_payout_authorized_status
```

### 5.2 MEDIUM - Numeracao Duplicada e Inconsistente de Migrations

**Severidade:** MEDIUM
**Arquivos:**
- `023_add_professional_status_column.sql` E `023_create_professional_documents_table.sql`
- `024_create_user_verifications_table.sql` E `024_add_manual_payout_fields.sql`
- `025_add_service_catalog_required_skills.sql` E `025_add_service_catalog_required_skills_OLD.sql`
- Gap: nao existe migration 002, 003, 004, 028

**Impacto:** Confusao sobre ordem de execucao. Scripts runner referenciam migrations por numero, podendo executar a errada.

**Recomendacao:** Resequenciar migrations com timestamps ou numeros unicos. Remover duplicatas e versoes _OLD.

### 5.3 Verificacao de Tabelas Referenciadas vs Criadas

**Tabelas criadas por migrations:**
- `wp_limpvix_orders` (001)
- `wp_limpvix_executions` (005)
- `wp_limpvix_briefings`, `wp_limpvix_briefing_steps` (006)
- `wp_limpvix_service_catalog`, `wp_limpvix_service_additionals` (009)
- `wp_limpvix_contracts`, `wp_limpvix_professional_allocations_history` (010)
- `wp_limpvix_message_queue`, `wp_limpvix_message_log`, `wp_limpvix_message_templates` (011)
- `wp_limpvix_professionals`, `wp_limpvix_professional_skills` (012)
- `wp_limpvix_schedules`, `wp_limpvix_availability` (013)
- `wp_limpvix_structured_feedback` (014)
- `wp_limpvix_financial_ledger` (015)
- `wp_limpvix_contract_offers` (021)
- `wp_limpvix_professional_documents` (023)
- `wp_limpvix_user_verifications` (024)
- `wp_limpvix_professional_verification` (026)

**Tabelas referenciadas em codigo mas nao encontradas em migrations explicitas:**
- `wp_limpvix_ledger` (CreateLedgerTable.php em Infrastructure, separada de financial_ledger)
- `wp_limpvix_feedback` (CreateFeedbackTable.php em Infrastructure)
- `wp_limpvix_mp_payouts` (CreateMercadoPagoPayoutsTable.php)
- `wp_limpvix_payouts` (referenciada em 024, 027, 031 como ALTER TABLE)
- `wp_limpvix_payout_audit_trail` (criada em 024)
- `wp_limpvix_recurring_payments` (criada em 018)

**Observacao:** Existem duas tabelas de ledger diferentes (`limpvix_ledger` vs `limpvix_financial_ledger`) e duas tabelas de feedback (`limpvix_feedback` vs `limpvix_structured_feedback`). Isso pode indicar migracoes nao consolidadas.

### 5.4 Prefixo WordPress

**INFO** - Todos os repositorios usam `$wpdb->prefix` corretamente para construir nomes de tabelas. Nenhuma tabela hardcoded sem prefixo foi encontrada no codigo PHP.

### 5.5 Foreign Keys e Integridade Referencial

**INFO** - O projeto deliberadamente NAO usa foreign keys SQL (documentado em `CreateOrdersTable.php`: "NUNCA usar foreign keys (problemas com MyISAM/InnoDB)"). A integridade referencial e mantida no nivel da aplicacao. Isso e uma pratica aceitavel no ecossistema WordPress.

### 5.6 MEDIUM - UUID Gerado com mt_rand()

**Severidade:** MEDIUM
**OWASP:** A02:2021 - Cryptographic Failures
**Arquivo:** `src/Infrastructure/Persistence/WpFinancialLedgerRepository.php`
**Linhas:** 276-282

**Descricao:** UUIDs do ledger financeiro sao gerados com `mt_rand()` que NAO e criptograficamente seguro.

**Codigo Afetado:**
```php
private function generateUuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        // ...
    );
}
```

**Recomendacao:**
```php
private function generateUuid(): string
{
    return wp_generate_uuid4();
}
```

---

## 6. SEGURANCA - CSRF E NONCES (OWASP A01:2021)

### 6.1 CRITICAL - Scripts de Migracao sem Autenticacao

**Severidade:** CRITICAL
**OWASP:** A01:2021 - Broken Access Control
**Arquivos:**
- `database-migrations/run-005-migration.php`
- `database-migrations/run-015-migration.php`
- `database-migrations/run-019-migration.php`
- `database-migrations/run-020-migration.php`
- `database-migrations/run-023-migration.php`
- `database-migrations/run-027-migration.php`

**Descricao:** Os scripts `run-XXX-migration.php` carregam WordPress via `require wp-load.php` e executam SQL diretamente SEM NENHUMA verificacao de autenticacao. Qualquer pessoa que conheca a URL pode executar DDL no banco de dados.

**Contraste:** Os scripts `execute-migration-XXX.php` e `execute-all-gaps-migrations.php` TEM verificacao `current_user_can('manage_options')`. Apenas os `run-XXX-migration.php` estao desprotegidos.

**Codigo Afetado (run-027-migration.php):**
```php
// SEM verificacao de autenticacao!
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;
// Executa ALTER TABLE diretamente...
```

**URL de acesso direto:**
```
https://seusite.com/wp-content/plugins/limpvix-core/database-migrations/run-027-migration.php
```

**Impacto:** CRITICO - Um atacante pode:
1. Executar DDL arbitrario no banco de dados
2. Alterar a estrutura de tabelas
3. Inserir dados maliciosos
4. Causar downtime via schema corruption

**Recomendacao IMEDIATA:**

**Opcao 1 (preferida): Adicionar verificacao de autenticacao em todos os scripts:**
```php
require dirname(__DIR__, 4) . '/wp-load.php';

if (!current_user_can('manage_options')) {
    wp_die('Unauthorized. Only administrators can run migrations.', 'Forbidden', ['response' => 403]);
}
```

**Opcao 2: Adicionar verificacao de CLI-only:**
```php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from command line.');
}
```

**Opcao 3 (mais segura): Remover scripts run-XXX e usar WP-CLI ou admin UI para migrations.**

### 6.2 Avaliacao Positiva - CSRF em AJAX Admin

Os seguintes handlers verificam nonce CORRETAMENTE:

| Handler | Nonce Action | Verificacao |
|---------|-------------|-------------|
| AdminActionsController (todas acoes financeiras) | limpvix_finance_actions | check_ajax_referer() |
| ManualPayoutAjaxHandler (create/approve/reject) | limpvix_manual_payout | wp_verify_nonce() |
| DocumentReviewAjaxHandler (approve/reject) | limpvix_document_review | wp_verify_nonce() |
| EfiBankSettings (5 handlers) | limpvix_efi_actions | check_ajax_referer() |
| FirebaseSettings (2 handlers) | limpvix_firebase_actions | check_ajax_referer() |
| MercadoPagoSettings (4 handlers) | limpvix_mp_actions | check_ajax_referer() |
| TestVendorsManager (3 handlers) | limpvix_test_vendor / limpvix_test_payout | check_ajax_referer() |
| FeedbackManagementPage | limpvix_get_feedback_detail | check_ajax_referer() |
| MessageTemplatesPage | limpvix_ajax | check_ajax_referer() |
| MessageTemplatesAdminPage (2 handlers) | limpvix_preview_template / limpvix_get_template_data | check_ajax_referer() |
| ContractManagementPage | limpvix_send_offers_nonce | wp_verify_nonce() |
| ProfessionalManagementPage | limpvix_kyc_action + nonce URL | check_ajax_referer() + wp_verify_nonce() |
| AdminBootstrap (feature flags, flows) | limpvix_save_feature_flags / limpvix_update_flows | wp_verify_nonce() |
| ServiceCatalogPage | limpvix_service_nonce_* | wp_verify_nonce() |
| PackageManagementPage | limpvix_package_nonce_* | wp_verify_nonce() |

### 6.3 Formularios Admin com wp_nonce_field()

Todos os formularios admin verificados incluem `wp_nonce_field()` adequadamente:
- DialogSettings, NVoipSettings, TwilioSettings, GoogleBusinessSettings
- EfiBankSettings, LimpVixSettingsPage, CommunicationSettingsPage
- ContractManagementPage, ServiceCatalogPage, PackageManagementPage
- ProfessionalManagementPage, ExecutionDetailsPage, PayoutsPage

---

## 7. SEGURANCA - FILE OPERATIONS

### 7.1 Upload de Documentos - Adequadamente Protegido

**Arquivo:** `src/Application/UseCases/Professional/UploadDocument.php`

**Controles implementados:**
- Whitelist de MIME types (JPEG, PNG, PDF)
- Limite de tamanho (5MB)
- Verificacao de conteudo real via `finfo_file()` contra MIME declarado
- Uso de `wp_handle_upload()` do WordPress
- Criacao de attachment via `wp_insert_attachment()`

**Avaliacao:** BOM - Implementacao segura de upload.

### 7.2 MEDIUM - Upload de Certificado EFI sem Verificacao de Conteudo

**Severidade:** MEDIUM
**OWASP:** A04:2021 - Insecure Design
**Arquivo:** `src/Admin/Settings/EfiBankSettings.php`
**Linhas:** 830-869

**Descricao:** O upload de certificado PEM/CRT/P12 verifica apenas extensao do arquivo e tamanho, mas NAO verifica o conteudo real do arquivo (MIME type check via finfo).

**Codigo Afetado:**
```php
// Verifica apenas extensao
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['pem', 'crt', 'p12'], true)) {
    wp_send_json_error(['message' => 'Tipo invalido. Aceito: .pem, .crt, .p12']);
}

// Move sem verificacao de conteudo
if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
```

**Atenuantes:**
- Requer `manage_options` (admin only)
- Verifica nonce
- Diretorio protegido com `.htaccess deny from all`
- Nome do arquivo e sanitizado (nao usa nome original)

**Recomendacao:**
```php
// Adicionar verificacao de conteudo
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$actualMimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMimeTypes = [
    'application/x-x509-ca-cert',
    'application/x-pem-file',
    'application/pkcs12',
    'application/octet-stream', // p12 files
];

if (!in_array($actualMimeType, $allowedMimeTypes, true)) {
    wp_send_json_error(['message' => 'Conteudo do arquivo nao corresponde ao tipo declarado.']);
}
```

### 7.3 LOW - Arquivos .backup Expostos

**Severidade:** LOW
**Arquivos:**
- `src/Infrastructure/API/OtpController.php.backup.twilio`
- `src/Admin/Bootstrap/AdminBootstrap.php.backup.twilio`

**Descricao:** Arquivos `.backup.twilio` estao no repositorio. Embora PHP nao execute arquivos com esta extensao, eles podem ser servidos como texto pelo servidor web, expondo codigo fonte.

**Recomendacao:** Remover arquivos `.backup` do repositorio e adicionar ao `.gitignore`.

### 7.4 Verificacao de Scripts de Diagnostico

**Avaliacao:** Os scripts em `database-migrations/` que tem `require wp-load.php` E `current_user_can()` estao adequadamente protegidos (execute-XXX.php). Os scripts `run-XXX.php` estao DESPROTEGIDOS (ver item 6.1).

---

## 8. ACHADOS ADICIONAIS

### 8.1 LOW - Ausencia de Rate Limiting no Login

**Severidade:** LOW
**Arquivo:** `src/Infrastructure/API/AuthController.php`

**Descricao:** O endpoint `/auth/login` nao implementa rate limiting. WordPress tem protecao nativa contra brute force, mas o endpoint JWT bypassa isso parcialmente.

**Recomendacao:** Implementar rate limiting via transients, similar ao `FrontendGuards::checkRateLimit()`.

### 8.2 LOW - Honeypot Nao Implementado

**Severidade:** LOW
**Arquivo:** `src/Frontend/FrontendGuards.php`
**Linha:** 113

**Descricao:** O metodo `checkHoneypot()` tem um TODO e sempre retorna `true`.

### 8.3 LOW - error_log Verboso

**Severidade:** LOW
**Multiplos arquivos**

**Descricao:** Uso extensivo de `error_log()` com dados potencialmente sensiveis (payloads de webhook, dados de pagamento). Em producao, isso pode expor dados sensiveis em logs.

**Recomendacao:** Usar sistema de logging com niveis (DEBUG, INFO, ERROR) e desabilitar DEBUG em producao.

### 8.4 INFO - Inconsistencia de Nomenclatura UseCase vs UseCases

**Diretorio `src/Application/UseCase/`** contém: Contract, Execution, Professional, Auth, Briefing, Customer
**Diretorio `src/Application/UseCases/`** contém: Briefing, Communication, Contract, Execution, Feedback, Finance, Financial, Order, Professional, Scheduling, Verification

Ambas as pastas coexistem com conteudo diferente. Isso pode causar confusao.

---

## QUICK WINS - Correcoes Faceis de Alto Impacto

### Prioridade 1 - IMEDIATO (menos de 1 hora)

| # | Acao | Impacto | Esforco |
|---|------|---------|---------|
| 1 | Adicionar `current_user_can('manage_options')` nos 6 scripts `run-XXX-migration.php` | Fecha vulnerabilidade CRITICA | 10 min |
| 2 | Corrigir `'permission_callback' => 'manage_options'` para callable em `ProfessionalDocumentController.php` | Fecha vulnerabilidade CRITICA | 5 min |
| 3 | Adicionar verificacao de hash no `handleSubmit()` de `CustomerFeedbackPage.php` e `handleAcceptance()` de `CustomerBriefingPage.php` | Fecha vulnerabilidade CRITICA | 15 min |
| 4 | Remover fallback `'limpvix-default-secret'` do JWT e lancar excecao se AUTH_KEY nao definido | Fecha vulnerabilidade HIGH | 5 min |
| 5 | Remover `metadata` do endpoint `/health` publico | Fecha information disclosure | 5 min |

### Prioridade 2 - CURTO PRAZO (menos de 1 dia)

| # | Acao | Impacto | Esforco |
|---|------|---------|---------|
| 6 | Adicionar verificacao IDOR em `ExecutionController::checkPermissions()` | Fecha broken access control | 30 min |
| 7 | Substituir `mt_rand()` por `wp_generate_uuid4()` no LedgerRepository | Melhora seguranca criptografica | 5 min |
| 8 | Adicionar escape (`esc_html()`) em todos os `echo $var` nos templates admin | Previne XSS | 1 hora |
| 9 | Remover `$e->getMessage()` da resposta do webhook MercadoPago | Previne information disclosure | 5 min |
| 10 | Remover arquivos `.backup.twilio` do repositorio | Reduz superficie de ataque | 5 min |

### Prioridade 3 - MEDIO PRAZO (1-2 semanas)

| # | Acao | Impacto | Esforco |
|---|------|---------|---------|
| 11 | Consolidar e resequenciar migrations (resolver duplicatas 023/024) | Previne erros de schema | 2-4 horas |
| 12 | Implementar rate limiting no endpoint `/auth/login` | Previne brute force | 2 horas |
| 13 | Adicionar verificacao finfo no upload de certificado EFI | Defesa em profundidade | 30 min |
| 14 | Usar `$wpdb->prepare()` nas queries do SchedulingBootstrap | Consistencia e seguranca | 30 min |
| 15 | Consolidar pastas UseCase/ e UseCases/ | Clareza arquitetural | 2-4 horas |

---

## CONCLUSAO

O plugin limpvix-core demonstra maturidade arquitetural significativa com DDD, separacao de concerns, e boas praticas de seguranca na maioria dos componentes. Os repositorios de persistencia usam `$wpdb->prepare()` consistentemente, os handlers AJAX admin verificam nonces e capabilities, e o sistema de JWT esta bem implementado (com excecao do fallback da chave).

As vulnerabilidades CRITICAS identificadas sao concentradas em tres areas:
1. **Scripts de migracao sem autenticacao** - facilmente corrigivel
2. **AJAX handlers publicos sem CSRF** - requer adicao de hash/nonce
3. **permission_callback como string** - erro de tipagem facilmente corrigivel

Com a implementacao dos Quick Wins de Prioridade 1 (estimativa: aproximadamente 40 minutos), as vulnerabilidades CRITICAS serao eliminadas. As correcoes de Prioridade 2 e 3 aprimoram a postura de seguranca para um nivel profissional completo.

---

*Documento gerado em 2026-02-18 por Claude Opus 4.6*
*Auditoria 2/3 do ciclo de revisao do plugin limpvix-core*
