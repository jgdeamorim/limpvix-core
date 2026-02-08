# LimpVix Core - Testes Unitários

## 📋 Estrutura de Testes

```
tests/
├── Domain/
│   └── Briefing/
│       ├── PackageTest.php                          # Testes para Package Value Object
│       ├── ComplexityTest.php                       # Testes para Complexity Value Object
│       ├── ProfessionalAllocationTest.php           # Testes para ProfessionalAllocation VO
│       └── ProfessionalAllocationPolicyTest.php     # Testes para Policy de alocação
├── Application/
│   └── (futuros testes de Use Cases)
├── Infrastructure/
│   └── (futuros testes de Repositories)
├── bootstrap.php                                     # Bootstrap para testes
└── README.md                                         # Este arquivo
```

## 🚀 Instalação

### 1. Instalar PHPUnit

```bash
cd /media/jeffer/.../wp-content/plugins/limpvix-core
composer require --dev phpunit/phpunit:^9.5
```

### 2. Verificar Instalação

```bash
./vendor/bin/phpunit --version
# Output esperado: PHPUnit 9.5.x
```

## ▶️ Rodando os Testes

### Rodar Todos os Testes

```bash
./vendor/bin/phpunit
```

### Rodar Testes Específicos

**Por Test Suite:**
```bash
# Apenas testes de domínio
./vendor/bin/phpunit --testsuite "Domain Tests"

# Apenas testes de aplicação
./vendor/bin/phpunit --testsuite "Application Tests"
```

**Por Arquivo:**
```bash
# Apenas testes de Package
./vendor/bin/phpunit tests/Domain/Briefing/PackageTest.php

# Apenas testes de Complexity
./vendor/bin/phpunit tests/Domain/Briefing/ComplexityTest.php

# Apenas testes de ProfessionalAllocationPolicy
./vendor/bin/phpunit tests/Domain/Briefing/ProfessionalAllocationPolicyTest.php
```

**Por Método:**
```bash
# Rodar apenas um teste específico
./vendor/bin/phpunit --filter test_premium_package_requires_minimum_two
```

### Rodar com Cobertura de Código

```bash
# Gerar relatório HTML
./vendor/bin/phpunit --coverage-html coverage-report

# Ver relatório no navegador
firefox coverage-report/index.html
```

### Rodar com Verbose

```bash
./vendor/bin/phpunit --verbose
```

### Rodar e Parar no Primeiro Erro

```bash
./vendor/bin/phpunit --stop-on-failure
```

## 📊 Cobertura de Testes

### FASE 1-3 (Packages, Complexity, Allocation)

| Componente | Testes | Status |
|------------|--------|--------|
| **Package** | 15 testes | ✅ Completo |
| **Complexity** | 14 testes | ✅ Completo |
| **ProfessionalAllocation** | 18 testes | ✅ Completo |
| **ProfessionalAllocationPolicy** | 17 testes | ✅ Completo |
| **Total** | **64 testes** | ✅ **100%** |

### Testes por Categoria

**PackageTest.php (15 testes):**
- ✅ Factory methods (basic, standard, premium)
- ✅ Cálculo de preço final
- ✅ Determinação de profissionais por duração
- ✅ Cap no máximo de profissionais
- ✅ Verificação de skills
- ✅ Display de percentual
- ✅ Serialização (toArray)
- ✅ Criação via config
- ✅ Comparação (equals)
- ✅ Multiplier
- ✅ Imutabilidade
- ✅ Validações de erro

**ComplexityTest.php (14 testes):**
- ✅ Factory methods (simple, medium, complex)
- ✅ Aplicação de multiplier em duração
- ✅ Aplicação de multiplier em preço
- ✅ Reasoning (withReasoning)
- ✅ Comparação (equals, isMoreComplexThan)
- ✅ Display string
- ✅ Serialização (toArray)
- ✅ Multipliers default
- ✅ Imutabilidade
- ✅ Validações de erro

