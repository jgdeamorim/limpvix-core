<?php
/**
 * Diagnóstico de Fluxos Operacionais
 *
 * Verifica o estado real dos fluxos operacionais no sistema
 */

// Carregar WordPress
require_once(__DIR__ . '/../../../wp-load.php');

if (!defined('ABSPATH')) {
    die('WordPress não carregado');
}

echo "===========================================\n";
echo "DIAGNÓSTICO DE FLUXOS OPERACIONAIS\n";
echo "===========================================\n\n";

// Fluxos Operacionais a verificar
$operationalFlows = [
    [
        'name' => 'Briefing → Contract',
        'use_case' => 'LimpVix\\Application\\UseCases\\Contract\\CreateContractFromBriefing',
    ],
    [
        'name' => 'Check-in → IN_PROGRESS',
        'use_cases' => [
            'LimpVix\\Application\\UseCases\\Execution\\CheckIn',
            'LimpVix\\Application\\UseCases\\Execution\\PerformCheckIn',
            'LimpVix\\Application\\UseCases\\Scheduling\\PerformCheckIn',
        ],
    ],
    [
        'name' => 'Check-out → COMPLETED',
        'use_cases' => [
            'LimpVix\\Application\\UseCases\\Execution\\CheckOut',
            'LimpVix\\Application\\UseCases\\Execution\\PerformCheckOut',
            'LimpVix\\Application\\UseCases\\Scheduling\\PerformCheckOut',
        ],
    ],
    [
        'name' => 'Evidence Upload',
        'use_cases' => [
            'LimpVix\\Application\\UseCases\\Execution\\UploadEvidence',
            'LimpVix\\Application\\UseCases\\Execution\\AddEvidence',
        ],
    ],
    [
        'name' => 'Evidence Validation',
        'use_cases' => [
            'LimpVix\\Application\\UseCases\\Execution\\ValidateEvidence',
            'LimpVix\\Application\\UseCases\\Execution\\ApproveEvidence',
            'LimpVix\\Application\\UseCases\\Execution\\ValidateExecution',
        ],
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
        'check' => function() {
            if (!class_exists('LimpVix\\Domain\\Execution\\Execution')) {
                return false;
            }
            return method_exists('LimpVix\\Domain\\Execution\\Execution', 'canBeValidated');
        },
    ],
];

$complete = 0;
$partial = 0;
$missing = 0;

echo "1. FLUXOS OPERACIONAIS\n";
echo "-------------------------------------------\n\n";

foreach ($operationalFlows as $flow) {
    $status = '❌ FALTANDO';
    $details = '';
    $exists = false;

    // Check single use_case
    if (isset($flow['use_case'])) {
        $exists = class_exists($flow['use_case']);
        if ($exists) {
            $status = '✅ COMPLETO';
            $details = "Use case: {$flow['use_case']}";
            $complete++;
        } else {
            $details = "Use case esperado: {$flow['use_case']} - NÃO ENCONTRADO";
            $missing++;
        }
    }
    // Check multiple use_cases (try alternatives)
    elseif (isset($flow['use_cases'])) {
        $foundCases = [];
        foreach ($flow['use_cases'] as $useCase) {
            if (class_exists($useCase)) {
                $foundCases[] = $useCase;
                $exists = true;
            }
        }

        if (!empty($foundCases)) {
            $status = '✅ COMPLETO';
            $details = "Use cases encontrados:\n";
            foreach ($foundCases as $case) {
                $details .= "     - {$case}\n";
            }
            $complete++;
        } else {
            $status = '❌ FALTANDO';
            $details = "Nenhum use case encontrado entre:\n";
            foreach ($flow['use_cases'] as $case) {
                $details .= "     - {$case}\n";
            }
            $missing++;
        }
    }
    // Check entity
    elseif (isset($flow['entity'])) {
        $exists = class_exists($flow['entity']);
        if ($exists) {
            $status = '✅ COMPLETO';
            $details = "Entity: {$flow['entity']}";
            $complete++;
        } else {
            $details = "Entity esperada: {$flow['entity']} - NÃO ENCONTRADA";
            $missing++;
        }
    }
    // Check custom function
    elseif (isset($flow['check'])) {
        $exists = $flow['check']();
        if ($exists) {
            $status = '✅ COMPLETO';
            $details = "Verificação customizada: OK";
            $complete++;
        } else {
            $details = "Verificação customizada: FALHOU";
            $missing++;
        }
    }

    echo "{$status} {$flow['name']}\n";
    if (!empty($details)) {
        echo "   {$details}\n";
    }
    echo "\n";
}

