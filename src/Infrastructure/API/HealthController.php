<?php
/**
 * HealthController - REST API Controller para Health Checks
 *
 * RESPONSABILIDADE:
 * - Fornecer endpoint público de health check
 * - Retornar status de cron jobs
 * - Permitir monitoring externo (UptimeRobot, etc.)
 *
 * ENDPOINTS:
 * - GET /wp-json/limpvix/v1/health/cron - Status dos cron jobs
 * - GET /wp-json/limpvix/v1/health/system - Status geral do sistema (future)
 *
 * MOTIVAÇÃO (SPRINT 7 - Item 1.9):
 * - Monitoring externo precisa de endpoint HTTP
 * - UptimeRobot/Pingdom podem chamar e alertar se down
 * - Visibilidade de cron jobs sem precisar acessar admin
 *
 * SEGURANÇA:
 * - Endpoint público (sem autenticação) por design
 * - Não expõe dados sensíveis
 * - Apenas status agregado
 * - Rate limiting via WordPress (future)
 *
 * USO EXTERNO:
 * ```bash
 * curl https://limpvix.com.br/wp-json/limpvix/v1/health/cron
 * ```
 *
 * RESPOSTA:
 * ```json
 * {
 *   "status": "healthy",
 *   "timestamp": "2026-02-10 12:34:56",
 *   "jobs": [
 *     {
 *       "job_name": "check_contract_expiration",
 *       "health": "healthy",
 *       "last_run": "2026-02-10 00:00:12",
 *       "age_hours": 12.5,
 *       "status": "success",
 *       "duration_ms": 1234
 *     }
 *   ]
 * }
 * ```
 *
 * @package LimpVix\Infrastructure\API
 * @since 0.7.0 (SPRINT 7 - Item 1.9)
 * @author Claude Code
 */

namespace LimpVix\Infrastructure\API;

use LimpVix\Application\Services\CronMonitor;

defined('ABSPATH') || exit;

class HealthController
{
    private CronMonitor $cronMonitor;

    /**
     * Lista de cron jobs a monitorar
     *
     * @var array<string, int> Job name => Max age in hours
     */
    private const MONITORED_JOBS = [
        'check_contract_expiration' => 25, // Daily job (25h grace period)
        // Future jobs:
        // 'send_execution_reminders' => 2,  // Hourly job
        // 'process_payment_queue' => 1,     // Hourly job
    ];

    /**
     * Construtor
     */
    public function __construct()
    {
        $this->cronMonitor = new CronMonitor();
    }

    /**
     * Registrar rotas REST API
     *
     * @return void
     */
    public function registerRoutes(): void
    {
        // GET /wp-json/limpvix/v1/health/cron
        register_rest_route('limpvix/v1', '/health/cron', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'getCronHealth'],
            'permission_callback' => '__return_true', // Public endpoint
        ]);

        // GET /wp-json/limpvix/v1/health (alias para backward compatibility)
        register_rest_route('limpvix/v1', '/health', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'getCronHealth'],
            'permission_callback' => '__return_true', // Public endpoint
        ]);
    }

    /**
     * GET /wp-json/limpvix/v1/health/cron
     *
     * Retorna status de saúde dos cron jobs
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function getCronHealth(\WP_REST_Request $request): \WP_REST_Response
    {
        $jobs = [];
        $overallHealthy = true;

        foreach (self::MONITORED_JOBS as $jobName => $maxAgeHours) {
            $jobStatus = $this->cronMonitor->getJobStatus($jobName, $maxAgeHours);
            $jobs[] = $jobStatus;

            // Se qualquer job não está healthy, overall = false
            if ($jobStatus['health'] !== 'healthy') {
                $overallHealthy = false;
            }
        }

        $overallStatus = $overallHealthy ? 'healthy' : 'degraded';

        $response = [
            'status' => $overallStatus,
            'timestamp' => current_time('mysql'),
            'jobs' => $jobs,
            'metadata' => [
                'plugin_version' => defined('LIMPVIX_VERSION') ? LIMPVIX_VERSION : 'unknown',
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
            ],
        ];

        // HTTP status code baseado em overall health
        $httpStatus = $overallHealthy ? 200 : 503; // 503 Service Unavailable se unhealthy

        return new \WP_REST_Response($response, $httpStatus);
    }

    /**
     * Adicionar job para monitoring
     *
     * Permite adicionar novos jobs dinamicamente via filter
     *
     * @param string $jobName Nome do job
     * @param int $maxAgeHours Idade máxima em horas
     * @return void
     */
    public static function addMonitoredJob(string $jobName, int $maxAgeHours): void
    {
        // Esta função pode ser chamada por outros módulos para registrar seus cron jobs
        // Implementação futura: usar um filter ou global array
    }
}
