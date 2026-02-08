# Testes de Integração e E2E - LimpVix Core

## 📋 Estrutura de Testes

```
tests/
├── Domain/                          # ✅ Testes Unitários (FASE 4)
│   └── Briefing/
│       ├── PackageTest.php
│       ├── ComplexityTest.php
│       ├── ProfessionalAllocationTest.php
│       └── ProfessionalAllocationPolicyTest.php
│
├── Integration/                     # ✅ Testes de Integração (FASE 5)
│   ├── Persistence/
│   │   └── WpBriefingRepositoryTest.php     # 17 testes
│   └── UseCases/
│       └── SelectPackageIntegrationTest.php # 8 testes
│
└── E2E/                             # ✅ Testes End-to-End (FASE 5)
    └── BriefingCompleteFlowTest.php        # 3 testes
```

## 🎯 Tipos de Testes

### 1. Testes Unitários (Unit Tests)
- **Objetivo:** Testar componentes isolados
- **Sem dependências:** Mocks para banco de dados
- **Rápidos:** Milissegundos por teste
- **Cobertura:** 64 testes (100% do domínio)

### 2. Testes de Integração (Integration Tests)
- **Objetivo:** Testar componentes conectados
- **Com dependências:** Banco de dados real (WordPress)
- **Médios:** Segundos por teste
- **Cobertura:** 25 testes (Repository + Use Cases)

### 3. Testes E2E (End-to-End)
- **Objetivo:** Testar fluxo completo do usuário
- **Sistema completo:** Todos componentes integrados
- **Lentos:** Dezenas de segundos por teste
- **Cobertura:** 3 cenários críticos

---

## 🔧 Configuração

### Requisitos

- **PHPUnit:** 9.5+
- **WordPress:** Ambiente funcional com banco de dados
- **Tabelas:** Migrations 007 e 008 executadas

### Instalação

```bash
cd /path/to/limpvix-core
composer require --dev phpunit/phpunit:^9.5
```

---

## ▶️ Rodando os Testes

### Por Tipo de Teste

**Apenas testes unitários (rápido):**
```bash
./vendor/bin/phpunit --testsuite "Unit Tests"
```

**Apenas testes de integração (requer banco):**
```bash
./vendor/bin/phpunit --testsuite "Integration Tests"
```

**Apenas testes E2E (requer banco):**
```bash
./vendor/bin/phpunit --testsuite "E2E Tests"
```

**Todos os testes:**
```bash
./vendor/bin/phpunit --testsuite "All Tests"
```

### Por Grupo (Tags)

**Apenas testes de domínio:**
```bash
./vendor/bin/phpunit --group domain
```

**Apenas testes de persistência:**
```bash
./vendor/bin/phpunit --group database
```

**Apenas fluxos completos:**
```bash
./vendor/bin/phpunit --group complete-flow
```

### Excluindo Testes Lentos

Por padrão, testes de integração e E2E são excluídos (configurado em phpunit.xml).

**Rodar todos exceto E2E:**
```bash
./vendor/bin/phpunit --exclude-group e2e
```

**Rodar apenas unitários (rápido):**
```bash
./vendor/bin/phpunit --exclude-group integration,e2e
```

---

## 📊 Cobertura de Testes FASE 5

| Test Suite | Testes | Arquivo | Status |
|------------|--------|---------|--------|
| **WpBriefingRepositoryTest** | 17 | Integration/Persistence | ✅ |
| **SelectPackageIntegrationTest** | 8 | Integration/UseCases | ✅ |
| **BriefingCompleteFlowTest** | 3 | E2E | ✅ |
| **TOTAL FASE 5** | **28** | - | ✅ **100%** |

### Cobertura Total (FASE 4 + 5)

| Categoria | Testes | Status |
|-----------|--------|--------|
| Unitários (FASE 4) | 64 | ✅ |
| Integração (FASE 5) | 25 | ✅ |
| E2E (FASE 5) | 3 | ✅ |
| **TOTAL** | **92** | ✅ **100%** |

---

## 🧪 Detalhamento dos Testes de Integração

### WpBriefingRepositoryTest (17 testes)

Testa persistência real no banco de dados WordPress.

