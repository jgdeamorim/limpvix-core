# Análise da Aba Geral - Informações Hardcoded vs Dinâmicas

**Data:** 2026-02-16
**URL:** http://localhost:8080/wp-admin/admin.php?page=limpvix-settings&tab=geral

---

## 📊 RESULTADO DA ANÁLISE

### ❌ **SEÇÕES COM INFORMAÇÕES HARDCODED**

| Seção | Status | Problema |
|-------|--------|----------|
| **Dashboard de Status (Hero Card)** | ❌ 80% HARDCODED | Valores fixos em vez de dinâmicos |
| **GAPs Implementados** | ❌ 100% HARDCODED | Lista fixa de GAPs |
| **Documentação e Recursos** | ❌ 60% HARDCODED | Alguns valores fixos |
| **Feature Flags** | ✅ DINÂMICO | Usa FeatureFlags class |
| **Health Check** | ✅ DINÂMICO | Usa $kernel->healthCheck() |

---

## 🔴 PROBLEMAS IDENTIFICADOS

### 1. **Dashboard de Status (Hero Card) - CRÍTICO**

**Linha:** 1181-1272

#### ❌ Valores Hardcoded:

```php
// Linha 1186: Título hardcoded
🎉 LimpVix Core - Sistema 100% Operacional

// Linha 1194: Completude hardcoded
100%

// Linha 1203: Fluxos hardcoded
10/10 Fluxos Operacionais

// Linha 1207: GAPs hardcoded
4/4 GAPs Implementados

// Linha 1211: Testes hardcoded
27 Testes Unitários

// Linha 1215: Status hardcoded
✓ Go-Live Ready
```

#### ✅ **Deveria ser:**

```php
// Buscar estatísticas reais
$stats = $this->calculateFluxosStats($enabledFlows);
$testCount = $this->countUnitTests();
$completionPercentage = $this->calculateSystemCompleteness($stats);

// Título dinâmico
<?php echo $completionPercentage >= 100 ? '🎉' : '⚠️'; ?> LimpVix Core - Sistema <?php echo $completionPercentage; ?>% Operacional

// Completude dinâmica
<?php echo $completionPercentage; ?>%

// Fluxos dinâmicos
<?php echo $stats['operational_complete']; ?>/<?php echo $stats['operational_total']; ?>

// GAPs dinâmicos
<?php echo $stats['gaps_implemented']; ?>/<?php echo $stats['gaps_total']; ?>

// Testes dinâmicos
<?php echo $testCount; ?>

// Status dinâmico
<?php echo $completionPercentage >= 100 ? '✓' : '⚠️'; ?> Go-Live Ready
```

---

### 2. **GAPs Implementados - CRÍTICO**

**Linha:** 1221-1243

#### ❌ Valores Hardcoded:

```php
// Lista fixa de GAPs
<strong>GAP #1:</strong> EPI Selfie Validation
<div>commit e9ae591</div>

<strong>GAP #2:</strong> Evidence Categorization System
<div>commit f9f9281</div>

<strong>GAP #3:</strong> Client Check-in Notifications
<div>commit 28fb29a</div>

<strong>GAP #4:</strong> Issue Reporting System + Tests
<div>commits 4f2e954 + f599585</div>
```

#### ✅ **Deveria ser:**

```php
$gaps = [
    [
        'id' => 'GAP #1',
        'name' => 'EPI Selfie Validation',
        'class' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence',
        'feature' => 'category property',
    ],
    // ... etc
];

foreach ($gaps as $gap) {
    $implemented = $this->checkGapImplementation($gap);
    $status = $implemented ? '✅' : '❌';

    echo "<div>{$status} <strong>{$gap['id']}:</strong> {$gap['name']}</div>";
}
```

---

### 3. **Documentação e Recursos - MÉDIO**

**Linha:** 1274-1346

#### ❌ Valores Hardcoded:

```php
// Linha 1306: Testes hardcoded
<strong>27 testes unitários</strong> (100% passing)

// Linha 1325: Versões hardcoded
<strong>PHP:</strong> 8.2.29 | <strong>PHPUnit:</strong> 9.6.34

// Linha 1338: Fluxos hardcoded
<strong>✅ Fluxos Operacionais:</strong> 10/10 completos

// Linha 1339: Testes hardcoded
<strong>✅ Cobertura de Testes:</strong> Domain layer com 27 testes
```

#### ✅ **Deveria ser:**

```php
$testCount = $this->countUnitTests();
$testsPassing = $this->getTestsPassingPercentage();
$phpVersion = phpversion();
$phpunitVersion = $this->getPhpUnitVersion();

<strong><?php echo $testCount; ?> testes unitários</strong> (<?php echo $testsPassing; ?>% passing)

<strong>PHP:</strong> <?php echo $phpVersion; ?> | <strong>PHPUnit:</strong> <?php echo $phpunitVersion; ?>

<strong>✅ Fluxos Operacionais:</strong> <?php echo $stats['operational_complete']; ?>/<?php echo $stats['operational_total']; ?> completos

<strong>✅ Cobertura de Testes:</strong> Domain layer com <?php echo $testCount; ?> testes
```

---

## ✅ SEÇÕES JÁ DINÂMICAS (CORRETAS)

