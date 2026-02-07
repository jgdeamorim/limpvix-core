<?php
/**
 * BriefingManagementPage - Página Administrativa de Gerenciamento de Briefings
 *
 * RESPONSABILIDADE:
 * - Submenu: LimpVix → Briefings
 * - Listar todos os briefings com paginação
 * - Filtros: status, property_type, data criação, usuário
 * - Ações: visualizar detalhes, exportar CSV
 * - Estatísticas rápidas (totais por status)
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Domain\Briefing\BriefingRepositoryInterface;
use LimpVix\Domain\Briefing\BriefingStatus;

defined('ABSPATH') || exit;

class BriefingManagementPage
{
    private $briefingRepository;
    private const PAGE_SLUG = 'limpvix-briefings';

    public function __construct(BriefingRepositoryInterface $briefingRepository)
    {
        $this->briefingRepository = $briefingRepository;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            'limpvix',
            'Briefings',
            'Briefings',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        $this->handleActions();
        $filters = $this->getFilters();
        $briefings = $this->getBriefings($filters);
        $stats = $this->getStatistics();
        ?>
        <div class="wrap">
            <h1>Briefings <a href="<?php echo esc_url(home_url('/briefing')); ?>" class="page-title-action">+ Novo</a></h1>

            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin:20px 0">
                <?php foreach ($stats as $stat): ?>
                    <div style="background:#fff;border-left:4px solid #2271b1;padding:15px">
                        <div style="font-size:32px;font-weight:600"><?php echo esc_html($stat['count']); ?></div>
                        <div style="font-size:13px;color:#646970"><?php echo esc_html($stat['label']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="background:#fff;border:1px solid #ccd0d4;padding:15px;margin:20px 0">
                <form method="get" style="display:flex;gap:10px;flex-wrap:wrap">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                    <select name="filter_status">
                        <option value="">Todos os Status</option>
                        <?php foreach ($this->getStatusOptions() as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['status'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="filter_property_type">
                        <option value="">Todos os Tipos</option>
                        <option value="residential" <?php selected($filters['property_type'], 'residential'); ?>>Residencial</option>
                        <option value="commercial" <?php selected($filters['property_type'], 'commercial'); ?>>Comercial</option>
                    </select>
                    <button type="submit" class="button">Filtrar</button>
                    <button type="submit" name="action" value="export_csv" class="button">Exportar CSV</button>
                </form>
            </div>

            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>UUID</th>
                        <th>Usuário</th>
                        <th>Tipo</th>
                        <th>Status</th>
                        <th>M²</th>
                        <th>Criado</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($briefings)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:40px">Nenhum briefing encontrado.</td></tr>
                    <?php else: ?>
                        <?php foreach ($briefings as $briefing): ?>
                            <tr>
                                <td><code><?php echo esc_html(substr($briefing->getUuid(), 0, 8)); ?>...</code></td>
                                <td><?php $user = get_userdata($briefing->getUserId()); echo $user ? esc_html($user->display_name) : 'ID: ' . $briefing->getUserId(); ?></td>
                                <td><?php echo esc_html($briefing->getPropertyType()->getValue()); ?></td>
                                <td><?php echo esc_html($briefing->getStatus()->getValue()); ?></td>
                                <td><?php echo $briefing->getMetrics() ? esc_html(number_format($briefing->getMetrics()->getM2(), 2)) : '—'; ?></td>
                                <td><?php echo esc_html($briefing->getCreatedAt()->format('d/m/Y H:i')); ?></td>
                                <td><a href="<?php echo esc_url(admin_url('admin.php?page=limpvix-briefing-detail&uuid=' . urlencode($briefing->getUuid()))); ?>" class="button button-small">Ver</a></td>
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
            'status' => isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '',
            'property_type' => isset($_GET['filter_property_type']) ? sanitize_text_field($_GET['filter_property_type']) : ''
        ];
    }

    private function getBriefings(array $filters): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_briefings';
        $sql = "SELECT * FROM {$table} WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND status = %s";
            $params[] = $filters['status'];
        }

        if (!empty($filters['property_type'])) {
            $sql .= " AND property_type = %s";
            $params[] = $filters['property_type'];
        }

        $sql .= " ORDER BY created_at DESC LIMIT 100";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);

        $briefings = [];
        foreach ($rows as $row) {
            $briefing = $this->briefingRepository->findByUuid($row['uuid']);
            if ($briefing) {
                $briefings[] = $briefing;
            }
        }

        return $briefings;
    }

    private function getStatistics(): array
    {
        return [
            ['label' => 'Rascunho', 'count' => $this->briefingRepository->count(BriefingStatus::draft())],
            ['label' => 'Em Andamento', 'count' => $this->briefingRepository->count(BriefingStatus::inProgress())],
            ['label' => 'Pagos', 'count' => $this->briefingRepository->count(BriefingStatus::paid())],
            ['label' => 'Finalizados', 'count' => $this->briefingRepository->count(BriefingStatus::locked())]
        ];
    }

    private function getStatusOptions(): array
    {
        return [
            'draft' => 'Rascunho',
            'in_progress' => 'Em Andamento',
            'pending_phone_verification' => 'Aguardando Verificação',
            'awaiting_payment' => 'Aguardando Pagamento',
            'paid' => 'Pago',
            'locked' => 'Finalizado'
        ];
    }

    private function handleActions(): void
    {
        if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
            $this->exportCsv();
        }
    }

    private function exportCsv(): void
    {
        $briefings = $this->getBriefings($this->getFilters());

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=briefings_' . date('Y-m-d_H-i-s') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['UUID', 'User ID', 'Tipo', 'Status', 'M²', 'Criado']);

        foreach ($briefings as $briefing) {
            fputcsv($output, [
                $briefing->getUuid(),
                $briefing->getUserId(),
                $briefing->getPropertyType()->getValue(),
                $briefing->getStatus()->getValue(),
                $briefing->getMetrics() ? $briefing->getMetrics()->getM2() : '',
                $briefing->getCreatedAt()->format('Y-m-d H:i:s')
            ]);
        }

        fclose($output);
        exit;
    }
}
