<?php
/**
 * Test Coverage Audit Script - LimpVix Core Plugin
 *
 * Analisa cobertura de testes:
 * - Testes por módulo
 * - Use Cases sem testes
 * - Aggregates sem testes
 * - Repositories sem testes
 * - Critical paths sem cobertura
 *
 * @version 1.0.0
 * @date 2026-02-11
 */

// Auto-detect if running in Docker container or host
if (file_exists('/var/www/html/wp-content/plugins/limpvix-core')) {
    // Running in Docker container
    define('PLUGIN_PATH', '/var/www/html/wp-content/plugins/limpvix-core');
    define('OUTPUT_FILE', '/tmp/TEST-COVERAGE-AUDIT.md');
} else {
    // Running on host
    define('PLUGIN_PATH', '/media/jeffer/5aab5a95-8290-d3f7-2e4f-8c27cc2d09a9/PROJETOS/LIMPVIX/WP/wp-limpo/wp-content/plugins/limpvix-core');
    define('OUTPUT_FILE', '/media/jeffer/5aab5a95-8290-d3f7-2e4f-8c27cc2d09a9/PROJETOS/LIMPVIX/WP/docs/06-AUDITS/TEST-COVERAGE-AUDIT.md');
}

echo "🧪 Test Coverage Audit - LimpVix Core Plugin\n";
echo "============================================\n\n";

$coverage = [
    'use_cases' => [],
    'aggregates' => [],
    'repositories' => [],
    'controllers' => [],
    'value_objects' => [],
];

$stats = [
    'total_use_cases' => 0,
    'tested_use_cases' => 0,
    'total_aggregates' => 0,
    'tested_aggregates' => 0,
    'total_repositories' => 0,
    'tested_repositories' => 0,
    'total_controllers' => 0,
    'tested_controllers' => 0,
    'total_value_objects' => 0,
    'tested_value_objects' => 0,
];

/**
 * Get all PHP files recursively
 */
function getPhpFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/**
 * Extract class name from file
 */
