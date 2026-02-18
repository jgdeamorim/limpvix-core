<?php

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Infrastructure\Admin\Queries\ExecutionListQuery;

defined('ABSPATH') || exit;

class ExecutionManagementPage
{
    private const PAGE_SLUG = 'limpvix-executions';
    private const PER_PAGE = 20;

    private ExecutionListQuery $query;

    public function __construct()
    {
        $this->query = new ExecutionListQuery();
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }

        $filters = $this->getFilters();
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $stats = $this->query->getStatistics();
        $executions = $this->query->findAll($filters, $page, self::PER_PAGE);
        $total = $this->query->count($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        // Pre-load user display names
        $userIds = [];
        foreach ($executions as $row) {
            if (!empty($row['client_user_id'])) {
                $userIds[] = (int) $row['client_user_id'];
            }
            if (!empty($row['contract_professional_id'])) {
                $userIds[] = (int) $row['contract_professional_id'];
            }
        }
        $userNames = $this->loadUserNames(array_unique($userIds));

        ?>
        <div class="wrap limpvix-admin">
            <div class="limpvix-page-header">
                <div>
                    <h1>
                        <span class="dashicons dashicons-clipboard"></span>
                        Execucoes
                    </h1>
                    <p class="limpvix-page-subtitle">Acompanhe a execucao dos servicos agendados</p>
                </div>
            </div>

            <?php $this->renderStatCards($stats); ?>
            <?php $this->renderFilters($filters); ?>
            <?php $this->renderTable($executions, $userNames, $page, $totalPages, $total); ?>
        </div>
        <?php
    }

    private function renderStatCards(array $stats): void
    {
        ?>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;">
            <div class="limpvix-card" style="text-align:center;padding:20px;">
                <div style="font-size:32px;font-weight:700;color:#2563eb;">
                    <?php echo esc_html($stats['upcoming']); ?>
                </div>
                <div style="font-size:13px;color:#64748b;margin-top:4px;">Agendadas</div>
                <div style="font-size:11px;color:#94a3b8;"><?php echo esc_html($stats['next_7_days']); ?> nos proximos 7 dias</div>
            </div>
            <div class="limpvix-card" style="text-align:center;padding:20px;">
                <div style="font-size:32px;font-weight:700;color:#f59e0b;">
                    <?php echo esc_html($stats['in_progress']); ?>
                </div>
                <div style="font-size:13px;color:#64748b;margin-top:4px;">Em Andamento</div>
            </div>
            <div class="limpvix-card" style="text-align:center;padding:20px;">
                <div style="font-size:32px;font-weight:700;color:#10b981;">
                    <?php echo esc_html($stats['completed_today']); ?>
                </div>
                <div style="font-size:13px;color:#64748b;margin-top:4px;">Concluidas Hoje</div>
            </div>
            <div class="limpvix-card" style="text-align:center;padding:20px;">
                <div style="font-size:32px;font-weight:700;color:#ef4444;">
                    <?php echo esc_html($stats['no_shows']); ?>
                </div>
                <div style="font-size:13px;color:#64748b;margin-top:4px;">No-Shows (total)</div>
            </div>
        </div>
        <?php
    }

    private function renderFilters(array $filters): void
    {
        $statuses = [
            '' => 'Todos os Status',
            'draft' => 'Rascunho',
            'scheduled' => 'Agendada',
            'in_progress' => 'Em Andamento',
            'completed' => 'Concluida',
            'cancelled' => 'Cancelada',
            'no_show' => 'No-Show',
        ];
        ?>
        <div class="limpvix-card" style="padding:16px;margin-bottom:16px;">
            <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="page" value="limpvix-executions">

                <div>
                    <label style="display:block;font-size:12px;color:#64748b;margin-bottom:4px;">Status</label>
                    <select name="status" style="min-width:160px;">
                        <?php foreach ($statuses as $val => $label): ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($filters['status'] ?? '', $val); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="display:block;font-size:12px;color:#64748b;margin-bottom:4px;">Data Inicio</label>
                    <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from'] ?? ''); ?>">
                </div>

                <div>
                    <label style="display:block;font-size:12px;color:#64748b;margin-bottom:4px;">Data Fim</label>
                    <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to'] ?? ''); ?>">
                </div>

                <div>
                    <button type="submit" class="button">Filtrar</button>
                    <a href="?page=limpvix-executions" class="button">Limpar</a>
                </div>
            </form>
        </div>
        <?php
    }

