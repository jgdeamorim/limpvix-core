<?php
/**
 * Teste das Estatísticas da Aba Geral
 */

// Carregar WordPress
require_once(__DIR__ . '/../../../wp-load.php');

if (!defined('ABSPATH')) {
    die('WordPress não carregado');
}

echo "===========================================\n";
echo "TESTE DE ESTATÍSTICAS DA ABA GERAL\n";
echo "===========================================\n\n";

// Simular o que o método calculateDashboardStats faz
echo "1. FLUXOS OPERACIONAIS\n";
echo "-------------------------------------------\n";

$operationalFlows = [
    ['name' => 'Briefing → Contract', 'use_case' => 'LimpVix\\Application\\UseCases\\Contract\\CreateContractFromBriefing'],
    ['name' => 'Check-in → IN_PROGRESS', 'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckIn'],
    ['name' => 'Check-out → COMPLETED', 'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckOut'],
    ['name' => 'Evidence Upload', 'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\AddEvidence'],
    ['name' => 'Evidence Validation', 'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\ApproveEvidence'],
    ['name' => 'Feedback Window', 'use_case' => 'LimpVix\\Application\\UseCases\\Feedback\\CheckFeedbackWindowStatus'],
    ['name' => 'Submit Feedback', 'use_case' => 'LimpVix\\Application\\UseCases\\Feedback\\SubmitFeedback'],
    ['name' => 'Payout Creation', 'use_case' => 'LimpVix\\Application\\UseCases\\Financial\\ExecutePayout'],
    ['name' => 'Issue Reporting', 'entity' => 'LimpVix\\Domain\\Execution\\Issue'],
    ['name' => 'Validation Workflow', 'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\ValidateExecution'],
];

$operationalComplete = 0;
foreach ($operationalFlows as $flow) {
    $exists = false;

    if (isset($flow['use_case'])) {
        $exists = class_exists($flow['use_case']);
    } elseif (isset($flow['entity'])) {
        $exists = class_exists($flow['entity']);
    }

    $status = $exists ? '✅' : '❌';
    echo "  {$status} {$flow['name']}\n";

    if ($exists) {
        $operationalComplete++;
    }
}

$operationalTotal = count($operationalFlows);
$operationalPercentage = round(($operationalComplete / $operationalTotal) * 100);

echo "\nTOTAL: {$operationalComplete}/{$operationalTotal} ({$operationalPercentage}%)\n\n";

// GAPs
echo "2. GAPS IMPLEMENTADOS\n";
echo "-------------------------------------------\n";

$gaps = [
    ['id' => 'GAP #1', 'name' => 'EPI Selfie Validation', 'class' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence'],
    ['id' => 'GAP #2', 'name' => 'Evidence Categorization', 'class' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence'],
    ['id' => 'GAP #3', 'name' => 'Client Check-in Notifications', 'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckIn'],
    ['id' => 'GAP #4', 'name' => 'Issue Reporting', 'class' => 'LimpVix\\Domain\\Execution\\Issue'],
];

$gapsImplemented = 0;
foreach ($gaps as $gap) {
    $implemented = false;

    if (isset($gap['class'])) {
        $implemented = class_exists($gap['class']);
    } elseif (isset($gap['use_case'])) {
        $implemented = class_exists($gap['use_case']);
    }

    $status = $implemented ? '✅' : '❌';
    echo "  {$status} {$gap['id']}: {$gap['name']}\n";

    if ($implemented) {
        $gapsImplemented++;
    }
}

$gapsTotal = count($gaps);
echo "\nTOTAL: {$gapsImplemented}/{$gapsTotal}\n\n";

// Contar testes
echo "3. TESTES UNITÁRIOS\n";
echo "-------------------------------------------\n";

$testsPath = __DIR__ . '/tests';
$testCount = 0;

if (is_dir($testsPath)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testsPath, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
            $testCount++;
        }
    }

    echo "Diretório de testes encontrado: {$testsPath}\n";
    echo "Total de arquivos de teste: {$testCount}\n";
} else {
    echo "⚠️ Diretório de testes não encontrado: {$testsPath}\n";
    echo "Total de arquivos de teste: 0\n";
}

echo "\n4. VERSÕES DO SISTEMA\n";
echo "-------------------------------------------\n";
echo "PHP: " . phpversion() . "\n";

// PHPUnit version
$composerLock = __DIR__ . '/composer.lock';
if (file_exists($composerLock)) {
    $lock = json_decode(file_get_contents($composerLock), true);
    $phpunitVersion = 'N/A';

    foreach ($lock['packages-dev'] ?? [] as $package) {
        if ($package['name'] === 'phpunit/phpunit') {
            $phpunitVersion = $package['version'];
            break;
        }
    }

    echo "PHPUnit: {$phpunitVersion}\n";
} else {
    echo "PHPUnit: N/A (composer.lock não encontrado)\n";
}

echo "\n5. COMPLETUDE DO SISTEMA\n";
echo "===========================================\n";

$totalItems = $operationalTotal + $gapsTotal;
$completeItems = $operationalComplete + $gapsImplemented;
$completionPercentage = round(($completeItems / $totalItems) * 100);
$isGoLiveReady = $completionPercentage >= 100;

echo "Total de itens: {$totalItems}\n";
echo "Itens completos: {$completeItems}\n";
echo "Completude: {$completionPercentage}%\n";
echo "Go-Live Ready: " . ($isGoLiveReady ? '✅ SIM' : '⚠️ NÃO') . "\n";
echo "Status: " . ($completionPercentage >= 100 ? '🎉 Sistema 100% Operacional' : "⚠️ Sistema {$completionPercentage}% Operacional") . "\n";

echo "\n===========================================\n";
echo "✅ Teste completo! Acesse a aba Geral para ver os valores dinâmicos.\n";
echo "===========================================\n";