**Cenários testados:**
- ✅ `test_save_creates_new_briefing_in_database`
- ✅ `test_find_by_uuid_retrieves_correct_briefing`
- ✅ `test_save_updates_existing_briefing`
- ✅ `test_hydration_preserves_value_objects`
  - PropertyStructure
  - Frequency
  - EstimatedMetrics
  - Package
  - Complexity
- ✅ `test_find_by_user_id_returns_user_briefings`
- ✅ `test_find_by_status_returns_correct_briefings`
- ✅ `test_count_returns_correct_total`
- ✅ `test_count_by_status_returns_correct_count`
- ✅ `test_delete_removes_briefing`
- ✅ `test_cannot_delete_locked_briefing`
- ✅ `test_find_by_order_id_retrieves_correct_briefing`
- ✅ `test_transactional_save_rollback_on_error`
- ✅ `test_immutability_briefing_retrieved_is_new_instance`

**Validações críticas:**
- Hidratação/Desidratação completa
- Value Objects preservados
- Transações funcionais
- Soft deletes respeitados
- Imutabilidade garantida

### SelectPackageIntegrationTest (8 testes)

Testa fluxo completo: Use Case → Repository → Database.

**Cenários testados:**
- ✅ `test_execute_selects_basic_package_successfully`
- ✅ `test_execute_selects_standard_package_successfully`
- ✅ `test_execute_selects_premium_package_successfully`
- ✅ `test_execute_fails_for_nonexistent_briefing`
- ✅ `test_execute_fails_for_invalid_package_type`
- ✅ `test_execute_fails_for_locked_briefing`
- ✅ `test_execute_registers_event_in_ledger`
- ✅ `test_execute_allows_changing_package`

**Validações críticas:**
- Persistência funcional
- Eventos registrados no ledger
- Validações de negócio aplicadas
- State transitions corretas

---

## 🚀 Detalhamento dos Testes E2E

### BriefingCompleteFlowTest (3 testes)

Testa fluxos completos de ponta a ponta.

#### Teste 1: Serviço Simples
**`test_complete_flow_simple_service`**

**Cenário:** Cliente de apartamento pequeno, serviço simples