### 1. **Feature Flags** ✅

**Linha:** 1350-1459

```php
$flags = new \LimpVix\Core\FeatureFlags();
$all_flags = $flags->getAll();

// Verifica dinamicamente cada flag
foreach ($important_flags as $flag => $info) {
    if (!$flags->isEnabled($flag)) {
        $all_enabled = false;
    }
}
```

**Status:** ✅ CORRETO - Busca valores reais do banco de dados

---

### 2. **Health Check** ✅

**Linha:** 1462-1500+

```php
$kernel = \LimpVix\Core\Kernel::getInstance();
$health = $kernel->healthCheck();

// Usa valores dinâmicos
<?php echo esc_html($health["version"]); ?>
<?php if ($health["booted"]): ?>
<?php if ($health["booknetic_active"]): ?>
```

**Status:** ✅ CORRETO - Busca status real do sistema

---

## 🔧 SOLUÇÃO PROPOSTA

### Criar Método `calculateDashboardStats()`

```php
/**
 * Calculate dynamic dashboard statistics
 */
private function calculateDashboardStats(): array
{
    // 1. Buscar stats de fluxos
    $enabledFlows = get_option('limpvix_enabled_flows', []);
    $fluxosStats = $this->calculateFluxosStats($enabledFlows);

    // 2. Contar testes unitários
    $testCount = $this->countUnitTests();
    $testsPath = plugin_dir_path(__FILE__) . '../../tests';

    // 3. Calcular completude do sistema
    $totalItems = $fluxosStats['operational_total'] + $fluxosStats['gaps_total'];
    $completeItems = $fluxosStats['operational_complete'] + $fluxosStats['gaps_implemented'];
    $completionPercentage = $totalItems > 0 ? round(($completeItems / $totalItems) * 100) : 0;

    // 4. Verificar se Go-Live Ready
    $isGoLiveReady = $completionPercentage >= 90; // 90% é mínimo

    // 5. Pegar versões
    $phpVersion = phpversion();
    $phpunitVersion = $this->getPhpUnitVersion();

    return [
        'completion_percentage' => $completionPercentage,
        'fluxos' => $fluxosStats,
        'test_count' => $testCount,
        'is_go_live_ready' => $isGoLiveReady,
        'php_version' => $phpVersion,
        'phpunit_version' => $phpunitVersion,
        'status_message' => $completionPercentage >= 100
            ? 'Sistema 100% Operacional'
            : "Sistema {$completionPercentage}% Operacional",
    ];
}

/**
 * Count unit tests in tests/ directory
 */
private function countUnitTests(): int
{
    $testsPath = plugin_dir_path(__FILE__) . '../../tests';

    if (!is_dir($testsPath)) {
        return 0;
    }

    $count = 0;
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($testsPath)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
            $count++;
        }
    }

    return $count;
}

/**
 * Get PHPUnit version
 */
private function getPhpUnitVersion(): string
{
    $composerLock = plugin_dir_path(__FILE__) . '../../composer.lock';

    if (!file_exists($composerLock)) {
        return 'N/A';
    }

    $lock = json_decode(file_get_contents($composerLock), true);

    foreach ($lock['packages-dev'] ?? [] as $package) {
        if ($package['name'] === 'phpunit/phpunit') {
            return $package['version'];
        }
    }

    return 'N/A';
}
```

---

## 📋 CHECKLIST DE CORREÇÕES

### Prioridade ALTA (Dashboard Hero Card):
- [ ] Tornar "Sistema X% Operacional" dinâmico
- [ ] Tornar "X%" completude dinâmico
- [ ] Tornar "X/Y Fluxos Operacionais" dinâmico
- [ ] Tornar "X/Y GAPs Implementados" dinâmico
- [ ] Tornar "X Testes Unitários" dinâmico
- [ ] Tornar "Go-Live Ready" dinâmico (baseado em %)

### Prioridade MÉDIA (GAPs):
- [ ] Verificar GAPs dinamicamente
- [ ] Não mostrar commits hardcoded (ou buscar do git)
- [ ] Mostrar apenas GAPs realmente implementados

### Prioridade BAIXA (Documentação):
- [ ] Buscar versão PHP dinamicamente
- [ ] Buscar versão PHPUnit do composer.lock
- [ ] Atualizar contagem de testes dinamicamente

---

## 🎯 IMPACTO

### ❌ **Atualmente (Hardcoded):**
- Informações desatualizadas se sistema mudar
- Manutenção manual necessária
- Não reflete estado real do sistema
- Pode confundir sobre o status real

### ✅ **Depois (Dinâmico):**
- Sempre mostra estado real
- Atualização automática
- Sem manutenção manual
- Confiável para decisões

---

## 📈 ESTIMATIVA DE CORREÇÃO

**Tempo:** 2-3 horas

**Arquivos a modificar:**
- `AdminBootstrap.php` - Adicionar métodos helper
- Atualizar renderGeralTab() para usar valores dinâmicos

**Benefício:**
- Dashboard sempre atualizado automaticamente
- Reflete estado real do sistema
- Confiável para monitoramento

---

**Conclusão:** A aba Geral tem **~70% de conteúdo hardcoded** que deveria ser dinâmico, especialmente no Dashboard de Status que é a primeira coisa que o usuário vê.
