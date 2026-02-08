# LimpVix Core

[![Tests](https://github.com/jgdeamorim/wp_limpvix-core/actions/workflows/tests.yml/badge.svg)](https://github.com/jgdeamorim/wp_limpvix-core/actions/workflows/tests.yml)
[![Code Quality](https://github.com/jgdeamorim/wp_limpvix-core/actions/workflows/code-quality.yml/badge.svg)](https://github.com/jgdeamorim/wp_limpvix-core/actions/workflows/code-quality.yml)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%20%7C%208.0%20%7C%208.1%20%7C%208.2-blue.svg)](https://www.php.net/)
[![WordPress Version](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg)](https://wordpress.org/)

**Versão:** 0.5.0
**Requer:** WordPress 6.4+, PHP 7.4+, Booknetic 4.8.5

## 📋 Descrição

LimpVix Core é uma **camada de governança soberana** sobre o Booknetic. Aplica regras de negócio específicas da LimpVix **sem modificar uma linha do Booknetic**.

### Princípios Fundamentais

- ✅ **Não-Invasão:** Booknetic permanece intocado e atualizável
- ✅ **Soberania:** LimpVix-Core é a fonte da verdade
- ✅ **Adapter Pattern:** Isolamento total da integração
- ✅ **Feature Flags:** Controle granular de funcionalidades
- ✅ **DDD Leve:** Domain-Driven Design simplificado

## 🏗️ Arquitetura

```
limpvix-core/
├─ src/
│  ├─ Core/                  # Bootstrap e Feature Flags
│  ├─ Domain/                # Entidades e Políticas
│  ├─ Application/           # Use Cases
│  ├─ Infrastructure/        # Adapters e Persistência
│  └─ Frontend/              # Guards e UI
├─ limpvix-core.php          # Plugin principal
└─ composer.json             # Dependências
```

## 🚀 Instalação

1. **Pré-requisitos:**
   - Booknetic 4.8.5 instalado e ativo
   - PHP 7.4+
   - Composer (para desenvolvimento)

2. **Instalar plugin:**
   ```bash
   cd wp-content/plugins/
   # Plugin já deve estar aqui
   cd limpvix-core
   composer install --no-dev
   ```

3. **Ativar no WordPress:**
   - WP Admin → Plugins → Ativar "LimpVix Core"

## ⚙️ Configuração Inicial

### Habilitar Core (Master Switch)

O plugin inicia **DESABILITADO** por segurança.

Para habilitar:

```php
// Via código (wp-config.php ou functions.php)
add_action('init', function() {
    $flags = new \LimpVix\Core\FeatureFlags();
    $flags->enable('core_enabled');
});
```

Ou via WP-CLI:
```bash
wp eval "\$flags = new \LimpVix\Core\FeatureFlags(); \$flags->enable('core_enabled');"
```

### Feature Flags Disponíveis

| Flag | Descrição | Default |
|------|-----------|---------|
| `core_enabled` | Master switch (desliga TUDO) | `false` |
| `intercept_appointment_creation` | Intercepta criação de appointments | `false` |
| `create_service_order` | Cria Ordem de Serviço | `false` |
| `validate_appointments` | Aplica validações customizadas | `false` |
| `audit_logging` | Habilita auditoria | `false` |
| `filter_timeslots` | Aplica SLA aos horários | `false` |
| `calculate_custom_price` | Calcula preço LimpVix | `false` |

## 🧪 Status Atual (v0.1.0)

### ✅ Implementado (PASSO 1 - Estrutura Base)

- [x] Bootstrap do plugin (Kernel)
- [x] Sistema de Feature Flags
- [x] Hooks Manager (estrutura)
- [x] Entidades de Domínio (Order, OrderStatus, OrderPolicy)
- [x] Use Case: ScheduleOrder (estrutura)
- [x] Adapter Interface para Booknetic
- [x] Frontend Guards (segurança)

### ⏳ Próximos Passos

**PASSO 2:** Bootstrap mínimo funcional
- Implementar registro de hooks real
- Testar ativação do plugin

**PASSO 3:** Feature Flags operacionais
- UI Admin para gerenciar flags
- Persistência via wp_options

**PASSO 4:** Interceptação mínima (safe mode)
- Apenas logging, sem bloquear
- Auditoria funcional

**PASSO 5:** Primeiro Use Case REAL
- ScheduleOrder completo
- Validação e bloqueio de fluxo

## 🔍 Desenvolvimento

### Rodar sem Composer (temporário)

Se não puder rodar composer install:

```php
// limpvix-core.php - comentar linha do autoloader
// require_once LIMPVIX_PLUGIN_DIR . 'vendor/autoload.php';

// Adicionar autoloader manual
spl_autoload_register(function($class) {
    if (strpos($class, 'LimpVix\\') === 0) {
        $file = str_replace('\\', '/', $class);
        $file = str_replace('LimpVix/', '', $file);
        $file = LIMPVIX_PLUGIN_DIR . 'src/' . $file . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});
```

### Debug Mode

Habilitar logs:

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Logs ficam em: `wp-content/debug.log`

## 📖 Documentação Completa

Documentação arquitetural completa em:
```
/docs-limpvix/
├── README.md
├── architecture/
│   └── 01-overview.md
├── integration/
│   ├── 01-booknetic-analysis.md
│   ├── 02-workflow-state-mapping.md
│   ├── 03-interception-points.md
│   └── 04-headless-strategy.md
└── flows/
    └── 04-state-machine.md
```

## ⚠️ Avisos Importantes

### NÃO Fazer

- ❌ Modificar código do Booknetic
- ❌ Chamar métodos privados do Booknetic
- ❌ Deletar tabelas do Booknetic
- ❌ Bypassar Feature Flags
- ❌ Habilitar tudo de uma vez

### SEMPRE Fazer

- ✅ Respeitar Feature Flags
- ✅ Testar em staging primeiro
- ✅ Ler logs (WP_DEBUG)
- ✅ Fazer backup antes de atualizar
- ✅ Habilitar features gradualmente

## 🐛 Troubleshooting

### Plugin não ativa

**Erro:** "Booknetic precisa estar instalado"
- **Solução:** Instalar e ativar Booknetic primeiro

**Erro:** "PHP 7.4 ou superior requerido"
- **Solução:** Atualizar PHP do servidor

### Core não intercepta nada

**Causa:** Feature Flag `core_enabled` está `false`
- **Solução:** Habilitar via código ou WP-CLI

### Erro "Class not found"

**Causa:** Autoloader não carregado
- **Solução:** Rodar `composer install`

## 🧪 Testes

### Cobertura de Testes (FASES 4-5)

| Tipo | Testes | Tempo | Status |
|------|--------|-------|--------|
| **Unitários** | 64 | ~1min | ✅ |
| **Integração** | 25 | ~2min | ✅ |
| **E2E** | 3 | ~1.5min | ✅ |
| **TOTAL** | **92** | **~4.5min** | ✅ **100%** |

### Rodando os Testes

```bash
# Instalar PHPUnit (se não instalado)
composer require --dev phpunit/phpunit:^9.5

# Apenas unitários (RÁPIDO: ~1min)
./vendor/bin/phpunit --exclude-group integration,e2e

# Apenas integração (~2min)
./vendor/bin/phpunit --testsuite "Integration Tests"

# Apenas E2E (~1.5min)
./vendor/bin/phpunit --testsuite "E2E Tests"

# TODOS os testes (~4.5min)
./vendor/bin/phpunit

# Com cobertura HTML
./vendor/bin/phpunit --coverage-html coverage-report
```

### Documentação de Testes

- [Testes Unitários](tests/README.md)
- [Testes de Integração e E2E](tests/INTEGRATION_TESTS.md)
- [Validação FASES 1-4](../../../docs-limpvix/FASES_1-4_VALIDATION.md)
- [Validação FASE 5](../../../docs-limpvix/FASE_5_VALIDATION.md)

## 🔄 CI/CD (FASE 6)

### GitHub Actions

O projeto possui workflows automatizados para garantir qualidade:

**1. Tests Workflow** (`.github/workflows/tests.yml`):
- ✅ Unit Tests em PHP 7.4, 8.0, 8.1, 8.2
- ✅ Integration Tests em WordPress 6.4, 6.5
- ✅ E2E Tests
- ✅ Coverage Report (Codecov)

**2. Code Quality Workflow** (`.github/workflows/code-quality.yml`):
- ✅ PHPCS (PSR-12 compliance)
- ✅ PHPStan (Static Analysis level 5)
- ✅ PHP Syntax Check
- ✅ Composer Validate
- ✅ Security Audit

### Status dos Checks

Todos os PRs e pushes para `main`/`develop` passam por:

- ✅ 92 testes (unit + integration + E2E)
- ✅ Validação de sintaxe em 4 versões de PHP
- ✅ Análise estática (PHPStan level 5)
- ✅ Code style (PSR-12)
- ✅ Security audit
- ✅ Composer validation

### Ver Status

Acompanhe o status em:
- **GitHub Actions**: https://github.com/jgdeamorim/wp_limpvix-core/actions
- **Badges**: No topo deste README

## 📝 Licença

Proprietário - LimpVix © 2026

---

**Documentação:** [docs-limpvix/README.md](../../../docs-limpvix/README.md)
**Suporte:** Equipe técnica LimpVix
**Última atualização:** 2026-02-08 (v0.5.0 - FASE 6 completa)