    private function renderTable(array $executions, array $userNames, int $page, int $totalPages, int $total): void
    {
        ?>
        <div class="limpvix-card">
            <table class="wp-list-table widefat fixed striped" style="border:0;">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>Contrato</th>
                        <th>Profissional</th>
                        <th>Data Agendada</th>
                        <th>Data Execucao</th>
                        <th style="width:130px;">Status</th>
                        <th>Valor</th>
                        <th style="width:100px;">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($executions)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;">
                                Nenhuma execucao encontrada com os filtros atuais.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($executions as $row): ?>
                            <tr>
                                <td><strong>#<?php echo esc_html($row['id']); ?></strong></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=limpvix-contracts&action=view&id=' . $row['contract_id'])); ?>">
                                        Contrato #<?php echo esc_html($row['contract_id']); ?>
                                    </a>
                                    <?php if (!empty($row['service_type'])): ?>
                                        <br><small style="color:#94a3b8;"><?php echo esc_html($row['service_type']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $profId = $row['contract_professional_id'] ?? '';
                                    echo $profId ? esc_html($userNames[(int) $profId] ?? "User #$profId") : '<span style="color:#94a3b8;">-</span>';
                                    ?>
                                </td>
                                <td><?php echo $row['scheduled_date'] ? esc_html(date_i18n('d/m/Y', strtotime($row['scheduled_date']))) : '-'; ?></td>
                                <td><?php echo $row['executed_date'] ? esc_html(date_i18n('d/m/Y', strtotime($row['executed_date']))) : '<span style="color:#94a3b8;">-</span>'; ?></td>
                                <td><?php echo $this->renderStatusBadge($row['status']); ?></td>
                                <td>
                                    <?php if (!empty($row['execution_value'])): ?>
                                        R$ <?php echo esc_html(number_format((float) $row['execution_value'], 2, ',', '.')); ?>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['schedule_uuid'])): ?>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=limpvix-execution-details&uuid=' . $row['schedule_uuid'])); ?>"
                                           class="button button-small" title="Ver Detalhes">
                                            Detalhes
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;font-size:12px;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div style="padding:12px 16px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:13px;color:#64748b;">
                        <?php echo esc_html($total); ?> execucoes encontradas
                    </span>
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'current' => $page,
                            'total' => $totalPages,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                        ]);
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderStatusBadge(string $status): string
    {
        $badges = [
            'draft' => ['bg' => '#f1f5f9', 'color' => '#64748b', 'label' => 'Rascunho'],
            'scheduled' => ['bg' => '#dbeafe', 'color' => '#1d4ed8', 'label' => 'Agendada'],
            'in_progress' => ['bg' => '#fef3c7', 'color' => '#d97706', 'label' => 'Em Andamento'],
            'completed' => ['bg' => '#d1fae5', 'color' => '#059669', 'label' => 'Concluida'],
            'cancelled' => ['bg' => '#fee2e2', 'color' => '#dc2626', 'label' => 'Cancelada'],
            'no_show' => ['bg' => '#fce7f3', 'color' => '#be185d', 'label' => 'No-Show'],
            'pending' => ['bg' => '#f3e8ff', 'color' => '#7c3aed', 'label' => 'Pendente'],
        ];

        $badge = $badges[$status] ?? ['bg' => '#f1f5f9', 'color' => '#64748b', 'label' => ucfirst($status)];

        return sprintf(
            '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;background:%s;color:%s;">%s</span>',
            esc_attr($badge['bg']),
            esc_attr($badge['color']),
            esc_html($badge['label'])
        );
    }

    private function getFilters(): array
    {
        return [
            'status' => sanitize_key($_GET['status'] ?? ''),
            'date_from' => sanitize_text_field($_GET['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_GET['date_to'] ?? ''),
            'contract_id' => (int) ($_GET['contract_id'] ?? 0),
        ];
    }

    private function loadUserNames(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $names = [];
        $users = get_users(['include' => $userIds, 'fields' => ['ID', 'display_name']]);
        foreach ($users as $user) {
            $names[(int) $user->ID] = $user->display_name;
        }

        return $names;
    }
}
