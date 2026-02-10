<?php
/**
 * CronMonitor - Service para monitorar execução de Cron Jobs
 *
 * RESPONSABILIDADE:
 * - Registrar início/fim de execução de cron jobs
 * - Armazenar última execução em wp_options
 * - Verificar saúde dos cron jobs (executaram recentemente?)
 * - Fornecer dados para health check endpoint
 *
 * MOTIVAÇÃO:
 * - Cron jobs podem falhar silenciosamente
 * - Sem monitoramento, contratos expirados não são detectados
 * - Automações críticas precisam de visibilidade
 *
 * ARMAZENAMENTO:
 * Usa wp_options para persistir execuções:
 * - Key: limpvix_cron_last_run_{job_name}
 * - Value: JSON com {started_at, completed_at, status, duration_ms, error}
 *
 * STATUS:
 * - success: Executou sem erros
 * - failure: Executou mas teve erro
 * - timeout: Iniciou mas não completou (timeout assumido)
 *
 * HEALTH CHECK:
 * - healthy: Executou nas últimas N horas (configur��vel)
 * - warning: Não executou mas ainda dentro do grace period
 * - critical: Não executou há muito tempo (> max age)
 *
 * USO:
 * ```php
 * $monitor = new CronMonitor();
 *
 * // Início do job
 * $monitor->recordStart('check_contract_expiration');
 *
 * try {
 *     // ... lógica do cron job
 *     $monitor->recordEnd('check_contract_expiration', 'success');
 * } catch (Exception $e) {
 *     $monitor->recordEnd('check_contract_expiration', 'failure', $e->getMessage());
 * }
 *
 * // Verificar saúde
 * if ($monitor->isHealthy('check_contract_expiration', 25)) {
 *     echo "Cron job saudável!";
 * }
 * ```
 *
 * @package LimpVix\Application\Services
 * @since 0.7.0 (SPRINT 7 - Item 1.9)
 * @author Claude Code
 */

namespace LimpVix\Application\Services;

defined('ABSPATH') || exit;

class CronMonitor
{
    private const OPTION_PREFIX = 'limpvix_cron_last_run_';
    private const STATUS_SUCCESS = 'success';
    private const STATUS_FAILURE = 'failure';
    private const STATUS_TIMEOUT = 'timeout';

    /**
     * Registrar início de execução de cron job
     *
     * @param string $jobName Nome do cron job (ex: check_contract_expiration)
     * @return void
     */
    public function recordStart(string $jobName): void
    {
        $data = [
            'started_at' => current_time('mysql'),
            'started_timestamp' => time(),
            'completed_at' => null,
            'status' => null,
            'duration_ms' => null,
            'error' => null,
        ];

        $this->saveJobData($jobName, $data);

        $this->log("Cron job '{$jobName}' started");
    }

    /**
     * Registrar fim de execução de cron job
     *
     * @param string $jobName Nome do cron job
     * @param string $status Status (success, failure, timeout)
     * @param string|null $error Mensagem de erro (se failure)
     * @return void
     */
    public function recordEnd(string $jobName, string $status, ?string $error = null): void
    {
        $data = $this->getJobData($jobName);

        if (!$data || !isset($data['started_timestamp'])) {
            // Job não foi iniciado via recordStart, criar dados mínimos
            $data = [
                'started_at' => current_time('mysql'),
                'started_timestamp' => time(),
            ];
        }

        $now = time();
        $durationMs = isset($data['started_timestamp'])
            ? ($now - $data['started_timestamp']) * 1000
            : null;

        $data['completed_at'] = current_time('mysql');
        $data['completed_timestamp'] = $now;
        $data['status'] = $status;
        $data['duration_ms'] = $durationMs;
        $data['error'] = $error;

        $this->saveJobData($jobName, $data);

        $statusEmoji = match ($status) {
            self::STATUS_SUCCESS => '✅',
            self::STATUS_FAILURE => '❌',
            self::STATUS_TIMEOUT => '⏱️',
            default => '❓',
        };

        $durationStr = $durationMs ? sprintf('%.2fs', $durationMs / 1000) : 'unknown';
        $this->log("{$statusEmoji} Cron job '{$jobName}' completed: {$status} (duration: {$durationStr})");

        if ($error) {
            $this->log("Error in '{$jobName}': {$error}", 'error');
        }
    }

    /**
     * Obter dados da última execução de um cron job
     *
     * @param string $jobName Nome do cron job
     * @return array|null Dados da execução ou null se nunca executou
     */
    public function getLastRun(string $jobName): ?array
    {
        return $this->getJobData($jobName);
    }

    /**
     * Verificar se cron job está saudável (executou recentemente)
     *
     * @param string $jobName Nome do cron job
     * @param int $maxAgeHours Idade máxima em horas (default: 25 para jobs diários)
     * @return bool True se saudável (executou nas últimas N horas)
     */
    public function isHealthy(string $jobName, int $maxAgeHours = 25): bool
    {
        $data = $this->getJobData($jobName);

        if (!$data || !isset($data['completed_timestamp'])) {
            // Nunca executou ou não completou
            return false;
        }

        $lastRunTimestamp = $data['completed_timestamp'];
        $ageSeconds = time() - $lastRunTimestamp;
        $ageHours = $ageSeconds / 3600;

        return $ageHours <= $maxAgeHours;
    }

