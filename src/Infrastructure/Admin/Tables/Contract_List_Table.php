<?php
/**
 * Contract_List_Table - WP_List_Table implementation for Contracts
 *
 * Implementa paginação nativa do WordPress para listagem de contratos.
 *
 * RESPONSABILIDADES:
 * - Renderizar tabela de contratos com paginação
 * - Suportar ordenação por colunas
 * - Integrar com ListContracts Use Case
 * - Prover UI consistente com padrão WordPress
 *
 * FEATURES:
 * - Paginação automática (20 itens por página)
 * - Ordenação por ID, valor mensal, data de início
 * - Filtros por status
 * - Ações em massa (bulk actions)
 * - Contagem total de registros
 *
 * @package LimpVix\Infrastructure\Admin\Tables
 * @since 0.6.0
 * @author Claude Code + LimpVix Development Team
 */

namespace LimpVix\Infrastructure\Admin\Tables;

// Load WP_List_Table se ainda não estiver carregado
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

defined('ABSPATH') || exit;

class Contract_List_Table extends \WP_List_Table
{
    private array $useCases;

    /**
     * Constructor
     *
     * @param array $useCases Array of Use Cases from Bootstrap
     */
    public function __construct(array $useCases)
    {
        parent::__construct([
            'singular' => 'contract',
            'plural' => 'contracts',
            'ajax' => false,
        ]);

        $this->useCases = $useCases;
    }

    /**
     * Define colunas da tabela
     *
     * @return array Colunas da tabela
     */
    public function get_columns(): array
    {
        return [
            'cb' => '<input type="checkbox" />',
            'contract_number' => 'Nº Contrato',
            'client' => 'Cliente',
            'contract_type' => 'Tipo',
            'service_code' => 'Serviço',
            'monthly_value' => 'Valor Mensal',
            'start_date' => 'Início',
            'status' => 'Status',
            'actions' => 'Ações',
        ];
    }

    /**
     * Define colunas ordenáveis
     *
     * @return array Colunas ordenáveis [column => [orderby, default_desc]]
     */
    public function get_sortable_columns(): array
    {
        return [
            'contract_number' => ['id', false],
            'monthly_value' => ['monthly_value', false],
            'start_date' => ['start_date', true], // Default DESC (mais recentes primeiro)
        ];
    }

