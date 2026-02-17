<?php
/**
 * Teste das Estatísticas de Fluxos
 */

// Carregar WordPress
require_once(__DIR__ . '/../../../wp-load.php');

if (!defined('ABSPATH')) {
    die('WordPress não carregado');
}

echo "===========================================\n";
echo "TESTE DE ESTATÍSTICAS DE FLUXOS\n";
echo "===========================================\n\n";

// Simular o que o método calculateFluxosStats faz
$enabledFlows = get_option('limpvix_enabled_flows', [
    'c1' => true,
    'c2' => true,
    'c3' => true,
    'p1' => true,
    'p2' => true,
    'p3' => true,
]);

echo "1. FLUXOS DE COMUNICAÇÃO HABILITADOS\n";
echo "-------------------------------------------\n";
$communicationTotal = 6;
$communicationEnabled = 0;
foreach (['c1', 'c2', 'c3', 'p1', 'p2', 'p3'] as $flowId) {
    $enabled = !empty($enabledFlows[$flowId]);
    echo "  {$flowId}: " . ($enabled ? '✅ Habilitado' : '❌ Desabilitado') . "\n";
    if ($enabled) {
        $communicationEnabled++;
    }
}
echo "\nTOTAL: {$communicationEnabled}/{$communicationTotal}\n\n";

echo "2. FLUXOS OPERACIONAIS\n";
echo "-------------------------------------------\n";
$operationalFlows = [
    [
        'name' => 'Briefing → Contract',
        'use_case' => 'LimpVix\\Application\\UseCases\\Contract\\CreateContractFromBriefing',
    ],
    [
        'name' => 'Check-in → IN_PROGRESS',
        'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckIn',
    ],
    [
        'name' => 'Check-out → COMPLETED',
        'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckOut',
    ],
    [
        'name' => 'Evidence Upload',
        'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\AddEvidence',
    ],
    [
        'name' => 'Evidence Validation',
        'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\ApproveEvidence',
    ],
    [
        'name' => 'Feedback Window',
        'use_case' => 'LimpVix\\Application\\UseCases\\Feedback\\CheckFeedbackWindowStatus',
    ],
    [
        'name' => 'Submit Feedback',
        'use_case' => 'LimpVix\\Application\\UseCases\\Feedback\\SubmitFeedback',
    ],
    [
        'name' => 'Payout Creation',
        'use_case' => 'LimpVix\\Application\\UseCases\\Financial\\ExecutePayout',
    ],
    [
        'name' => 'Issue Reporting',
        'entity' => 'LimpVix\\Domain\\Execution\\Issue',
    ],
    [
        'name' => 'Validation Workflow',
        'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\ValidateExecution',
    ],
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
$operationalPercentage = $operationalTotal > 0 ? round(($operationalComplete / $operationalTotal) * 100) : 0;

echo "\nTOTAL: {$operationalComplete}/{$operationalTotal} ({$operationalPercentage}%)\n\n";

echo "3. GAPS IMPLEMENTADOS\n";
echo "-------------------------------------------\n";
$gaps = [
    [
        'name' => 'GAP #1 - EPI Selfie Validation',
        'class' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence',
    ],
    [
        'name' => 'GAP #2 - Evidence Categorization',
        'class' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence',
    ],
    [
        'name' => 'GAP #3 - Client Check-in Notifications',
        'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckIn',
    ],
    [
        'name' => 'GAP #4 - Issue Reporting',
        'class' => 'LimpVix\\Domain\\Execution\\Issue',
    ],
];

$gapsImplemented = 0;
foreach ($gaps as $gap) {
    $exists = false;

    if (isset($gap['class'])) {
        $exists = class_exists($gap['class']);
    } elseif (isset($gap['use_case'])) {
        $exists = class_exists($gap['use_case']);
    }

    $status = $exists ? '✅' : '❌';
    echo "  {$status} {$gap['name']}\n";

    if ($exists) {
        $gapsImplemented++;
    }
}

$gapsTotal = count($gaps);
echo "\nTOTAL: {$gapsImplemented}/{$gapsTotal}\n\n";

echo "4. VERIFICAÇÃO DO MÉTODO canBeValidated()\n";
echo "-------------------------------------------\n";
$executionClass = 'LimpVix\\Domain\\Execution\\Execution';
if (class_exists($executionClass)) {
    echo "✅ Classe Execution existe\n";

    if (method_exists($executionClass, 'canBeValidated')) {
        echo "✅ Método canBeValidated() existe\n";

        // Verificar se o método é público
        $reflection = new \ReflectionClass($executionClass);
        $method = $reflection->getMethod('canBeValidated');
        echo "  - Visibilidade: " . ($method->isPublic() ? 'public' : 'não public') . "\n";
        echo "  - Retorna: bool\n";
    } else {
        echo "❌ Método canBeValidated() NÃO existe\n";
    }
} else {
    echo "❌ Classe Execution NÃO existe\n";
}

echo "\n5. RESUMO PARA ABA FLUXOS\n";
echo "===========================================\n";
echo "Quick Stats que devem aparecer:\n";
echo "  - Fluxos Operacionais: {$operationalComplete}/{$operationalTotal}\n";
echo "  - Fluxos de Comunicação: {$communicationEnabled}/{$communicationTotal}\n";
echo "  - GAPs Implementados: {$gapsImplemented}/{$gapsTotal}\n";
echo "\nStatus Operacional:\n";
echo "  - Completos: {$operationalComplete}\n";
echo "  - Pendentes: " . ($operationalTotal - $operationalComplete) . "\n";
echo "  - Completude: {$operationalPercentage}%\n";

echo "\n===========================================\n";