    /**
     * Obter status detalhado de um cron job
     *
     * @param string $jobName Nome do cron job
     * @param int $maxAgeHours Idade máxima para considerar healthy
     * @return array Status detalhado com health, age, last_run, etc.
     */
    public function getJobStatus(string $jobName, int $maxAgeHours = 25): array
    {
        $data = $this->getJobData($jobName);

        if (!$data) {
            return [
                'job_name' => $jobName,
                'health' => 'unknown',
                'message' => 'Never executed',
                'last_run' => null,
                'age_hours' => null,
                'status' => null,
                'error' => null,
            ];
        }

        $completedTimestamp = $data['completed_timestamp'] ?? null;
        $status = $data['status'] ?? null;
        $error = $data['error'] ?? null;

        if (!$completedTimestamp) {
            // Iniciou mas não completou (timeout assumido)
            $startedTimestamp = $data['started_timestamp'] ?? null;
            $ageSeconds = $startedTimestamp ? (time() - $startedTimestamp) : null;
            $ageHours = $ageSeconds ? $ageSeconds / 3600 : null;

            return [
                'job_name' => $jobName,
                'health' => 'critical',
                'message' => 'Job started but never completed (possible timeout/crash)',
                'last_run' => $data['started_at'] ?? null,
                'age_hours' => $ageHours,
                'status' => 'timeout',
                'error' => 'Job did not complete',
            ];
        }

        $ageSeconds = time() - $completedTimestamp;
        $ageHours = $ageSeconds / 3600;

        // Determinar health
        if ($ageHours <= $maxAgeHours && $status === self::STATUS_SUCCESS) {
            $health = 'healthy';
            $message = sprintf('Last run %.1f hours ago (success)', $ageHours);
        } elseif ($ageHours <= $maxAgeHours && $status === self::STATUS_FAILURE) {
            $health = 'warning';
            $message = sprintf('Last run %.1f hours ago but failed', $ageHours);
        } elseif ($ageHours > $maxAgeHours) {
            $health = 'critical';
            $message = sprintf('Last run %.1f hours ago (threshold: %d hours)', $ageHours, $maxAgeHours);
        } else {
            $health = 'unknown';
            $message = 'Unable to determine health';
        }

        return [
            'job_name' => $jobName,
            'health' => $health,
            'message' => $message,
            'last_run' => $data['completed_at'],
            'age_hours' => round($ageHours, 1),
            'status' => $status,
            'duration_ms' => $data['duration_ms'] ?? null,
            'error' => $error,
        ];
    }

    /**
     * Obter status de todos os cron jobs registrados
     *
     * @param array $jobNames Lista de nomes de jobs para monitorar
     * @param int $maxAgeHours Idade máxima para healthy
     * @return array Array de status de cada job
     */
    public function getAllJobsStatus(array $jobNames, int $maxAgeHours = 25): array
    {
        $statuses = [];

        foreach ($jobNames as $jobName) {
            $statuses[] = $this->getJobStatus($jobName, $maxAgeHours);
        }

        return $statuses;
    }

    /**
     * Verificar se há jobs com problemas
     *
     * @param array $jobNames Lista de jobs para verificar
     * @param int $maxAgeHours Idade máxima
     * @return bool True se há pelo menos um job critical ou warning
     */
    public function hasUnhealthyJobs(array $jobNames, int $maxAgeHours = 25): bool
    {
        foreach ($jobNames as $jobName) {
            $status = $this->getJobStatus($jobName, $maxAgeHours);
            if ($status['health'] === 'critical' || $status['health'] === 'warning') {
                return true;
            }
        }

        return false;
    }

    /**
     * Limpar dados antigos (manutenção)
     *
     * Remove dados de execução mais antigos que N dias.
     * Útil para evitar acúmulo de dados em wp_options.
     *
     * @param int $daysOld Dias para considerar old (default: 30)
     * @return int Número de jobs limpos
     */
    public function cleanupOldData(int $daysOld = 30): int
    {
        global $wpdb;

        $cutoffTimestamp = time() - ($daysOld * 24 * 3600);
        $cleaned = 0;

        // Buscar todas as options de cron jobs
        $options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like(self::OPTION_PREFIX) . '%'
            )
        );

        foreach ($options as $option) {
            $jobName = str_replace(self::OPTION_PREFIX, '', $option->option_name);
            $data = $this->getJobData($jobName);

            if ($data && isset($data['completed_timestamp'])) {
                if ($data['completed_timestamp'] < $cutoffTimestamp) {
                    delete_option($option->option_name);
                    $cleaned++;
                    $this->log("Cleaned old data for job: {$jobName}");
                }
            }
        }

        return $cleaned;
    }

    /**
     * Salvar dados de execução no banco
     *
     * @param string $jobName Nome do job
     * @param array $data Dados da execução
     * @return void
     */
    private function saveJobData(string $jobName, array $data): void
    {
        $optionName = self::OPTION_PREFIX . $jobName;
        update_option($optionName, $data, false); // autoload = false
    }

    /**
     * Obter dados de execução do banco
     *
     * @param string $jobName Nome do job
     * @return array|null Dados ou null se não existe
     */
    private function getJobData(string $jobName): ?array
    {
        $optionName = self::OPTION_PREFIX . $jobName;
        $data = get_option($optionName, null);

        return is_array($data) ? $data : null;
    }

    /**
     * Log de operações (apenas se WP_DEBUG ativo)
     *
     * @param string $message Mensagem de log
     * @param string $level Nível (info, error)
     * @return void
     */
    private function log(string $message, string $level = 'info'): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $prefix = $level === 'error' ? '❌' : 'ℹ️';
            error_log("[CronMonitor] {$prefix} {$message}");
        }
    }
}