    /**
     * Prepara itens para exibição
     *
     * Busca dados do Use Case com paginação e ordenação.
     *
     * @return void
     */
    public function prepare_items(): void
    {
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        // Get filters from GET params
        $filterStatus = sanitize_text_field($_GET['filter_status'] ?? 'all');

        // Whitelist validation
        $validStatuses = ['all', 'active', 'suspended', 'cancelled', 'expired', 'completed', 'pending_allocation'];
        if (!in_array($filterStatus, $validStatuses, true)) {
            $filterStatus = 'all';
        }

        // Get orderby/order
        $orderby = sanitize_text_field($_GET['orderby'] ?? 'start_date');
        $order = strtoupper(sanitize_text_field($_GET['order'] ?? 'DESC'));

        $filters = [
            'status' => $filterStatus,
            'offset' => $offset,
            'limit' => $per_page,
            'orderby' => $orderby,
            'order' => $order,
            'return_total' => true, // Force total count
        ];

        // Use ListContracts Use Case
        if (isset($this->useCases['list'])) {
            $result = $this->useCases['list']->execute($filters);
            $this->items = $result['data'] ?? [];
            $total_items = $result['total'] ?? 0;
        } else {
            $this->items = [];
            $total_items = 0;
        }

        // Setup pagination
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    /**
     * Renderiza coluna padrão
     *
     * @param array $item Item da lista
     * @param string $column_name Nome da coluna
     * @return string HTML da coluna
     */
    public function column_default($item, $column_name): string
    {
        switch ($column_name) {
            case 'contract_number':
                return '<strong>' . esc_html($item['contract_number'] ?? 'N/A') . '</strong>';

            case 'client':
                $userId = (int) ($item['client_user_id'] ?? 0);
                $user = $userId > 0 ? get_user_by('id', $userId) : null;
                return esc_html($user ? $user->display_name : 'Usuário #' . $userId);

            case 'contract_type':
                $types = [
                    'monthly' => 'Mensal',
                    'weekly' => 'Semanal',
                    'biweekly' => 'Quinzenal',
                ];
                $type = $item['contract_type'] ?? 'monthly';
                return esc_html($types[$type] ?? ucfirst($type));

            case 'service_code':
                return esc_html($item['service_code'] ?? 'N/A');

            case 'monthly_value':
                $value = (float) ($item['monthly_value'] ?? 0);
                return 'R$ ' . number_format($value, 2, ',', '.');

            case 'start_date':
                $date = $item['start_date'] ?? null;
                return $date ? date('d/m/Y', strtotime($date)) : '—';

            case 'status':
                $status = $item['status'] ?? 'unknown';
                $labels = [
                    'active' => 'Ativo',
                    'suspended' => 'Suspenso',
                    'cancelled' => 'Cancelado',
                    'expired' => 'Expirado',
                    'completed' => 'Completo',
                    'pending_allocation' => 'Pendente',
                ];
                $label = $labels[$status] ?? ucfirst($status);
                return sprintf(
                    '<span class="status-badge status-%s">%s</span>',
                    esc_attr($status),
                    esc_html($label)
                );

            case 'actions':
                return $this->column_actions($item);

            default:
                return '';
        }
    }

    /**
     * Renderiza coluna de checkbox
     *
     * @param array $item Item da lista
     * @return string HTML do checkbox
     */
    public function column_cb($item): string
    {
        return sprintf(
            '<input type="checkbox" name="contract_ids[]" value="%d" />',
            (int) ($item['id'] ?? 0)
        );
    }

    /**
     * Renderiza coluna de ações
     *
     * @param array $item Item da lista
     * @return string HTML das ações
     */
    private function column_actions(array $item): string
    {
        $actions = [];
        $id = (int) ($item['id'] ?? 0);
        $status = $item['status'] ?? '';
        $pageSlug = 'limpvix-contracts';

        // Editar (sempre disponível)
        $actions[] = sprintf(
            '<a href="?page=%s&action=edit&id=%d" class="button button-small">Editar</a>',
            $pageSlug,
            $id
        );

        // Ações específicas por status
        if ($status === 'active') {
            $actions[] = sprintf(
                '<a href="?page=%s&action=pause&id=%d" class="button button-small">Pausar</a>',
                $pageSlug,
                $id
            );
        } elseif ($status === 'suspended') {
            $actions[] = sprintf(
                '<a href="?page=%s&action=resume&id=%d" class="button button-small button-primary">Retomar</a>',
                $pageSlug,
                $id
            );
        } elseif ($status === 'cancelled') {
            $actions[] = sprintf(
                '<a href="?page=%s&action=reactivate&id=%d" class="button button-small button-primary">Reativar</a>',
                $pageSlug,
                $id
            );
        }

        // Ver detalhes (sempre disponível)
        $actions[] = sprintf(
            '<a href="?page=%s&action=view&id=%d">Ver Detalhes</a>',
            $pageSlug,
            $id
        );

        return implode(' | ', $actions);
    }

    /**
     * Define bulk actions (ações em massa)
     *
     * @return array Bulk actions disponíveis
     */
    public function get_bulk_actions(): array
    {
        return [
            'bulk_pause' => 'Pausar Selecionados',
            'bulk_cancel' => 'Cancelar Selecionados',
        ];
    }

    /**
     * Mensagem quando não há itens
     *
     * @return void
     */
    public function no_items(): void
    {
        echo 'Nenhum contrato encontrado. <a href="?page=limpvix-contracts&action=create">Criar primeiro contrato</a>';
    }

    /**
     * Renderiza filtros extras acima da tabela
     *
     * @param string $which Posição (top ou bottom)
     * @return void
     */
    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }

        $filterStatus = sanitize_text_field($_GET['filter_status'] ?? 'all');
        ?>
        <div class="alignleft actions">
            <select name="filter_status" id="filter-status">
                <option value="all" <?php selected($filterStatus, 'all'); ?>>Todos os status</option>
                <option value="active" <?php selected($filterStatus, 'active'); ?>>Ativos</option>
                <option value="suspended" <?php selected($filterStatus, 'suspended'); ?>>Suspensos</option>
                <option value="cancelled" <?php selected($filterStatus, 'cancelled'); ?>>Cancelados</option>
                <option value="expired" <?php selected($filterStatus, 'expired'); ?>>Expirados</option>
                <option value="completed" <?php selected($filterStatus, 'completed'); ?>>Completos</option>
                <option value="pending_allocation" <?php selected($filterStatus, 'pending_allocation'); ?>>Pendentes</option>
            </select>
            <button type="submit" class="button">Filtrar</button>
            <a href="?page=limpvix-contracts" class="button">Limpar</a>
        </div>
        <?php
    }
}