echo "\n2. GAPs IMPLEMENTADOS\n";
echo "-------------------------------------------\n\n";

$gaps = [
    [
        'id' => 'GAP #1',
        'name' => 'EPI Selfie Validation',
        'checks' => [
            'Evidence categorization' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence',
            'Category constants' => function() {
                $reflection = new \ReflectionClass('LimpVix\\Domain\\Execution\\ValueObjects\\Evidence');
                return $reflection->hasConstant('CATEGORY_EPI_CHECKIN');
            },
        ],
    ],
    [
        'id' => 'GAP #2',
        'name' => 'Evidence Categorization',
        'checks' => [
            'Evidence value object' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence',
            'Category property' => function() {
                $reflection = new \ReflectionClass('LimpVix\\Domain\\Execution\\ValueObjects\\Evidence');
                return $reflection->hasProperty('category');
            },
        ],
    ],
    [
        'id' => 'GAP #3',
        'name' => 'Client Check-in Notifications',
        'checks' => [
            'PerformCheckIn use case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckIn',
        ],
    ],
    [
        'id' => 'GAP #4',
        'name' => 'Issue Reporting',
        'checks' => [
            'Issue entity' => 'LimpVix\\Domain\\Execution\\Issue',
            'ReportIssue use case' => 'LimpVix\\Application\\UseCases\\Execution\\ReportIssue',
        ],
    ],
];

$gapsImplemented = 0;
$gapsTotal = count($gaps);

foreach ($gaps as $gap) {
    $allChecksPass = true;
    $checkResults = [];

    foreach ($gap['checks'] as $checkName => $check) {
        if (is_string($check)) {
            $result = class_exists($check);
            $checkResults[$checkName] = $result ? '✅' : '❌';
            if (!$result) {
                $allChecksPass = false;
            }
        } elseif (is_callable($check)) {
            $result = $check();
            $checkResults[$checkName] = $result ? '✅' : '❌';
            if (!$result) {
                $allChecksPass = false;
            }
        }
    }

    $status = $allChecksPass ? '✅ IMPLEMENTADO' : '⚠️ PARCIAL';
    if ($allChecksPass) {
        $gapsImplemented++;
    }

    echo "{$status} {$gap['id']} - {$gap['name']}\n";
    foreach ($checkResults as $checkName => $result) {
        echo "   {$result} {$checkName}\n";
    }
    echo "\n";
}

echo "\n3. RESUMO GERAL\n";
echo "===========================================\n";
echo "Fluxos Operacionais:\n";
echo "  ✅ Completos: {$complete}\n";
echo "  ⚠️ Parciais: {$partial}\n";
echo "  ❌ Faltando: {$missing}\n";
echo "  📊 Total: " . count($operationalFlows) . "\n";
echo "  📈 % Completude: " . round(($complete / count($operationalFlows)) * 100) . "%\n\n";

echo "GAPs:\n";
echo "  ✅ Implementados: {$gapsImplemented}\n";
echo "  ❌ Pendentes: " . ($gapsTotal - $gapsImplemented) . "\n";
echo "  📊 Total: {$gapsTotal}\n";
echo "  📈 % Completude: " . round(($gapsImplemented / $gapsTotal) * 100) . "%\n\n";

if ($missing > 0) {
    echo "⚠️ ATENÇÃO: {$missing} fluxo(s) operacional(is) FALTANDO!\n";
    echo "Revisar implementação dos use cases listados acima.\n\n";
} else {
    echo "🎉 TODOS os fluxos operacionais estão implementados!\n\n";
}

if ($gapsImplemented < $gapsTotal) {
    echo "⚠️ ATENÇÃO: " . ($gapsTotal - $gapsImplemented) . " GAP(s) PENDENTE(S)!\n\n";
} else {
    echo "🎉 TODOS os GAPs estão implementados!\n\n";
}

echo "===========================================\n";
