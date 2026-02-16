<?php
/**
 * Script de Diagnóstico de Cron Jobs
 *
 * Analisa por que os cron jobs não estão executando automaticamente
 *
 * Uso: wp-content/plugins/limpvix-core/diagnose_cron_jobs.php
 */

// Carregar WordPress
require_once(__DIR__ . '/../../../wp-load.php');

if (!defined('ABSPATH')) {
    die('WordPress não carregado');
}

echo "===========================================\n";
echo "DIAGNÓSTICO DE CRON JOBS - LIMPVIX\n";
echo "===========================================\n\n";

// 1. Verificar DISABLE_WP_CRON
echo "1. CONFIGURAÇÃO WP-CRON\n";
echo "-------------------------------------------\n";
if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
    echo "✅ DISABLE_WP_CRON: TRUE\n";
    echo "   WP-Cron desabilitado. Deve usar cron do servidor.\n";
    echo "   Verificar se crontab está configurado:\n";
    echo "   */5 * * * * curl http://localhost:8080/wp-cron.php?doing_wp_cron > /dev/null 2>&1\n";
} else {
    echo "⚠️  DISABLE_WP_CRON: FALSE ou não definido\n";
    echo "   WP-Cron executado via visitas ao site (pode ter atrasos).\n";
}
echo "\n";

// 2. Listar todos os cron jobs LimpVix agendados
echo "2. CRON JOBS LIMPVIX AGENDADOS\n";
echo "-------------------------------------------\n";
$cron_jobs = _get_cron_array();
$limpvix_crons = [];

foreach ($cron_jobs as $timestamp => $cron) {
    foreach ($cron as $hook => $details) {
        if (strpos($hook, 'limpvix_') === 0) {
            $limpvix_crons[$hook] = [
                'hook' => $hook,
                'next_run' => $timestamp,
                'schedule' => $details[array_key_first($details)]['schedule'] ?? 'single',
                'args' => $details[array_key_first($details)]['args'] ?? [],
            ];
        }
    }
}

if (empty($limpvix_crons)) {
    echo "❌ NENHUM cron job LimpVix encontrado agendado!\n";
    echo "   PROBLEMA: Os cron jobs não foram registrados.\n\n";
} else {
    echo "✅ " . count($limpvix_crons) . " cron jobs LimpVix encontrados:\n\n";
    foreach ($limpvix_crons as $hook => $info) {
        $next_run_date = date('Y-m-d H:i:s', $info['next_run']);
        $time_until = human_time_diff($info['next_run'], time());
        $is_overdue = $info['next_run'] < time();

        $status_icon = $is_overdue ? '⏱️ ' : '✓';
        echo "  {$status_icon} {$hook}\n";
        echo "     Próxima execução: {$next_run_date}\n";
        echo "     Status: " . ($is_overdue ? "ATRASADO ({$time_until} atrás)" : "Agendado (em {$time_until})") . "\n";
        echo "     Frequência: {$info['schedule']}\n\n";
    }
}

// 3. Verificar se os HOOKS estão registrados
echo "3. HOOKS REGISTRADOS\n";
echo "-------------------------------------------\n";
global $wp_filter;

$expected_hooks = [
    'limpvix_check_contract_expiration',
    'limpvix_process_review_timer',
    'limpvix_send_feedback_reminders',
    'limpvix_process_payout_batch',
    'limpvix_sync_payouts',
    'limpvix_retry_failed_payouts',
    'limpvix_contracts_daily_check',
    'limpvix_contracts_weekly_briefing',
    'limpvix_fallback_send_offers',
    'limpvix_clean_message_queue',
    'limpvix_mp_periodic_sync',
];

foreach ($expected_hooks as $hook) {
    if (isset($wp_filter[$hook]) && !empty($wp_filter[$hook]->callbacks)) {
        echo "  ✅ {$hook} - " . count($wp_filter[$hook]->callbacks) . " callback(s) registrado(s)\n";

        // Mostrar callbacks
        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $callback_name = is_array($callback['function'])
                    ? (is_string($callback['function'][0]) ? $callback['function'][0] : get_class($callback['function'][0])) . '::' . $callback['function'][1]
                    : (is_string($callback['function']) ? $callback['function'] : 'Closure');
                echo "     - Priority {$priority}: {$callback_name}\n";
            }
        }
    } else {
        echo "  ❌ {$hook} - NÃO registrado\n";
        echo "     PROBLEMA: Hook não tem callback. Cron não vai executar nada.\n";
    }
}
echo "\n";

// 4. Verificar schedules customizados
echo "4. SCHEDULES CUSTOMIZADOS\n";
echo "-------------------------------------------\n";
$schedules = wp_get_schedules();
$limpvix_schedules = array_filter($schedules, function($key) {
    return strpos($key, 'limpvix_') === 0;
}, ARRAY_FILTER_USE_KEY);

