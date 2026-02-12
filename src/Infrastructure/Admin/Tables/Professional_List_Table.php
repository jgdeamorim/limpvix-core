<?php
/**
 * Professional_List_Table - WP_List_Table implementation for Professionals
 *
 * Implementa paginação nativa do WordPress para listagem de profissionais.
 *
 * RESPONSABILIDADES:
 * - Renderizar tabela de profissionais com paginação
 * - Suportar ordenação por colunas
 * - Integrar com ListProfessionals Use Case
 * - Prover UI consistente com padrão WordPress
 *
 * FEATURES:
 * - Paginação automática (20 itens por página)
 * - Ordenação por ID, score, nome
 * - Filtros por status, verificação, score mínimo
 * - Busca por nome, CPF, email
 * - Ações em massa (bulk actions)
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

class Professional_List_Table extends \WP_List_Table
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
            'singular' => 'professional',
            'plural' => 'professionals',
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
            'full_name' => 'Nome',
            'cpf' => 'CPF',
            'email' => 'Email',
            'phone' => 'Telefone',
            'score' => 'Score',
            'is_verified' => 'Verificado',
            'is_active' => 'Status',
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
            'full_name' => ['full_name', false],
            'score' => ['score', true], // Default DESC (maiores scores primeiro)
            'created_at' => ['created_at', true],
        ];
    }

    /**
     * Prepara itens para exibição
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
        $filterVerified = sanitize_text_field($_GET['filter_verified'] ?? 'all');
        $filterScore = (float) ($_GET['filter_score'] ?? 0);
        $search = sanitize_text_field($_GET['s'] ?? '');

        // Get orderby/order
        $orderby = sanitize_text_field($_GET['orderby'] ?? 'created_at');
        $order = strtoupper(sanitize_text_field($_GET['order'] ?? 'DESC'));

        $filters = [
            'status' => $filterStatus,
            'verified' => $filterVerified,
            'min_score' => $filterScore,
            'search' => $search,
            'offset' => $offset,
            'limit' => $per_page,
            'orderby' => $orderby,
            'order' => $order,
            'return_total' => true,
        ];

        // Use ListProfessionals Use Case
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
            case 'full_name':
                return '<strong>' . esc_html($item['full_name'] ?? 'N/A') . '</strong>';

            case 'cpf':
                $cpf = $item['cpf'] ?? '';
                // Mask CPF: 123.***.***-45
                if (strlen($cpf) === 11) {
                    return substr($cpf, 0, 3) . '.***.***-' . substr($cpf, -2);
                }
                return esc_html($cpf);

            case 'email':
                return esc_html($item['email'] ?? 'N/A');

            case 'phone':
                return esc_html($item['phone'] ?? 'N/A');

            case 'score':
                $score = (float) ($item['score'] ?? 0);
                $color = $score >= 4.0 ? '#00a32a' : ($score >= 3.0 ? '#f0b849' : '#d63638');
                return sprintf(
                    '<span style="color: %s; font-weight: bold;">%.2f</span>',
                    $color,
                    $score
                );

            case 'is_verified':
                $verified = (int) ($item['is_verified'] ?? 0);
                if ($verified) {
                    return '<span style="color: #00a32a;">✓ Sim</span>';
                } else {
                    return '<span style="color: #f0b849;">⚠ Não</span>';
                }

            case 'is_active':
                $isActive = (int) ($item['is_active'] ?? 0);
                $suspendedUntil = $item['suspended_until'] ?? null;

                // Check if suspended
                if ($suspendedUntil && strtotime($suspendedUntil) > time()) {
                    return '<span style="color: #d63638;">Suspenso</span>';
                }

                if ($isActive) {
                    return '<span style="color: #00a32a;">Ativo</span>';
                } else {
                    return '<span style="color: #999;">Inativo</span>';
                }

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
            '<input type="checkbox" name="professional_ids[]" value="%d" />',
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
        $isVerified = (int) ($item['is_verified'] ?? 0);
        $isActive = (int) ($item['is_active'] ?? 0);
        $suspendedUntil = $item['suspended_until'] ?? null;
        $isSuspended = $suspendedUntil && strtotime($suspendedUntil) > time();
        $pageSlug = 'limpvix-professionals';

        // Verificar (se não verificado)
        if (!$isVerified) {
            $actions[] = sprintf(
                '<a href="?page=%s&action=verify&id=%d" class="button button-small button-primary">Verificar</a>',
                $pageSlug,
                $id
            );
        }

        // Suspender/Remover suspensão
        if ($isSuspended) {
            $actions[] = sprintf(
                '<a href="?page=%s&action=unsuspend&id=%d" class="button button-small button-primary">Remover Suspensão</a>',
                $pageSlug,
                $id
            );
        } else {
            $actions[] = sprintf(
                '<a href="?page=%s&action=suspend&id=%d" class="button button-small">Suspender</a>',
                $pageSlug,
                $id
            );
        }

        // Ver detalhes
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
            'bulk_verify' => 'Verificar Selecionados',
            'bulk_suspend' => 'Suspender Selecionados',
            'bulk_deactivate' => 'Desativar Selecionados',
        ];
    }

    /**
     * Mensagem quando não há itens
     *
     * @return void
     */
    public function no_items(): void
    {
        echo 'Nenhum profissional encontrado. <a href="?page=limpvix-professionals&action=create">Cadastrar primeiro profissional</a>';
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
        $filterVerified = sanitize_text_field($_GET['filter_verified'] ?? 'all');
        $filterScore = (float) ($_GET['filter_score'] ?? 0);
        $search = sanitize_text_field($_GET['s'] ?? '');
        ?>
        <div class="alignleft actions" style="display: flex; gap: 10px; align-items: center;">
            <select name="filter_status">
                <option value="all" <?php selected($filterStatus, 'all'); ?>>Todos os Status</option>
                <option value="active" <?php selected($filterStatus, 'active'); ?>>Ativos</option>
                <option value="inactive" <?php selected($filterStatus, 'inactive'); ?>>Inativos</option>
                <option value="suspended" <?php selected($filterStatus, 'suspended'); ?>>Suspensos</option>
            </select>

            <select name="filter_verified">
                <option value="all" <?php selected($filterVerified, 'all'); ?>>Todos Verificação</option>
                <option value="verified" <?php selected($filterVerified, 'verified'); ?>>Verificados</option>
                <option value="not_verified" <?php selected($filterVerified, 'not_verified'); ?>>Não Verificados</option>
            </select>

            <label style="display: flex; align-items: center; gap: 5px;">
                Score ≥
                <input type="number" name="filter_score" value="<?php echo esc_attr($filterScore); ?>"
                       min="0" max="5" step="0.1" style="width: 60px;">
            </label>

            <button type="submit" class="button">Filtrar</button>
            <a href="?page=limpvix-professionals" class="button">Limpar</a>
        </div>
        <?php
    }

    /**
     * Renderiza search box
     *
     * @param string $text Texto do botão
     * @param string $input_id ID do input
     * @return void
     */
    public function search_box($text = 'Buscar', $input_id = 'search'): void
    {
        $search = sanitize_text_field($_GET['s'] ?? '');
        ?>
        <p class="search-box">
            <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>">
                <?php echo esc_html($text); ?>:
            </label>
            <input type="search" id="<?php echo esc_attr($input_id); ?>" name="s"
                   value="<?php echo esc_attr($search); ?>"
                   placeholder="Buscar por nome, CPF ou email..."
                   style="min-width: 300px;" />
            <?php submit_button($text, '', '', false, ['id' => 'search-submit']); ?>
        </p>
        <?php
    }
}
