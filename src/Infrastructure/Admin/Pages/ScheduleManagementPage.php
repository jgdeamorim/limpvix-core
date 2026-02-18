<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Infrastructure\Persistence\WpScheduleRepository;

/**
 * Admin Page: Schedule Management
 *
 * Lista e gerencia schedules do sistema:
 * - Filtros por status, data, profissional, SLA violations
 * - Visualização detalhada de cada schedule
 * - Informações de alocação e check-in/out
 */
final class ScheduleManagementPage
{
    private WpScheduleRepository $scheduleRepository;

    public function __construct()
    {
        $this->scheduleRepository = new WpScheduleRepository();
    }

    public static function init(): void
    {
        $instance = new self();
        add_action('admin_menu', [$instance, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$instance, 'enqueueAssets']);
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'limpvix-core',
            'Agendamentos',
            'Agendamentos',
            'manage_options',
            'limpvix-schedules',
            [$this, 'render']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'limpvix_page_limpvix-schedules') {
            return;
        }

        wp_enqueue_style(
            'limpvix-schedules',
            plugins_url('assets/css/admin-schedules.css', LIMPVIX_PLUGIN_FILE),
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'limpvix-schedules',
            plugins_url('assets/js/admin-schedules.js', LIMPVIX_PLUGIN_FILE),
            ['jquery'],
            '1.0.0',
            true
        );
    }

    public function render(): void
    {
        $filters = $this->getFilters();
        $schedules = $this->scheduleRepository->findAll($filters);
        $stats = $this->getStats();

        ?>
        <div class="wrap">
            <h1>Agendamentos LimpVix</h1>

            <!-- Stats Cards -->
            <div class="limpvix-stats-cards">
                <div class="stat-card">
                    <div class="stat-label">Total</div>
                    <div class="stat-value"><?php echo esc_html($stats['total']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Alocados</div>
                    <div class="stat-value"><?php echo esc_html($stats['allocated']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Em Progresso</div>
                    <div class="stat-value"><?php echo esc_html($stats['in_progress']); ?></div>
                </div>
                <div class="stat-card stat-danger">
                    <div class="stat-label">Violações SLA</div>
                    <div class="stat-value"><?php echo esc_html($stats['sla_violations']); ?></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="limpvix-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="limpvix-schedules">

                    <select name="status">
                        <option value="">Todos os Status</option>
                        <option value="draft" <?php selected($filters['status'], 'draft'); ?>>Rascunho</option>
                        <option value="allocated" <?php selected($filters['status'], 'allocated'); ?>>Alocado</option>
                        <option value="in_progress" <?php selected($filters['status'], 'in_progress'); ?>>Em Progresso</option>
                        <option value="completed" <?php selected($filters['status'], 'completed'); ?>>Concluído</option>
                        <option value="cancelled" <?php selected($filters['status'], 'cancelled'); ?>>Cancelado</option>
                    </select>

                    <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" placeholder="Data de">
                    <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" placeholder="Data até">

                    <label>
                        <input type="checkbox" name="sla_only" value="1" <?php checked($filters['sla_only'], '1'); ?>>
                        Apenas Violações SLA
                    </label>

                    <button type="submit" class="button">Filtrar</button>
                    <a href="?page=limpvix-schedules" class="button">Limpar</a>
                </form>
            </div>

            <!-- Schedules Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>UUID</th>
                        <th>Pedido</th>
                        <th>Data/Hora</th>
                        <th>Profissionais</th>
                        <th>Status</th>
                        <th>SLA</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr>
                            <td colspan="7">Nenhum agendamento encontrado.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($schedules as $schedule): ?>
                            <tr>
                                <td>
                                    <code><?php echo esc_html(substr($schedule['uuid'], 0, 8)); ?></code>
                                </td>
                                <td>
                                    <a href="?page=limpvix-orders&order=<?php echo esc_attr($schedule['order_uuid']); ?>">
                                        <?php echo esc_html(substr($schedule['order_uuid'], 0, 8)); ?>
                                    </a>
                                </td>
                                <td>
                                    <strong><?php echo esc_html(date('d/m/Y H:i', strtotime($schedule['requested_time']))); ?></strong><br>
                                    <small>Janela: <?php echo esc_html(date('H:i', strtotime($schedule['window_start']))); ?> - <?php echo esc_html(date('H:i', strtotime($schedule['window_end']))); ?></small>
                                </td>
                                <td>
                                    <?php
                                    $professionals = $this->getAllocatedProfessionals($schedule['uuid']);
                                    if (empty($professionals)):
                                        ?>
                                        <em>Nenhum alocado</em>
                                    <?php else: ?>
                                        <?php foreach ($professionals as $prof): ?>
                                            <div>
                                                <?php echo esc_html($prof['name']); ?>
                                                <?php if ($prof['score']): ?>
                                                    <span class="score-badge"><?php echo esc_html(number_format($prof['score'], 1)); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $this->renderStatusBadge($schedule['status']); ?>
                                </td>
                                <td>
                                    <?php if ($schedule['sla_violation']): ?>
                                        <span class="badge badge-danger">
                                            <?php
                                            $violation = json_decode($schedule['sla_violation'], true);
                                            echo esc_html($violation['type'] ?? 'Violação');
                                            ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-success">OK</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="?page=limpvix-schedules&action=view&uuid=<?php echo esc_attr($schedule['uuid']); ?>" class="button button-small">
                                        Ver Detalhes
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function getFilters(): array
    {
        return [
            'status' => $_GET['status'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'sla_only' => $_GET['sla_only'] ?? '',
        ];
    }

    private function getStats(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_schedules';

        return [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'allocated' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'allocated'"),
            'in_progress' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'in_progress'"),
            'sla_violations' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE sla_violation IS NOT NULL"),
        ];
    }

    private function getAllocatedProfessionals(string $scheduleUuid): array
    {
        global $wpdb;
        $allocationsTable = $wpdb->prefix . 'limpvix_professional_allocations';
        $profsTable       = $wpdb->prefix . 'limpvix_professionals';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.full_name AS name, a.allocation_score AS score
                FROM {$allocationsTable} a
                INNER JOIN {$profsTable} p ON a.professional_id = p.id
                WHERE a.schedule_uuid = %s
                AND a.status = 'allocated'",
                $scheduleUuid
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    private function renderStatusBadge(string $status): string
    {
        $badges = [
            'draft' => '<span class="badge badge-secondary">Rascunho</span>',
            'allocated' => '<span class="badge badge-info">Alocado</span>',
            'in_progress' => '<span class="badge badge-warning">Em Progresso</span>',
            'completed' => '<span class="badge badge-success">Concluído</span>',
            'cancelled' => '<span class="badge badge-danger">Cancelado</span>',
        ];

        return $badges[$status] ?? '<span class="badge">Desconhecido</span>';
    }
}