function extractClassName(string $filePath): ?string
{
    $content = file_get_contents($filePath);
    if (preg_match('/class\s+(\w+)/', $content, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Check if test file exists
 */
function hasTestFile(string $className, string $type): bool
{
    $testPaths = [
        PLUGIN_PATH . '/tests/Unit/' . $type . '/' . $className . 'Test.php',
        PLUGIN_PATH . '/tests/Integration/' . $type . '/' . $className . 'Test.php',
        PLUGIN_PATH . '/tests/' . $className . 'Test.php',
        PLUGIN_PATH . '/tests/Unit/' . $className . 'Test.php',
    ];

    foreach ($testPaths as $path) {
        if (file_exists($path)) {
            return true;
        }
    }

    return false;
}

/**
 * Analyze Use Cases
 */
function analyzeUseCases(): void
{
    global $coverage, $stats;

    $useCasesPath = PLUGIN_PATH . '/src/Application/UseCase';
    $files = getPhpFiles($useCasesPath);

    foreach ($files as $file) {
        $className = extractClassName($file);
        if (!$className) {
            continue;
        }

        $stats['total_use_cases']++;

        $hasTest = hasTestFile($className, 'UseCase');
        if ($hasTest) {
            $stats['tested_use_cases']++;
        }

        // Determine module
        $module = 'Unknown';
        if (preg_match('#/UseCase/(\w+)/#', $file, $matches)) {
            $module = $matches[1];
        }

        $coverage['use_cases'][] = [
            'class' => $className,
            'module' => $module,
            'file' => str_replace(PLUGIN_PATH . '/', '', $file),
            'has_test' => $hasTest,
        ];
    }
}

/**
 * Analyze Aggregates
 */
function analyzeAggregates(): void
{
    global $coverage, $stats;

    $domainsPath = PLUGIN_PATH . '/src/Domain';
    $files = getPhpFiles($domainsPath);

    foreach ($files as $file) {
        // Skip if not an aggregate (check if it's in a domain folder directly)
        if (!preg_match('#/Domain/(\w+)/(\w+)\.php$#', $file, $matches)) {
            continue;
        }

        $module = $matches[1];
        $className = $matches[2];

        // Skip interfaces, exceptions, events, value objects
        if (preg_match('/(Interface|Exception|Event|Factory|Repository)$/', $className)) {
            continue;
        }

        // Skip value object folders
        if (stripos($file, '/ValueObject/') !== false || stripos($file, '/Events/') !== false) {
            continue;
        }

        $stats['total_aggregates']++;

        $hasTest = hasTestFile($className, 'Domain/' . $module);
        if ($hasTest) {
            $stats['tested_aggregates']++;
        }

        $coverage['aggregates'][] = [
            'class' => $className,
            'module' => $module,
            'file' => str_replace(PLUGIN_PATH . '/', '', $file),
            'has_test' => $hasTest,
        ];
    }
}

/**
 * Analyze Repositories
 */
function analyzeRepositories(): void
{
    global $coverage, $stats;

    $repoPath = PLUGIN_PATH . '/src/Infrastructure/Persistence';
    $files = getPhpFiles($repoPath);

    foreach ($files as $file) {
        $className = extractClassName($file);
        if (!$className || !preg_match('/Repository$/', $className)) {
            continue;
        }

        // Skip interfaces
        if (preg_match('/Interface$/', $className)) {
            continue;
        }

        $stats['total_repositories']++;

        $hasTest = hasTestFile($className, 'Infrastructure/Persistence');
        if ($hasTest) {
            $stats['tested_repositories']++;
        }

        $coverage['repositories'][] = [
            'class' => $className,
            'file' => str_replace(PLUGIN_PATH . '/', '', $file),
            'has_test' => $hasTest,
        ];
    }
}

/**
 * Analyze Controllers
 */
function analyzeControllers(): void
{
    global $coverage, $stats;

    $controllerPath = PLUGIN_PATH . '/src/Infrastructure/API';
    $files = getPhpFiles($controllerPath);

    foreach ($files as $file) {
        $className = extractClassName($file);
        if (!$className || !preg_match('/Controller$/', $className)) {
            continue;
        }

        $stats['total_controllers']++;

        $hasTest = hasTestFile($className, 'Infrastructure/API');
        if ($hasTest) {
            $stats['tested_controllers']++;
        }

        $coverage['controllers'][] = [
            'class' => $className,
            'file' => str_replace(PLUGIN_PATH . '/', '', $file),
            'has_test' => $hasTest,
        ];
    }
}

/**
 * Generate report
 */
function generateReport(): string
{
    global $coverage, $stats;

    // Calculate overall coverage
    $totalClasses = $stats['total_use_cases'] + $stats['total_aggregates'] +
                    $stats['total_repositories'] + $stats['total_controllers'];

    $totalTested = $stats['tested_use_cases'] + $stats['tested_aggregates'] +
                   $stats['tested_repositories'] + $stats['tested_controllers'];

    $coveragePercent = $totalClasses > 0 ? round(($totalTested / $totalClasses) * 100, 1) : 0;

    $report = "# Test Coverage Audit Report - LimpVix Core Plugin\n\n";
    $report .= "**Data:** " . date('Y-m-d H:i:s') . "\n";
    $report .= "**Versão:** 1.1.0\n\n";
    $report .= "---\n\n";

    // Executive Summary
    $report .= "## 📊 Executive Summary\n\n";
    $report .= "### Overall Test Coverage\n\n";

    $scoreEmoji = $coveragePercent >= 80 ? '🟢' : ($coveragePercent >= 50 ? '🟡' : '🔴');
    $report .= "**{$scoreEmoji} {$coveragePercent}%**\n\n";

    $report .= "### Statistics by Layer\n\n";
    $report .= "| Layer | Total | Tested | Coverage | Status |\n";
    $report .= "|-------|-------|--------|----------|--------|\n";

    // Use Cases
    $ucCoverage = $stats['total_use_cases'] > 0 ?
        round(($stats['tested_use_cases'] / $stats['total_use_cases']) * 100, 1) : 0;
    $ucEmoji = $ucCoverage >= 80 ? '🟢' : ($ucCoverage >= 50 ? '🟡' : '🔴');
    $report .= "| **Use Cases** | {$stats['total_use_cases']} | {$stats['tested_use_cases']} | {$ucCoverage}% | {$ucEmoji} |\n";

    // Aggregates
    $aggCoverage = $stats['total_aggregates'] > 0 ?
        round(($stats['tested_aggregates'] / $stats['total_aggregates']) * 100, 1) : 0;
    $aggEmoji = $aggCoverage >= 80 ? '🟢' : ($aggCoverage >= 50 ? '🟡' : '🔴');
    $report .= "| **Aggregates** | {$stats['total_aggregates']} | {$stats['tested_aggregates']} | {$aggCoverage}% | {$aggEmoji} |\n";

    // Repositories
    $repoCoverage = $stats['total_repositories'] > 0 ?
        round(($stats['tested_repositories'] / $stats['total_repositories']) * 100, 1) : 0;
    $repoEmoji = $repoCoverage >= 80 ? '🟢' : ($repoCoverage >= 50 ? '🟡' : '🔴');
    $report .= "| **Repositories** | {$stats['total_repositories']} | {$stats['tested_repositories']} | {$repoCoverage}% | {$repoEmoji} |\n";

    // Controllers
    $ctrlCoverage = $stats['total_controllers'] > 0 ?
        round(($stats['tested_controllers'] / $stats['total_controllers']) * 100, 1) : 0;
    $ctrlEmoji = $ctrlCoverage >= 80 ? '🟢' : ($ctrlCoverage >= 50 ? '🟡' : '🔴');
    $report .= "| **Controllers** | {$stats['total_controllers']} | {$stats['tested_controllers']} | {$ctrlCoverage}% | {$ctrlEmoji} |\n";

    $report .= "| **TOTAL** | **{$totalClasses}** | **{$totalTested}** | **{$coveragePercent}%** | **{$scoreEmoji}** |\n\n";

    $report .= "---\n\n";

    // Detailed Findings
    $report .= "## 🔍 Detailed Findings\n\n";

    // Use Cases without tests
    $untestedUseCases = array_filter($coverage['use_cases'], fn($uc) => !$uc['has_test']);
    if (!empty($untestedUseCases)) {
        $report .= "### 🔴 Use Cases Without Tests\n\n";
        $report .= "**Total:** " . count($untestedUseCases) . "\n\n";

        // Group by module
        $byModule = [];
        foreach ($untestedUseCases as $uc) {
            $byModule[$uc['module']][] = $uc;
        }

        foreach ($byModule as $module => $useCases) {
            $report .= "#### Module: {$module}\n\n";
            foreach ($useCases as $uc) {
                $report .= "- `{$uc['class']}` - {$uc['file']}\n";
            }
            $report .= "\n";
        }
    }

    // Aggregates without tests
    $untestedAggregates = array_filter($coverage['aggregates'], fn($agg) => !$agg['has_test']);
    if (!empty($untestedAggregates)) {
        $report .= "### 🔴 Aggregates Without Tests\n\n";
        $report .= "**Total:** " . count($untestedAggregates) . "\n\n";

        foreach ($untestedAggregates as $agg) {
            $report .= "- **{$agg['module']}/{$agg['class']}** - {$agg['file']}\n";
        }
        $report .= "\n";
    }

    // Repositories without tests
    $untestedRepos = array_filter($coverage['repositories'], fn($repo) => !$repo['has_test']);
    if (!empty($untestedRepos)) {
        $report .= "### 🟡 Repositories Without Tests\n\n";
        $report .= "**Total:** " . count($untestedRepos) . "\n\n";

        foreach ($untestedRepos as $repo) {
            $report .= "- `{$repo['class']}` - {$repo['file']}\n";
        }
        $report .= "\n";
    }

    // Controllers without tests
    $untestedControllers = array_filter($coverage['controllers'], fn($ctrl) => !$ctrl['has_test']);
    if (!empty($untestedControllers)) {
        $report .= "### 🟡 Controllers Without Tests\n\n";
        $report .= "**Total:** " . count($untestedControllers) . "\n\n";

        foreach ($untestedControllers as $ctrl) {
            $report .= "- `{$ctrl['class']}` - {$ctrl['file']}\n";
        }
        $report .= "\n";
    }

    // Recommendations
    $report .= "---\n\n";
    $report .= "## 📋 Testing Priorities\n\n";
    $report .= "### 🔴 P0 - Critical (Test Before Go-Live)\n\n";
    $report .= "1. **Core Use Cases:**\n";
    $report .= "   - CreateBriefing, AcceptOffer, CompleteService\n";
    $report .= "   - ProcessPayout, ChargeCustomer\n";
    $report .= "   - Critical business logic paths\n\n";

    $report .= "2. **Core Aggregates:**\n";
    $report .= "   - Contract, Professional, Execution\n";
    $report .= "   - Business rule validation\n";
    $report .= "   - State transitions\n\n";

    $report .= "### 🟡 P1 - High Priority\n\n";
    $report .= "1. **Repositories:** Integration tests for data persistence\n";
    $report .= "2. **Controllers:** API endpoint tests\n";
    $report .= "3. **Value Objects:** Validation logic\n\n";

    $report .= "### 🟢 P2 - Nice to Have\n\n";
    $report .= "1. **Admin Pages:** UI interaction tests\n";
    $report .= "2. **Event Handlers:** Event dispatching tests\n";
    $report .= "3. **Factories:** Object creation tests\n\n";

    $report .= "---\n\n";
    $report .= "## 💡 Testing Strategy Recommendations\n\n";
    $report .= "1. **Unit Tests (70%):**\n";
    $report .= "   - All Use Cases should have unit tests\n";
    $report .= "   - All Aggregates business logic\n";
    $report .= "   - Value Object validations\n\n";

    $report .= "2. **Integration Tests (20%):**\n";
    $report .= "   - Repository database operations\n";
    $report .= "   - API controllers with WordPress\n";
    $report .= "   - External API integrations\n\n";

    $report .= "3. **E2E Tests (10%):**\n";
    $report .= "   - Critical user journeys\n";
    $report .= "   - Payment flows\n";
    $report .= "   - Contract lifecycle\n\n";

    $report .= "---\n\n";
    $report .= "**Report Generated:** " . date('Y-m-d H:i:s') . "\n";
    $report .= "**Auditor:** Claude Code Test Coverage Audit v1.0.0\n";

    return $report;
}

// Main execution
echo "Analyzing test coverage...\n";

echo "Scanning Use Cases...\n";
analyzeUseCases();

echo "Scanning Aggregates...\n";
analyzeAggregates();

echo "Scanning Repositories...\n";
analyzeRepositories();

echo "Scanning Controllers...\n";
analyzeControllers();

echo "\nGenerating report...\n";

// Create output directory
$outputDir = dirname(OUTPUT_FILE);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Generate and save report
$report = generateReport();
file_put_contents(OUTPUT_FILE, $report);

echo "\n✅ Test Coverage Audit Complete!\n";
echo "📄 Report saved to: " . OUTPUT_FILE . "\n";
echo "\n📊 Summary:\n";
echo "- Overall Coverage: " . round((($stats['tested_use_cases'] + $stats['tested_aggregates'] + $stats['tested_repositories'] + $stats['tested_controllers']) /
    max(1, $stats['total_use_cases'] + $stats['total_aggregates'] + $stats['total_repositories'] + $stats['total_controllers'])) * 100, 1) . "%\n";
echo "- Use Cases: {$stats['tested_use_cases']}/{$stats['total_use_cases']}\n";
echo "- Aggregates: {$stats['tested_aggregates']}/{$stats['total_aggregates']}\n";
echo "- Repositories: {$stats['tested_repositories']}/{$stats['total_repositories']}\n";
echo "- Controllers: {$stats['tested_controllers']}/{$stats['total_controllers']}\n";
