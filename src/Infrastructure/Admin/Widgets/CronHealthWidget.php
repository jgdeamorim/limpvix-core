<?php
/**
 * CronHealthWidget - Widget do Dashboard Admin para status de Cron Jobs
 *
 * RESPONSABILIDADE:
 * - Exibir status visual de cron jobs no dashboard do admin
 * - Indicadores coloridos (verde/amarelo/vermelho)
 * - Última execução e tempo decorrido
 * - Link para health check endpoint
 *
 * LOCALIZAÇÃO:
 * - WordPress Admin Dashboard (wp-admin/index.php)
 * - Visível apenas para administradores
 *
 * MOTIVAÇÃO (SPRINT 7 - Item 1.9):
 * - Admins precisam ver status de cron jobs rapidamente
 * - Troubleshooting mais fácil
 * - Alertas visuais se algo está errado
 *
 * @package LimpVix\Infrastructure\Admin\Widgets
 * @since 0.7.0 (SPRINT 7 - Item 1.9)
 * @author Claude Code
 */

namespace LimpVix\Infrastructure\Admin\Widgets;

use LimpVix\Application\Services\CronMonitor;

defined('ABSPATH') || exit;

class CronHealthWidget
{
    private CronMonitor $cronMonitor;

    /**
     * Jobs a monitorar
     */
    private const MONITORED_JOBS = [
        'check_contract_expiration' => 25,
    ];

    /**
     * Construtor
     */
    public function __construct()
    {
        $this->cronMonitor = new CronMonitor();
    }

    /**
     * Registrar widget no dashboard
     *
     * @return void
     */
    public function register(): void
    {
        add_action('wp_dashboard_setup', [$this, 'addDashboardWidget']);
    }

    /**
     * Adicionar widget ao dashboard
     *
     * @return void
     */
    public function addDashboardWidget(): void
    {
        wp_add_dashboard_widget(
            'limpvix_cron_health',
            '🔧 LimpVix - Cron Jobs Status',
            [$this, 'renderWidget']
        );
    }

    /**
     * Renderizar conteúdo do widget
     *
     * @return void
     */
    public function renderWidget(): void
    {
        $jobs = [];
        $hasUnhealthy = false;

        foreach (self::MONITORED_JOBS as $jobName => $maxAgeHours) {
            $status = $this->cronMonitor->getJobStatus($jobName, $maxAgeHours);
            $jobs[] = $status;

            if ($status['health'] !== 'healthy') {
                $hasUnhealthy = true;
            }
        }

        // Health check endpoint URL
        $healthEndpoint = rest_url('limpvix/v1/health/cron');

        ?>
        <style>
            .limpvix-cron-widget {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            }
            .limpvix-cron-job {
                padding: 12px;
                margin-bottom: 8px;
                border-left: 4px solid #ddd;
                background: #f9f9f9;
                border-radius: 2px;
            }
            .limpvix-cron-job.healthy {
                border-left-color: #46b450;
                background: #f0f9f0;
            }
            .limpvix-cron-job.warning {
                border-left-color: #ffb900;
                background: #fffbf0;
            }
            .limpvix-cron-job.critical {
                border-left-color: #dc3232;
                background: #fef0f0;
            }
            .limpvix-cron-job-name {
                font-weight: 600;
                font-size: 14px;
                margin-bottom: 4px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .limpvix-cron-status-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 500;
                text-transform: uppercase;
            }
            .limpvix-cron-status-badge.healthy {
                background: #46b450;
                color: white;
            }
            .limpvix-cron-status-badge.warning {
                background: #ffb900;
                color: #333;
            }
            .limpvix-cron-status-badge.critical {
                background: #dc3232;
                color: white;
            }
            .limpvix-cron-status-badge.unknown {
                background: #8c8f94;
                color: white;
            }
            .limpvix-cron-meta {
                font-size: 12px;
                color: #646970;
                margin-top: 4px;
            }
            .limpvix-cron-meta strong {
                color: #1d2327;
            }
            .limpvix-cron-footer {
                margin-top: 12px;
                padding-top: 12px;
                border-top: 1px solid #ddd;
                font-size: 12px;
            }
            .limpvix-cron-footer a {
                text-decoration: none;
            }
        </style>

        <div class="limpvix-cron-widget">
            <?php if (empty($jobs)): ?>
                <p style="color: #8c8f94;">
                    Nenhum cron job monitorado.
                </p>
            <?php else: ?>
                <?php foreach ($jobs as $job): ?>
                    <div class="limpvix-cron-job <?php echo esc_attr($job['health']); ?>">
                        <div class="limpvix-cron-job-name">
                            <?php echo esc_html($this->formatJobName($job['job_name'])); ?>
                            <span class="limpvix-cron-status-badge <?php echo esc_attr($job['health']); ?>">
                                <?php echo esc_html($job['health']); ?>
                            </span>
                        </div>
                        <div class="limpvix-cron-meta">
                            <?php if ($job['last_run']): ?>
                                <strong>Última execução:</strong> <?php echo esc_html($job['last_run']); ?>
                                (há <?php echo esc_html($job['age_hours']); ?>h)
                            <?php else: ?>
                                <strong>Status:</strong> Nunca executado
                            <?php endif; ?>
                        </div>
                        <?php if ($job['status']): ?>
                            <div class="limpvix-cron-meta">
                                <strong>Status:</strong> <?php echo esc_html($job['status']); ?>
                                <?php if ($job['duration_ms']): ?>
                                    | <strong>Duração:</strong> <?php echo number_format($job['duration_ms'] / 1000, 2); ?>s
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($job['error']): ?>
                            <div class="limpvix-cron-meta" style="color: #dc3232;">
                                <strong>Erro:</strong> <?php echo esc_html($job['error']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="limpvix-cron-footer">
                <p>
                    <a href="<?php echo esc_url($healthEndpoint); ?>" target="_blank" class="button button-small">
                        📊 Ver Health Check JSON
                    </a>
                </p>
                <p style="margin-top: 8px; color: #646970;">
                    ℹ️ Este endpoint pode ser usado para monitoring externo (UptimeRobot, Pingdom).
                </p>
                <?php if ($hasUnhealthy): ?>
                    <p style="margin-top: 8px; color: #dc3232; font-weight: 600;">
                        ⚠️ Atenção: Há cron jobs com problemas. Verifique os logs do sistema.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Formatar nome do job para exibição
     *
     * @param string $jobName Nome técnico do job
     * @return string Nome formatado para exibição
     */
    private function formatJobName(string $jobName): string
    {
        // Converter snake_case para Title Case
        $name = str_replace('_', ' ', $jobName);
        $name = ucwords($name);

        // Mapeamento de nomes amigáveis
        $friendlyNames = [
            'Check Contract Expiration' => 'Verificar Expiração de Contratos',
            'Send Execution Reminders' => 'Enviar Lembretes de Execução',
            'Process Payment Queue' => 'Processar Fila de Pagamentos',
        ];

        return $friendlyNames[$name] ?? $name;
    }
}