if (empty($limpvix_schedules)) {
    echo "⚠️  Nenhum schedule customizado LimpVix encontrado.\n";
} else {
    echo "✅ " . count($limpvix_schedules) . " schedule(s) customizado(s):\n";
    foreach ($limpvix_schedules as $key => $schedule) {
        echo "  - {$key}: {$schedule['interval']}s ({$schedule['display']})\n";
    }
}
echo "\n";

// 5. Verificar último status dos cron jobs (via CronMonitor)
echo "5. ÚLTIMO STATUS DOS CRON JOBS (via CronMonitor)\n";
echo "-------------------------------------------\n";
$monitored_jobs = [
    'check_contract_expiration',
    'process_review_timer',
    'send_feedback_reminders',
    'process_payout_batch',
    'sync_payouts',
    'retry_failed_payouts',
    'contracts_daily_check',
    'contracts_weekly_briefing',
    'fallback_send_offers',
    'clean_message_queue',
    'mp_periodic_sync',
];

foreach ($monitored_jobs as $job) {
    $option_name = "limpvix_cron_last_run_{$job}";
    $data = get_option($option_name);

    if ($data && is_array($data)) {
        $status_icon = match($data['status'] ?? 'unknown') {
            'success' => '✅',
            'failure' => '❌',
            'timeout' => '⏱️',
            default => '❓',
        };

        $completed_at = $data['completed_at'] ?? 'Nunca';
        $age_hours = isset($data['completed_timestamp'])
            ? round((time() - $data['completed_timestamp']) / 3600, 1)
            : 'N/A';

        echo "  {$status_icon} {$job}\n";
        echo "     Última execução: {$completed_at}\n";
        echo "     Idade: {$age_hours} horas\n";
        echo "     Status: " . ($data['status'] ?? 'unknown') . "\n";

        if (!empty($data['error'])) {
            echo "     Erro: {$data['error']}\n";
        }

        if (isset($data['duration_ms'])) {
            echo "     Duração: " . round($data['duration_ms'] / 1000, 2) . "s\n";
        }
        echo "\n";
    } else {
        echo "  ❓ {$job} - Nunca executou (sem dados no CronMonitor)\n\n";
    }
}

// 6. DIAGNÓSTICO FINAL
echo "6. DIAGNÓSTICO E RECOMENDAÇÕES\n";
echo "===========================================\n";

$issues = [];
$recommendations = [];

if (empty($limpvix_crons)) {
    $issues[] = "CRÍTICO: Nenhum cron job está agendado no WordPress";
    $recommendations[] = "Verificar se ContractBootstrap::registerCronJobs() está sendo chamado";
    $recommendations[] = "Verificar se CommunicationBootstrap::boot() está sendo chamado";
    $recommendations[] = "Tentar desativar e reativar o plugin para re-agendar os crons";
}

// Verificar se há hooks sem agendamento
foreach ($expected_hooks as $hook) {
    if (isset($wp_filter[$hook]) && !isset($limpvix_crons[$hook])) {
        $issues[] = "Hook '{$hook}' está registrado mas NÃO está agendado";
        $recommendations[] = "Executar wp_schedule_event() para '{$hook}'";
    }
}

// Verificar crons atrasados
foreach ($limpvix_crons as $hook => $info) {
    if ($info['next_run'] < time()) {
        $hours_late = round((time() - $info['next_run']) / 3600, 1);
        $issues[] = "'{$hook}' está atrasado ({$hours_late} horas)";
    }
}

// Verificar DISABLE_WP_CRON sem crontab
if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
    $recommendations[] = "IMPORTANTE: Verificar se crontab está configurado para chamar wp-cron.php";
    $recommendations[] = "Comando para crontab: */5 * * * * curl http://localhost:8080/wp-cron.php?doing_wp_cron > /dev/null 2>&1";
}

if (empty($issues)) {
    echo "✅ SISTEMA SAUDÁVEL - Nenhum problema detectado\n\n";
} else {
    echo "❌ PROBLEMAS DETECTADOS:\n";
    foreach ($issues as $i => $issue) {
        echo "   " . ($i + 1) . ". {$issue}\n";
    }
    echo "\n";
}

if (!empty($recommendations)) {
    echo "💡 RECOMENDAÇÕES:\n";
    foreach ($recommendations as $i => $rec) {
        echo "   " . ($i + 1) . ". {$rec}\n";
    }
    echo "\n";
}

// 7. Comando para forçar execução
echo "7. COMANDOS ÚTEIS\n";
echo "===========================================\n";
echo "Forçar execução de um cron específico:\n";
echo "  do_action('limpvix_check_contract_expiration');\n\n";
echo "Reagendar todos os crons (desativar/ativar plugin):\n";
echo "  wp plugin deactivate limpvix-core && wp plugin activate limpvix-core\n\n";
echo "Executar wp-cron manualmente:\n";
echo "  curl http://localhost:8080/wp-cron.php?doing_wp_cron\n\n";

echo "===========================================\n";
echo "DIAGNÓSTICO COMPLETO\n";
echo "===========================================\n";
