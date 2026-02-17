<?php
/**
 * Debug da Renderização da Aba Fluxos
 */

// Carregar WordPress
require_once(__DIR__ . '/../../../wp-load.php');

if (!defined('ABSPATH')) {
    die('WordPress não carregado');
}

echo "===========================================\n";
echo "DEBUG DA RENDERIZAÇÃO DA ABA FLUXOS\n";
echo "===========================================\n\n";

// Simular o que acontece no renderFluxosTab()
$enabledFlows = get_option('limpvix_enabled_flows', [
    'c1' => true,
    'c2' => true,
    'c3' => true,
    'p1' => true,
    'p2' => true,
    'p3' => true,
]);

echo "1. ENABLED FLOWS (get_option)\n";
echo "-------------------------------------------\n";
var_dump($enabledFlows);
echo "\n";

// Simular calculateFluxosStats
echo "2. SIMULAÇÃO DO calculateFluxosStats()\n";
echo "-------------------------------------------\n";

// Comunicação
$communicationTotal = 6;
$communicationEnabled = 0;
foreach (['c1', 'c2', 'c3', 'p1', 'p2', 'p3'] as $flowId) {
    if (!empty($enabledFlows[$flowId])) {
        $communicationEnabled++;
    }
}

echo "communication_enabled: {$communicationEnabled}\n";
echo "communication_total: {$communicationTotal}\n\n";

// Operacionais
$operationalFlows = [
    ['use_case' => 'LimpVix\\Application\\UseCases\\Contract\\CreateContractFromBriefing'],
    ['use_case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckIn'],
    ['use_case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckOut'],
    ['use_case' => 'LimpVix\\Application\\UseCases\\Execution\\AddEvidence'],
    ['use_case' => 'LimpVix\\Application\\UseCases\\Execution\\ApproveEvidence'],
    ['use_case' => 'LimpVix\\Application\\UseCases\\Feedback\\CheckFeedbackWindowStatus'],
    ['use_case' => 'LimpVix\\Application\\UseCases\\Feedback\\SubmitFeedback'],
    ['use_case' => 'LimpVix\\Application\\UseCases\\Financial\\ExecutePayout'],
    ['entity' => 'LimpVix\\Domain\\Execution\\Issue'],
    ['use_case' => 'LimpVix\\Application\\UseCases\\Execution\\ValidateExecution'],
];

$operationalComplete = 0;
foreach ($operationalFlows as $flow) {
    if (isset($flow['use_case'])) {
        if (class_exists($flow['use_case'])) {
            $operationalComplete++;
        }
    } elseif (isset($flow['entity'])) {
        if (class_exists($flow['entity'])) {
            $operationalComplete++;
        }
    }
}

$operationalTotal = count($operationalFlows);
$operationalPercentage = round(($operationalComplete / $operationalTotal) * 100);

echo "operational_complete: {$operationalComplete}\n";
echo "operational_total: {$operationalTotal}\n";
echo "operational_percentage: {$operationalPercentage}\n\n";

// GAPs
$gaps = [
    ['class' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence'],
    ['class' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence'],
    ['use_case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckIn'],
    ['class' => 'LimpVix\\Domain\\Execution\\Issue'],
];

$gapsImplemented = 0;
foreach ($gaps as $gap) {
    if (isset($gap['class'])) {
        if (class_exists($gap['class'])) {
            $gapsImplemented++;
        }
    } elseif (isset($gap['use_case'])) {
        if (class_exists($gap['use_case'])) {
            $gapsImplemented++;
        }
    }
}

$gapsTotal = count($gaps);

echo "gaps_implemented: {$gapsImplemented}\n";
echo "gaps_total: {$gapsTotal}\n\n";

echo "3. ARRAY \$stats QUE SERÁ PASSADO PARA O TEMPLATE\n";
echo "-------------------------------------------\n";
$stats = [
    'communication_enabled' => $communicationEnabled,
    'communication_total' => $communicationTotal,
    'operational_complete' => $operationalComplete,
    'operational_total' => $operationalTotal,
    'operational_percentage' => $operationalPercentage,
    'gaps_implemented' => $gapsImplemented,
    'gaps_total' => $gapsTotal,
];

print_r($stats);

echo "\n4. HTML QUE DEVE SER RENDERIZADO\n";
echo "===========================================\n";
echo "Quick Stats:\n";
echo "  - {$stats['operational_complete']}/{$stats['operational_total']} Fluxos Operacionais Completos\n";
echo "  - {$stats['communication_enabled']}/{$stats['communication_total']} Fluxos de Comunicação Habilitados\n";
echo "  - {$stats['gaps_implemented']}/{$stats['gaps_total']} GAPs Implementados\n\n";

echo "Status Operacional:\n";
echo "  - {$stats['operational_complete']} COMPLETOS\n";
echo "  - " . ($stats['operational_total'] - $stats['operational_complete']) . " PENDENTES\n";
echo "  - {$stats['operational_percentage']}% COMPLETUDE\n\n";

echo "===========================================\n";
echo "✅ Se você ver esses valores na página, está correto!\n";
echo "===========================================\n";