**ProfessionalAllocationTest.php (18 testes):**
- ✅ Factory methods (single, multiple, fromConfig)
- ✅ requiresMultiple()
- ✅ isAtMaxCapacity()
- ✅ canAddMore()
- ✅ withReason() (imutável)
- ✅ withCount() (imutável, capped, min)
- ✅ Display string
- ✅ Comparações (equals, requiresMoreThan)
- ✅ Serialização (toArray)
- ✅ toString
- ✅ Validações de erro
- ✅ Imutabilidade completa

**ProfessionalAllocationPolicyTest.php (17 testes):**
- ✅ Single para serviço simples
- ✅ Múltiplos para duração longa (> 5h)
- ✅ Múltiplos para área muito grande (> 200m²)
- ✅ Complex + área grande → mín 2
- ✅ Premium package → mín 2
- ✅ Pós-obra → sempre múltiplos
- ✅ Cap no máximo permitido
- ✅ Simulação (simulate)
- ✅ Basic package → single
- ✅ Standard package → até 2
- ✅ Sem métricas → single default
- ✅ Regras combinadas
- ✅ Configuração personalizável
- ✅ Reset de configuração

## 🎯 Exemplos de Uso

### Rodar Testes Rapidamente (CI/CD)

```bash
./vendor/bin/phpunit --no-coverage --stop-on-failure
```

### Rodar Testes com Output Detalhado

```bash
./vendor/bin/phpunit --testdox
```

Output esperado:
```
Package
 ✔ Basic package factory creates correctly
 ✔ Standard package factory creates correctly
 ✔ Premium package factory creates correctly
 ✔ Calculates final price correctly
 ...

Complexity
 ✔ Simple complexity factory creates correctly
 ✔ Medium complexity factory creates correctly
 ...

Professional Allocation Policy
 ✔ Single professional for simple service
 ✔ Multiple professionals for long duration
 ✔ Multiple professionals for very large area
 ...
```

## 🐛 Troubleshooting

### Erro: "Class not found"

**Solução:** Verificar autoloader no bootstrap.php

```bash
# Verificar que autoloader está carregando classes
./vendor/bin/phpunit --verbose
```

### Erro: "Function get_option() not found"

**Solução:** Bootstrap define mock functions do WordPress. Verificar que bootstrap.php está sendo carregado.

### Erro: "No tests executed"

**Solução:** Verificar que arquivos de teste terminam com `Test.php`

## 📝 Escrevendo Novos Testes

### Template Básico

```php
<?php
namespace LimpVix\Tests\Domain\Briefing;

use PHPUnit\Framework\TestCase;

class MyTest extends TestCase
{
    /**
     * @test
     */
    public function test_something_works_correctly()
    {
        // Arrange
        $input = 'value';

        // Act
        $result = doSomething($input);

        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

### Convenções

- ✅ Nome do arquivo: `ClassNameTest.php`
- ✅ Nome do método: `test_snake_case_description`
- ✅ Usar annotation `@test`
- ✅ AAA Pattern: Arrange, Act, Assert
- ✅ Um assert por teste (quando possível)
- ✅ Testar comportamento, não implementação

## 🔍 Integração Contínua (CI/CD)

### GitHub Actions (exemplo)

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - uses: php-actions/composer@v6
      - name: Run tests
        run: ./vendor/bin/phpunit --no-coverage
```

## 📚 Referências

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Test-Driven Development (TDD)](https://www.agilealliance.org/glossary/tdd/)
- [WordPress Plugin Testing](https://make.wordpress.org/cli/handbook/plugin-unit-tests/)

## ✅ Próximos Passos

- [ ] Testes de integração (WpBriefingRepository)
- [ ] Testes de Use Cases (Application layer)
- [ ] Testes E2E (fluxo completo)
- [ ] Setup de CI/CD com GitHub Actions
- [ ] Alcançar 90%+ de cobertura de código

---

**Última atualização:** FASE 4 - Testes Unitários Completos
**Status:** ✅ 64 testes implementados e passando
