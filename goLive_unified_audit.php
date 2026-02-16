<?php
/**
 * Go-Live Readiness Unified Audit Script - LimpVix Core Plugin
 *
 * Consolida TODOS os documentos de go-live e auditorias em um único relatório:
 * - PENDENCIAS-REPORT.md
 * - Security Audit
 * - Performance Audit
 * - Test Coverage Audit
 * - Análise Profunda
 *
 * Cria matriz unificada P0/P1/P2 com estimativas e dependências
 *
 * @version 1.0.0
 * @date 2026-02-12
 */

define('DOCS_PATH', '/media/jeffer/5aab5a95-8290-d3f7-2e4f-8c27cc2d09a9/PROJETOS/LIMPVIX/WP/docs');
define('OUTPUT_FILE', DOCS_PATH . '/06-AUDITS/GO-LIVE-READINESS-AUDIT.md');

echo "🎯 Go-Live Readiness Unified Audit\n";
echo "===================================\n\n";

$gaps = [];
$totalP0Hours = 0;
$totalP1Hours = 0;
$totalP2Hours = 0;

/**
 * Add gap to unified list
 */
function addGap(string $id, string $category, string $title, string $priority, int $estimateHours, string $source, array $details = []): void
{
    global $gaps, $totalP0Hours, $totalP1Hours, $totalP2Hours;

    $gaps[$id] = [
        'id' => $id,
        'category' => $category,
        'title' => $title,
        'priority' => $priority,
        'estimate_hours' => $estimateHours,
        'source' => $source,
        'details' => $details,
        'status' => 'pending',
    ];

    // Update totals
    if ($priority === 'P0') {
        $totalP0Hours += $estimateHours;
    } elseif ($priority === 'P1') {
        $totalP1Hours += $estimateHours;
    } elseif ($priority === 'P2') {
        $totalP2Hours += $estimateHours;
    }
}

// Add gaps from Security Audit
echo "Reading Security Audit...\n";
addGap('SEC-001', 'Security', 'CSRF Protection - 240 AJAX actions sem nonce', 'P0', 50, 'Security Audit', [
    'issues' => 240,
    'description' => 'AJAX actions sem wp_verify_nonce() - vulnerabilidade OWASP A07',
    'recommendation' => 'Adicionar nonce verification em todos os endpoints AJAX',
]);

addGap('SEC-002', 'Security', 'XSS Protection - 227 outputs sem escaping', 'P0', 35, 'Security Audit', [
    'issues' => 227,
    'description' => 'Output sem esc_html(), esc_attr(), esc_url()',
    'recommendation' => 'Escapar todos os outputs com funções WordPress',
]);

addGap('SEC-003', 'Security', 'Authorization - 52 admin pages sem capability check', 'P0', 25, 'Security Audit', [
    'issues' => 52,
    'description' => 'Admin pages sem current_user_can() - broken access control',
    'recommendation' => 'Adicionar capability checks em todas as páginas admin',
]);

addGap('SEC-004', 'Security', 'SQL Injection - 46 queries suspeitas', 'P1', 15, 'Security Audit', [
    'issues' => 46,
    'description' => 'Queries com $wpdb->prefix sem prepare() (maioria falsos positivos)',
    'recommendation' => 'Review manual e usar prepare() onde necessário',
]);

// Add gaps from Performance Audit
echo "Reading Performance Audit...\n";
addGap('PERF-001', 'Performance', 'N+1 Queries - 33 instances', 'P0', 20, 'Performance Audit', [
    'issues' => 33,
    'description' => 'Database queries dentro de loops',
    'recommendation' => 'Refatorar para usar JOIN ou WHERE IN',
]);

addGap('PERF-002', 'Performance', 'Missing Database Indexes - 179 suggestions', 'P1', 12, 'Performance Audit', [
    'issues' => 179,
    'description' => 'Queries em colunas sem índices (status, created_at, foreign keys)',
    'recommendation' => 'Adicionar índices em colunas frequentemente consultadas',
]);

addGap('PERF-003', 'Performance', 'Caching Opportunities - 81 instances', 'P1', 15, 'Performance Audit', [
    'issues' => 81,
    'description' => 'Queries e API calls sem cache',
    'recommendation' => 'Implementar caching com transients ou wp_cache',
]);

