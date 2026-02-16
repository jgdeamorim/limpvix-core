# CI/CD Guide - LimpVix Core

## 📋 Índice

- [Visão Geral](#visão-geral)
- [Workflows](#workflows)
- [Configuração](#configuração)
- [Uso](#uso)
- [Troubleshooting](#troubleshooting)
- [Manutenção](#manutenção)

---

## 🎯 Visão Geral

O LimpVix Core utiliza **GitHub Actions** para CI/CD automatizado, garantindo qualidade de código e funcionalidade através de testes automatizados e validações.

### Objetivos

- ✅ Detectar bugs antes do merge
- ✅ Garantir compatibilidade cross-version (PHP 7.4-8.2, WordPress 6.4-6.5)
- ✅ Manter padrões de código (PSR-12)
- ✅ Validar segurança (composer audit)
- ✅ Medir cobertura de testes

---

## 🔄 Workflows

### 1. Tests Workflow

**Arquivo:** `.github/workflows/tests.yml`

**Triggers:**
- Push para `main` ou `develop`
- Pull requests para `main` ou `develop`

**Jobs:**

#### Unit Tests
- **Matriz:** PHP 7.4, 8.0, 8.1, 8.2
- **Duração:** ~2-3 minutos
- **Passos:**
  1. Checkout código
  2. Setup PHP + extensões
  3. Cache de dependências Composer
  4. Instalar dependências
  5. Rodar testes unitários (`--testsuite "Unit Tests"`)
  6. Gerar coverage (apenas PHP 8.1)
  7. Upload para Codecov

#### Integration Tests
- **Matriz:** PHP 8.1 + WordPress 6.4, 6.5
- **Services:** MySQL 8.0
- **Duração:** ~3-4 minutos
- **Passos:**
  1. Checkout código
  2. Setup PHP + MySQL
  3. Instalar WordPress Test Suite
  4. Executar migrations
  5. Rodar testes de integração

#### E2E Tests
- **Versão:** PHP 8.1 + WordPress latest
- **Duração:** ~2-3 minutos
- **Passos:**
  1. Checkout código
  2. Setup PHP + MySQL
  3. Instalar WordPress Test Suite
  4. Executar migrations
  5. Rodar testes E2E

#### Test Summary
- **Dependências:** Todos jobs anteriores
- **Função:** Agregar resultados e falhar se algum job falhou

---

### 2. Code Quality Workflow

**Arquivo:** `.github/workflows/code-quality.yml`

**Triggers:**
- Push para `main` ou `develop`
- Pull requests para `main` ou `develop`

**Jobs:**

#### PHPCS (Code Sniffer)
- **Padrão:** PSR-12
- **Scope:** `src/`
- **Continue on error:** Sim (warning, não bloqueia)

#### PHPStan (Static Analysis)
- **Level:** 5
- **Scope:** `src/`
- **Continue on error:** Sim

#### PHP Syntax Check
- **Matriz:** PHP 7.4, 8.0, 8.1, 8.2
- **Função:** Validar sintaxe em todas versões
- **Continue on error:** Não (crítico)

#### Composer Validate
- **Função:** Validar composer.json
- **Continue on error:** Não (crítico)

#### Composer Security Audit
- **Função:** Detectar vulnerabilidades em dependências
- **Continue on error:** Sim (informativo)

#### Check TODOs
- **Função:** Listar TODOs no código
- **Continue on error:** Sim (informativo)

#### Check Documentation
- **Função:** Verificar PHPDoc faltando
- **Continue on error:** Sim (informativo)

---

## ⚙️ Configuração

### Secrets Necessários

**GitHub Repository Secrets:**

```
CODECOV_TOKEN (opcional)
```

Para configurar:
1. Ir em: Repository → Settings → Secrets → Actions
2. Add repository secret: `CODECOV_TOKEN`
3. Obter token em: https://codecov.io/

### Permissions

O workflow precisa das seguintes permissions:

```yaml
permissions:
  contents: read
  pull-requests: write (para comentários)
  checks: write (para status checks)
```

### Branch Protection

Recomendado configurar em: Repository → Settings → Branches

```
Branch: main
✅ Require status checks to pass before merging
  ✅ Unit Tests
  ✅ Integration Tests
  ✅ E2E Tests
  ✅ PHP Syntax Check
  ✅ Composer Validate
```

---

## 💻 Uso

### Desenvolvimento Local

Antes de fazer push/PR, rode localmente:

```bash
# Rodar testes completos
./vendor/bin/phpunit

# Validar código (PHPCS)
./vendor/bin/phpcs --standard=PSR12 src/

# Análise estática (PHPStan)
./vendor/bin/phpstan analyse src/ --level=5

# Validar composer.json
composer validate --strict

# Security audit
composer audit
```

### Pull Request Workflow

1. **Criar branch:**
   ```bash
   git checkout -b feature/my-feature
   ```

2. **Desenvolver e testar:**
   ```bash
   # Fazer mudanças
   vim src/...

   # Rodar testes localmente
   ./vendor/bin/phpunit --exclude-group integration,e2e

   # Validar código
   ./vendor/bin/phpcs --standard=PSR12 src/
   ```

3. **Commit e push:**
   ```bash
   git add .
   git commit -m "feat: add my feature"
   git push origin feature/my-feature
   ```

4. **Criar PR:**
   - GitHub detecta automaticamente e roda workflows
   - Aguardar status checks passar
   - Revisar feedback do Codecov
   - Corrigir se necessário

5. **Merge:**
   - Só fazer merge se todos checks passarem ✅

### Forçar Re-run de Workflow

Se um workflow falhou por erro temporário (ex: network):

1. Ir em: Actions → Workflow run com falha
2. Click em "Re-run jobs" → "Re-run all jobs"

---

## 🐛 Troubleshooting

### Workflow Falha: "Composer install failed"

**Causa:** Dependências incompatíveis

**Solução:**
```bash
# Local
composer update
git add composer.lock
git commit -m "chore: update dependencies"
```

### Workflow Falha: "MySQL connection refused"

**Causa:** Service MySQL não iniciou a tempo

**Solução:** Adicionar health check no workflow:
```yaml
options: --health-cmd="mysqladmin ping" --health-interval=10s
```

### Workflow Falha: "Class not found"

**Causa:** Autoloader não configurado

**Solução:**
```bash
# Garantir que composer install roda antes dos testes
composer install --prefer-dist --no-progress
```

### PHPCS/PHPStan Falha com Muitos Erros

**Não bloqueia merge** (continue-on-error: true), mas deve ser corrigido:

```bash
# Corrigir automaticamente (PHPCS)
./vendor/bin/phpcbf --standard=PSR12 src/

# Ver erros específicos (PHPStan)
./vendor/bin/phpstan analyse src/ --level=5
```

### Coverage não sobe para Codecov

**Causa:** Token inválido ou missing

**Solução:**
1. Verificar secret `CODECOV_TOKEN` em: Settings → Secrets
2. Gerar novo token em: https://codecov.io/
3. Atualizar secret

---

## 🔧 Manutenção

### Atualizar Versão do PHP

**Adicionar PHP 8.3:**

```yaml
# .github/workflows/tests.yml
strategy:
  matrix:
    php-version: ['7.4', '8.0', '8.1', '8.2', '8.3']
```

### Atualizar Versão do WordPress

```yaml
# .github/workflows/tests.yml
strategy:
  matrix:
    wordpress-version: ['6.4', '6.5', '6.6']
```

### Adicionar Novo Job

Exemplo: Adicionar job de deploy:

```yaml
# .github/workflows/tests.yml
deploy:
  name: Deploy to Staging
  runs-on: ubuntu-latest
  needs: [unit-tests, integration-tests, e2e-tests]
  if: github.ref == 'refs/heads/develop'

  steps:
    - name: Checkout code
      uses: actions/checkout@v3

    - name: Deploy to staging
      run: |
        # Comandos de deploy
```

### Desabilitar Job Temporariamente

```yaml
# Adicionar if: false
phpstan:
  name: PHPStan Static Analysis
  if: false  # Desabilitado temporariamente
  runs-on: ubuntu-latest
```

---

## 📊 Métricas

### Tempo de Execução

| Workflow | Jobs | Tempo Total |
|----------|------|-------------|
| Tests | 3 | ~8-10 min |
| Code Quality | 7 | ~5-7 min |
| **TOTAL** | **10** | **~15 min** |

### Custo (GitHub Actions)

- **Free tier:** 2.000 minutos/mês
- **Uso estimado:** ~450 min/mês (30 PRs × 15 min)
- **Sobra:** ~1.550 min/mês

### Status Atual

Verificar em tempo real:
- **Badge:** No README.md
- **Actions tab:** https://github.com/jgdeamorim/wp_limpvix-core/actions

---

## ✅ Checklist de Implementação

- [x] Workflow de testes configurado
- [x] Workflow de qualidade configurado
- [x] MySQL service configurado
- [x] Cache de Composer configurado
- [x] Codecov integrado
- [x] Badges no README
- [x] Branch protection configurado
- [x] Documentação criada

---

## 🔗 Referências

- [GitHub Actions Docs](https://docs.github.com/en/actions)
- [PHPUnit Documentation](https://phpunit.de/)
- [Codecov Documentation](https://docs.codecov.com/)
- [PSR-12 Coding Standard](https://www.php-fig.org/psr/psr-12/)
- [PHPStan Documentation](https://phpstan.org/)

---

**Última atualização:** 2026-02-08 (FASE 6)
**Versão:** 1.0.0