**Fluxo:**
1. ✅ Criar Briefing (draft)
2. ✅ Adicionar Structure (1 quarto, 1 banheiro)
3. ✅ Adicionar Frequency (limpeza básica)
4. ✅ Calcular Métricas (50m², 2h)
5. ✅ Selecionar Package (basic)
6. ✅ Avaliar Complexity (simple, 1.0x)
7. ✅ Calcular Profissionais (1 profissional)
8. ✅ Lock (ordem #9999)
9. ✅ Criar Snapshot (validação de integridade)

**Expectativas:**
- Package: Basic (0% aumento)
- Complexity: Simple
- Profissionais: 1
- Snapshot válido com hash SHA-256

#### Teste 2: Serviço Complexo Premium
**`test_complete_flow_complex_premium_service`**

**Cenário:** Casa grande, serviço complexo com pacote premium

**Fluxo:**
1. ✅ Criar Briefing (draft)
2. ✅ Adicionar Structure (4 quartos, 3 banheiros, área externa)
3. ✅ Adicionar Frequency (limpeza pesada)
4. ✅ Calcular Métricas (200m², 6h)
5. ✅ Selecionar Package (premium)
6. ✅ Avaliar Complexity (complex, 1.5x)
7. ✅ Calcular Profissionais (≥ 2 profissionais)
8. ✅ Lock (ordem #8888)
9. ✅ Criar Snapshot (pricing breakdown completo)

**Expectativas:**
- Package: Premium (+30% aumento)
- Complexity: Complex
- Profissionais: ≥ 2 (múltiplos)
- Reasoning detalhado
- Snapshot com pricing breakdown

#### Teste 3: Briefing Locked (Immutabilidade)
**`test_locked_briefing_cannot_be_modified`**

**Cenário:** Tentativa de modificar briefing após pagamento

**Fluxo:**
1. ✅ Criar e lock Briefing
2. ❌ Tentar selecionar package (deve falhar)

**Expectativas:**
- Erro: `briefing_locked`
- Briefing permanece inalterado

---

## 📝 Convenções de Testes de Integração

### Nomenclatura
```php
// Testes de Integração
test_METHOD_ACTION_EXPECTED_RESULT()

// Exemplos:
test_save_creates_new_briefing_in_database()
test_execute_selects_premium_package_successfully()
```

### Setup/Teardown
```php
protected function setUp(): void
{
    parent::setUp();
    $this->repository = new WpBriefingRepository();
    $this->cleanupTestData(); // IMPORTANTE: Limpar antes
}

protected function tearDown(): void
{
    $this->cleanupTestData(); // IMPORTANTE: Limpar depois
    parent::tearDown();
}
```

### Cleanup de Dados
```php
private function cleanupTestData(): void
{
    global $wpdb;

    // SEMPRE usar prefixo 'test-' em UUIDs
    $wpdb->query("DELETE FROM {$table} WHERE uuid LIKE 'test-%'");
}
```

### Grupos (Tags)
```php
/**
 * @group integration
 * @group database
 */
class WpBriefingRepositoryTest extends TestCase
```

---

## 🐛 Troubleshooting

### Erro: "Table doesn't exist"

**Causa:** Migrations não executadas

**Solução:**
```bash
# Executar migrations via WP CLI ou admin
docker exec limpvix_wordpress wp db query < database-migrations/007_add_briefing_packages.sql
docker exec limpvix_wordpress wp db query < database-migrations/008_add_briefing_complexity.sql
```

### Erro: "Briefing already exists"

**Causa:** Dados de teste não foram limpos

**Solução:**
```bash
# Limpar manualmente
docker exec limpvix_wordpress wp db query "DELETE FROM wp_limpvix_briefings WHERE uuid LIKE 'test-%'"
```

### Testes Lentos

**Causa:** Testes de integração/E2E acessam banco

**Solução:** Rodar apenas unitários
```bash
./vendor/bin/phpunit --exclude-group integration,e2e
```

### Erro: "Lock wait timeout exceeded"

**Causa:** Transações abertas

**Solução:**
```bash
# Matar processos MySQL
docker exec limpvix_wordpress wp db query "SHOW PROCESSLIST"
docker exec limpvix_wordpress wp db query "KILL <process_id>"
```

---

## 📈 Estratégia de Testes

### Pirâmide de Testes

```
        ▲
       /E2E\          3 testes    (Lentos, críticos)
      /─────\
     /Integ.─\       25 testes    (Médios, componentes)
    /─────────\
   /──Unitários\     64 testes    (Rápidos, domínio)
  /─────────────\
```

### Quando Usar Cada Tipo

**Unitários:**
- Value Objects
- Policies
- Cálculos isolados
- Validações de domínio

**Integração:**
- Repositories (persistência)
- Use Cases (fluxos)
- Adapters (APIs externas)

**E2E:**
- Fluxos críticos de usuário
- Cenários de negócio completos
- Validação de contratos

---

## ✅ Checklist de Qualidade

Antes de dar merge/deploy:

- [ ] Todos unitários passando (64/64)
- [ ] Todos integração passando (25/25)
- [ ] Todos E2E passando (3/3)
- [ ] Cobertura > 85%
- [ ] Sem testes marcados como `@skip`
- [ ] Cleanup de dados funcionando
- [ ] Sem memory leaks
- [ ] Migrations executadas

---

## 🔄 CI/CD

### GitHub Actions (exemplo)

```yaml
name: Tests

on: [push, pull_request]

jobs:
  unit-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - uses: php-actions/composer@v6
      - name: Run unit tests
        run: ./vendor/bin/phpunit --exclude-group integration,e2e

  integration-tests:
    needs: unit-tests
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: wordpress_test
    steps:
      - uses: actions/checkout@v2
      - uses: php-actions/composer@v6
      - name: Setup WordPress
        run: ./bin/install-wp-tests.sh
      - name: Run integration tests
        run: ./vendor/bin/phpunit --group integration

  e2e-tests:
    needs: integration-tests
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - uses: php-actions/composer@v6
      - name: Run E2E tests
        run: ./vendor/bin/phpunit --group e2e
```

---

**Última atualização:** FASE 5 - Testes de Integração e E2E Completos
**Status:** ✅ 92 testes totais (64 unit + 25 integration + 3 E2E)