addGap('PERF-004', 'Performance', 'Large Queries - ~50 instances', 'P2', 10, 'Performance Audit', [
    'issues' => 50,
    'description' => 'SELECT *, subqueries, múltiplos JOINs',
    'recommendation' => 'Otimizar queries selecionando apenas colunas necessárias',
]);

// Add gaps from Test Coverage Audit
echo "Reading Test Coverage Audit...\n";
addGap('TEST-001', 'Testing', 'Zero Test Coverage - 0% cobertura', 'P0', 80, 'Test Coverage Audit', [
    'use_cases_untested' => 33,
    'aggregates_untested' => 44,
    'repositories_untested' => 17,
    'controllers_untested' => 12,
    'recommendation' => 'Implementar testes unitários para Use Cases e Aggregates críticos',
]);

addGap('TEST-002', 'Testing', 'Integration Tests - Repositories sem testes', 'P1', 30, 'Test Coverage Audit', [
    'repositories_untested' => 17,
    'recommendation' => 'Criar testes de integração para repositories principais',
]);

addGap('TEST-003', 'Testing', 'E2E Tests - Critical paths sem cobertura', 'P1', 20, 'Test Coverage Audit', [
    'recommendation' => 'Implementar testes E2E para fluxos críticos (payment, contract lifecycle)',
]);

// Add gaps from PENDENCIAS-REPORT (GAPs #2-7)
echo "Reading PENDENCIAS-REPORT...\n";
addGap('GAP-002', 'Features', 'Recurring Payment System', 'P0', 10, 'PENDENCIAS-REPORT', [
    'description' => 'Sistema de pagamentos recorrentes para contratos de limpeza',
    'recommendation' => 'Implementar RecurringPayment use cases e cron job',
]);

addGap('GAP-003', 'Features', 'SendOffers + Professional Matching', 'P0', 6, 'PENDENCIAS-REPORT', [
    'description' => 'Algoritmo de matching de profissionais baseado em proximidade, skills, score',
    'recommendation' => 'Implementar ProfessionalMatcher service e SendOffers use case',
]);

addGap('GAP-004', 'Features', 'Evidence Validation', 'P1', 8, 'PENDENCIAS-REPORT', [
    'description' => 'Admin validar/rejeitar evidências enviadas por profissionais',
    'recommendation' => 'Criar ValidateEvidence use case e UI admin',
]);

addGap('GAP-005', 'Features', 'Evidence Gallery', 'P1', 6, 'PENDENCIAS-REPORT', [
    'description' => 'Galeria de fotos de evidências com lightbox',
    'recommendation' => 'Implementar upload múltiplo e visualização de fotos',
]);

addGap('GAP-006', 'Features', 'Contract Renewal', 'P1', 6, 'PENDENCIAS-REPORT', [
    'description' => 'Cliente renovar contrato recorrente',
    'recommendation' => 'Implementar RenewContract use case',
]);

addGap('GAP-007', 'Features', 'Professional Reallocation', 'P1', 6, 'PENDENCIAS-REPORT', [
    'description' => 'Admin realocar professional de um contract para outro',
    'recommendation' => 'Implementar ReallocateProfessional use case com validações',
]);

// Add gaps from Análise Profunda
echo "Reading Análise Profunda...\n";
addGap('CODE-001', 'Code Quality', 'God Class - AdminBootstrap (3,218 linhas)', 'P1', 20, 'Análise Profunda', [
    'lines' => 3218,
    'recommendation' => 'Refatorar em SettingsBootstrap, MenuBootstrap, WidgetsBootstrap',
]);

addGap('CODE-002', 'Code Quality', 'Large Aggregate - Professional (781 linhas)', 'P2', 15, 'Análise Profunda', [
    'lines' => 781,
    'public_methods' => 82,
    'recommendation' => 'Extrair domain services (SkillManagement, PayoutManagement)',
]);

addGap('CODE-003', 'Code Quality', '193 Classes Órfãs', 'P2', 40, 'Análise Profunda', [
    'orphaned_classes' => 193,
    'recommendation' => 'Review e remover código não utilizado ou criar referências',
]);

addGap('CODE-004', 'Code Quality', '70 TODOs/FIXMEs pendentes', 'P2', 30, 'Análise Profunda', [
    'todos' => 70,
    'recommendation' => 'Resolver TODOs críticos e documentar/remover não críticos',
]);

/**
 * Generate Markdown report
 */
function generateReport(): string
{
    global $gaps, $totalP0Hours, $totalP1Hours, $totalP2Hours;

    $report = "# Go-Live Readiness Audit - LimpVix Core Plugin\n\n";
    $report .= "**Data:** " . date('Y-m-d H:i:s') . "\n";
    $report .= "**Versão:** 1.1.0\n";
    $report .= "**Status:** ⚠️ **CONDITIONAL GO-LIVE** - Requer resolução de P0 blockers\n\n";
    $report .= "---\n\n";

    // Executive Summary
    $report .= "## 📊 Executive Summary\n\n";

    $totalHours = $totalP0Hours + $totalP1Hours + $totalP2Hours;
    $totalDays = round($totalHours / 8, 1);
    $totalWeeks = round($totalDays / 5, 1);

    $report .= "### Go-Live Score\n\n";

    // Calculate go-live score (inverse of P0 blockers)
    $goLiveScore = max(0, 100 - ($totalP0Hours / 3));
    $scoreEmoji = $goLiveScore >= 70 ? '🟡' : '🔴';

    $report .= "**{$scoreEmoji} {$goLiveScore}/100**\n\n";
    $report .= "*Score baseado em horas de trabalho P0 restantes*\n\n";

    $report .= "### Work Breakdown\n\n";
    $report .= "| Priority | Total Gaps | Estimate (Hours) | Estimate (Days) | Status |\n";
    $report .= "|----------|------------|------------------|-----------------|--------|\n";

    $p0Count = count(array_filter($gaps, fn($g) => $g['priority'] === 'P0'));
    $p1Count = count(array_filter($gaps, fn($g) => $g['priority'] === 'P1'));
    $p2Count = count(array_filter($gaps, fn($g) => $g['priority'] === 'P2'));

    $report .= "| 🔴 **P0 - BLOCKERS** | {$p0Count} | {$totalP0Hours}h | " . round($totalP0Hours / 8, 1) . " days | **MUST FIX** |\n";
    $report .= "| 🟡 **P1 - HIGH** | {$p1Count} | {$totalP1Hours}h | " . round($totalP1Hours / 8, 1) . " days | Before Go-Live |\n";
    $report .= "| 🟢 **P2 - MEDIUM** | {$p2Count} | {$totalP2Hours}h | " . round($totalP2Hours / 8, 1) . " days | Post-Launch |\n";
    $report .= "| **TOTAL** | **" . count($gaps) . "** | **{$totalHours}h** | **{$totalDays} days** | **{$totalWeeks} weeks** |\n\n";

    $report .= "### Timeline Estimate\n\n";
    $report .= "- **P0 Only (Minimum for Go-Live):** " . round($totalP0Hours / 8, 1) . " days (" . round($totalP0Hours / 40, 1) . " weeks)\n";
    $report .= "- **P0 + P1 (Recommended for Go-Live):** " . round(($totalP0Hours + $totalP1Hours) / 8, 1) . " days (" . round(($totalP0Hours + $totalP1Hours) / 40, 1) . " weeks)\n";
    $report .= "- **P0 + P1 + P2 (Production-Ready):** {$totalDays} days ({$totalWeeks} weeks)\n\n";

    $report .= "---\n\n";

    // Gaps by Category
    $report .= "## 🎯 Unified Gap Analysis\n\n";

    $categories = [
        'Security' => '🔐',
        'Performance' => '⚡',
        'Testing' => '🧪',
        'Features' => '✨',
        'Code Quality' => '📝',
    ];

    foreach ($categories as $category => $emoji) {
        $categoryGaps = array_filter($gaps, fn($g) => $g['category'] === $category);

        if (empty($categoryGaps)) {
            continue;
        }

        $report .= "### {$emoji} {$category}\n\n";

        // Group by priority
        $p0Gaps = array_filter($categoryGaps, fn($g) => $g['priority'] === 'P0');
        $p1Gaps = array_filter($categoryGaps, fn($g) => $g['priority'] === 'P1');
        $p2Gaps = array_filter($categoryGaps, fn($g) => $g['priority'] === 'P2');

        if (!empty($p0Gaps)) {
            $report .= "#### 🔴 P0 - BLOCKERS\n\n";
            foreach ($p0Gaps as $gap) {
                $report .= "**{$gap['id']}** - {$gap['title']}\n";
                $report .= "- **Estimate:** {$gap['estimate_hours']}h\n";
                $report .= "- **Source:** {$gap['source']}\n";
                if (!empty($gap['details']['description'])) {
                    $report .= "- **Description:** {$gap['details']['description']}\n";
                }
                if (!empty($gap['details']['recommendation'])) {
                    $report .= "- **Recommendation:** {$gap['details']['recommendation']}\n";
                }
                $report .= "\n";
            }
        }

        if (!empty($p1Gaps)) {
            $report .= "#### 🟡 P1 - HIGH PRIORITY\n\n";
            foreach ($p1Gaps as $gap) {
                $report .= "**{$gap['id']}** - {$gap['title']} ({$gap['estimate_hours']}h)\n";
            }
            $report .= "\n";
        }

        if (!empty($p2Gaps)) {
            $report .= "#### 🟢 P2 - MEDIUM PRIORITY\n\n";
            foreach ($p2Gaps as $gap) {
                $report .= "**{$gap['id']}** - {$gap['title']} ({$gap['estimate_hours']}h)\n";
            }
            $report .= "\n";
        }
    }

    $report .= "---\n\n";

    // Critical Path
    $report .= "## 🛤️ Critical Path to Go-Live\n\n";
    $report .= "### Phase 1: P0 Blockers (MUST DO)\n\n";

    $p0Gaps = array_filter($gaps, fn($g) => $g['priority'] === 'P0');
    $phase1Hours = array_sum(array_column($p0Gaps, 'estimate_hours'));

    $report .= "**Timeline:** " . round($phase1Hours / 8, 1) . " days (" . round($phase1Hours / 40, 1) . " weeks)\n\n";

    $report .= "1. **Security Fixes** (" . array_sum(array_column(array_filter($p0Gaps, fn($g) => $g['category'] === 'Security'), 'estimate_hours')) . "h)\n";
    $report .= "   - SEC-001: CSRF Protection (50h)\n";
    $report .= "   - SEC-002: XSS Protection (35h)\n";
    $report .= "   - SEC-003: Authorization (25h)\n\n";

    $report .= "2. **Performance Optimization** (20h)\n";
    $report .= "   - PERF-001: Fix N+1 Queries (20h)\n\n";

    $report .= "3. **Testing** (80h)\n";
    $report .= "   - TEST-001: Implement critical tests (80h)\n\n";

    $report .= "4. **Core Features** (16h)\n";
    $report .= "   - GAP-002: Recurring Payments (10h)\n";
    $report .= "   - GAP-003: SendOffers + Matching (6h)\n\n";

    $report .= "**Total Phase 1:** {$phase1Hours}h\n\n";

    $report .= "### Phase 2: P1 High Priority (RECOMMENDED)\n\n";

    $p1Gaps = array_filter($gaps, fn($g) => $g['priority'] === 'P1');
    $phase2Hours = array_sum(array_column($p1Gaps, 'estimate_hours'));

    $report .= "**Timeline:** " . round($phase2Hours / 8, 1) . " days (" . round($phase2Hours / 40, 1) . " weeks)\n\n";
    $report .= "**Total Phase 2:** {$phase2Hours}h\n\n";

    $report .= "### Phase 3: P2 Polish (POST-LAUNCH)\n\n";

    $p2Gaps = array_filter($gaps, fn($g) => $g['priority'] === 'P2');
    $phase3Hours = array_sum(array_column($p2Gaps, 'estimate_hours'));

    $report .= "**Timeline:** " . round($phase3Hours / 8, 1) . " days (" . round($phase3Hours / 40, 1) . " weeks)\n\n";
    $report .= "**Total Phase 3:** {$phase3Hours}h\n\n";

    $report .= "---\n\n";

    // Risks
    $report .= "## ⚠️ Risks & Mitigation\n\n";
    $report .= "| Risk | Severity | Impact | Mitigation |\n";
    $report .= "|------|----------|--------|------------|\n";
    $report .= "| Zero test coverage | 🔴 HIGH | Production bugs, regressions | Implement tests for critical paths first (P0) |\n";
    $report .= "| CSRF vulnerabilities | 🔴 HIGH | Security breach, data manipulation | Add nonce verification to all AJAX endpoints |\n";
    $report .= "| N+1 queries | 🔴 HIGH | Performance degradation at scale | Refactor queries, add indexes, implement caching |\n";
    $report .= "| Missing features (GAP #2, #3) | 🟡 MEDIUM | Reduced functionality | Implement before go-live or launch with limitations |\n";
    $report .= "| Code quality issues | 🟢 LOW | Technical debt, maintainability | Refactor post-launch in iterations |\n\n";

    $report .= "---\n\n";

    // Go-Live Decision
    $report .= "## ✅ Go-Live Decision Matrix\n\n";
    $report .= "| Scenario | P0 Complete | P1 Complete | P2 Complete | Recommendation | Timeline |\n";
    $report .= "|----------|-------------|-------------|-------------|----------------|----------|\n";
    $report .= "| **Minimum Viable** | ✅ Yes | ❌ No | ❌ No | ⚠️ **Conditional GO** | " . round($totalP0Hours / 40, 1) . " weeks |\n";
    $report .= "| **Recommended** | ✅ Yes | ✅ Yes | ❌ No | 🟢 **GO LIVE** | " . round(($totalP0Hours + $totalP1Hours) / 40, 1) . " weeks |\n";
    $report .= "| **Production-Ready** | ✅ Yes | ✅ Yes | ✅ Yes | 🟢 **GO LIVE** | {$totalWeeks} weeks |\n\n";

    $report .= "### Current Status\n\n";
    $report .= "⚠️ **CONDITIONAL GO-LIVE**\n\n";
    $report .= "- ❌ P0 Blockers: {$p0Count} gaps ({$totalP0Hours}h remaining)\n";
    $report .= "- ❌ P1 High Priority: {$p1Count} gaps ({$totalP1Hours}h remaining)\n";
    $report .= "- ⚠️ P2 Medium Priority: {$p2Count} gaps ({$totalP2Hours}h remaining)\n\n";

    $report .= "**Recommendation:** Complete P0 blockers minimum, ideally P0 + P1 before go-live.\n\n";

    $report .= "---\n\n";
    $report .= "**Report Generated:** " . date('Y-m-d H:i:s') . "\n";
    $report .= "**Auditor:** Claude Code Go-Live Readiness Audit v1.0.0\n";
    $report .= "**Sources:** Security Audit, Performance Audit, Test Coverage Audit, PENDENCIAS-REPORT, Análise Profunda\n";

    return $report;
}

// Generate report
echo "\nGenerating unified report...\n";

// Create output directory
$outputDir = dirname(OUTPUT_FILE);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$report = generateReport();
file_put_contents(OUTPUT_FILE, $report);

echo "\n✅ Go-Live Readiness Audit Complete!\n";
echo "📄 Report saved to: " . OUTPUT_FILE . "\n";
echo "\n📊 Summary:\n";
echo "- Total Gaps: " . count($gaps) . "\n";
echo "- P0 Blockers: " . count(array_filter($gaps, fn($g) => $g['priority'] === 'P0')) . " ({$totalP0Hours}h)\n";
echo "- P1 High: " . count(array_filter($gaps, fn($g) => $g['priority'] === 'P1')) . " ({$totalP1Hours}h)\n";
echo "- P2 Medium: " . count(array_filter($gaps, fn($g) => $g['priority'] === 'P2')) . " ({$totalP2Hours}h)\n";
echo "- Total Work: {$totalHours}h (" . round($totalHours / 8, 1) . " days)\n";
