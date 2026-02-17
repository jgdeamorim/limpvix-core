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
 * - Filtros por status (active|inactive|suspended|banned), verificação, KYC, score mínimo
 * - Busca por nome, CPF, email
 * - Ações em massa (bulk actions)
 * - Coluna KYC com badge semântico
 * - Ação de ban permanente e ativação manual
 *
 * @package LimpVix\Infrastructure\Admin\Tables
 * @since 0.6.0
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

    public function __construct(array $useCases)
    {
        parent::__construct([
            'singular' => 'professional',
            'plural'   => 'professionals',
            'ajax'     => false,
        ]);

        $this->useCases = $useCases;
    }

    // ─── Columns ─────────────────────────────────────────────────────────────

    public function get_columns(): array
    {
        return [
            'cb'          => '<input type="checkbox" />',
            'full_name'   => 'Nome',
            'cpf'         => 'CPF',
            'email'       => 'Email / Telefone',
            'score'       => 'Score',
            'kyc_status'  => 'KYC',
            'is_verified' => 'Verificado',
            'is_active'   => 'Status',
            'actions'     => 'Ações',
        ];
    }

    public function get_sortable_columns(): array
    {
        return [
            'full_name'  => ['full_name', false],
            'score'      => ['score', true],
            'created_at' => ['created_at', true],
        ];
    }

    // ─── prepare_items ────────────────────────────────────────────────────────

    public function prepare_items(): void
    {
        $per_page    = 20;
        $current_page = $this->get_pagenum();
        $offset      = ($current_page - 1) * $per_page;

        $filterStatus   = sanitize_text_field($_GET['filter_status']   ?? 'all');
        $filterVerified = sanitize_text_field($_GET['filter_verified']  ?? 'all');
        $filterKyc      = sanitize_text_field($_GET['filter_kyc']       ?? 'all');
        $filterScore    = (float) ($_GET['filter_score'] ?? 0);
        $search         = sanitize_text_field($_GET['s'] ?? '');

        $orderby = sanitize_text_field($_GET['orderby'] ?? 'created_at');
        $order   = strtoupper(sanitize_text_field($_GET['order'] ?? 'DESC'));

        $filters = [
            'status'       => $filterStatus,
            'verified'     => $filterVerified,
            'filter_kyc'   => $filterKyc,
            'min_score'    => $filterScore,
            'search'       => $search,
            'offset'       => $offset,
            'limit'        => $per_page,
            'orderby'      => $orderby,
            'order'        => $order,
            'return_total' => true,
        ];

        if (isset($this->useCases['list'])) {
            $result       = $this->useCases['list']->execute($filters);
            $this->items  = $result['data']  ?? [];
            $total_items  = $result['total'] ?? 0;
        } else {
            $this->items = [];
            $total_items = 0;
        }

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    // ─── Column renderers ─────────────────────────────────────────────────────

    public function column_default($item, $column_name): string
    {
        switch ($column_name) {

            case 'full_name':
                return '<strong>' . esc_html($item['full_name'] ?? 'N/A') . '</strong>';

            case 'cpf':
                $cpf = $item['cpf'] ?? '';
                if (strlen($cpf) === 11) {
                    return substr($cpf, 0, 3) . '.***.***-' . substr($cpf, -2);
                }
                return esc_html($cpf);

            case 'email':
                return esc_html($item['email'] ?? '') . '<br><small style="color:#6b7280;">' . esc_html($item['phone'] ?? '') . '</small>';

            case 'score':
                $score = (float) ($item['score'] ?? 0);
                $color = $score >= 4.0 ? '#16a34a' : ($score >= 3.0 ? '#d97706' : '#dc2626');
                $bg    = $score >= 4.0 ? '#dcfce7' : ($score >= 3.0 ? '#fef9c3' : '#fee2e2');
                return sprintf(
                    '<span style="background:%s; color:%s; padding:3px 10px; border-radius:20px; font-size:13px; font-weight:700;">%.2f ★</span>',
                    $bg, $color, $score
                );

            case 'kyc_status':
                return $this->renderKycBadge($item['kyc_status'] ?? 'not_started');

            case 'is_verified':
                $verified = (int) ($item['is_verified'] ?? 0);
                return $verified
                    ? '<span style="background:#dcfce7; color:#166534; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600;">✓ Sim</span>'
                    : '<span style="background:#fef9c3; color:#854d0e; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600;">⚠ Não</span>';

            case 'is_active':
                return $this->renderStatusBadge($item);

            case 'actions':
                return $this->column_actions($item);

            default:
                return '';
        }
    }

    public function column_cb($item): string
    {
        return sprintf('<input type="checkbox" name="professional_ids[]" value="%d" />', (int) ($item['id'] ?? 0));
    }

    // ─── Badge helpers ────────────────────────────────────────────────────────

    private function renderStatusBadge(array $item): string
    {
        // Banido permanentemente — prioridade máxima
        if (!empty($item['is_permanently_banned']) && (int) $item['is_permanently_banned'] === 1) {
            $reason = $item['ban_reason'] ? esc_attr($item['ban_reason']) : 'Sem motivo registrado';
            return '<span style="background:#fee2e2; color:#991b1b; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700;" title="' . $reason . '">🚫 Banido</span>';
        }

        // Suspenso temporariamente
        $suspendedUntil = $item['suspended_until'] ?? null;
        if ($suspendedUntil && strtotime($suspendedUntil) > time()) {
            $until = esc_html(date('d/m/Y', strtotime($suspendedUntil)));
            return '<span style="background:#fff3cd; color:#856404; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700;" title="Até ' . $until . '">⛔ Suspenso<br><small style="font-size:10px; font-weight:400;">' . $until . '</small></span>';
        }

        // Ativo
        if ((int) ($item['is_active'] ?? 0) === 1) {
            return '<span style="background:#dcfce7; color:#166534; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700;">✅ Ativo</span>';
        }

        // Inativo
        return '<span style="background:#f3f4f6; color:#374151; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700;">⬛ Inativo</span>';
    }

    private function renderKycBadge(string $status): string
    {
        return match ($status) {
            'approved'    => '<span style="background:#dcfce7; color:#166534; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:600;">✅ Aprovado</span>',
            'pending'     => '<span style="background:#fef9c3; color:#854d0e; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:600;">⏳ Pendente</span>',
            'processing'  => '<span style="background:#dbeafe; color:#1e40af; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:600;">🔄 Processando</span>',
            'rejected'    => '<span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:600;">❌ Rejeitado</span>',
            'expired'     => '<span style="background:#f3f4f6; color:#6b7280; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:600;">⏰ Expirado</span>',
            'not_started' => '<span style="background:#f3f4f6; color:#9ca3af; padding:2px 8px; border-radius:20px; font-size:11px;">— N/I</span>',
            default       => '<span style="color:#9ca3af; font-size:11px;">—</span>',
        };
    }

    // ─── Row actions ──────────────────────────────────────────────────────────

    private function column_actions(array $item): string
    {
        $actions       = [];
        $id            = (int) ($item['id'] ?? 0);
        $isVerified    = (int) ($item['is_verified'] ?? 0);
        $isActive      = (int) ($item['is_active'] ?? 0);
        $isBanned      = (int) ($item['is_permanently_banned'] ?? 0);
        $suspendedUntil = $item['suspended_until'] ?? null;
        $isSuspended   = $suspendedUntil && strtotime($suspendedUntil) > time();
        $page          = 'limpvix-professionals';

        // ── Verificar (se não verificado e não banido) ─────────────────────
        if (!$isVerified && !$isBanned) {
            $actions[] = sprintf(
                '<a href="%s" style="color:#1d4ed8;">✓ Verificar</a>',
                wp_nonce_url("?page={$page}&quick_action=verify&id={$id}", "limpvix_quick_action_verify_{$id}")
            );
        }

        // ── Suspender / Remover suspensão ──────────────────────────────────
        if (!$isBanned) {
            if ($isSuspended) {
                $actions[] = sprintf(
                    '<a href="%s" style="color:#16a34a;">▶ Remover Suspensão</a>',
                    wp_nonce_url("?page={$page}&quick_action=unsuspend&id={$id}", "limpvix_quick_action_unsuspend_{$id}")
                );
            } else {
                $actions[] = sprintf(
                    '<a href="?page=%s&action=suspend&id=%d" style="color:#d97706;">⛔ Suspender</a>',
                    $page, $id
                );
            }
        }

        // ── Banir / Desbanir permanentemente ──────────────────────────────
        if ($isBanned) {
            $actions[] = sprintf(
                '<a href="%s" style="color:#16a34a;">🔓 Desbanir</a>',
                wp_nonce_url("?page={$page}&quick_action=unban&id={$id}", "limpvix_quick_action_unban_{$id}")
            );
        } else {
            $actions[] = sprintf(
                '<a href="%s" style="color:#dc2626;" onclick="return confirm(\'Banir permanentemente este profissional?\')">🚫 Banir</a>',
                wp_nonce_url("?page={$page}&quick_action=ban&id={$id}", "limpvix_quick_action_ban_{$id}")
            );
        }

        // ── Ativar / Desativar ─────────────────────────────────────────────
        if (!$isBanned && !$isSuspended) {
            if ($isActive) {
                $actions[] = sprintf(
                    '<a href="%s" style="color:#6b7280;">⬛ Desativar</a>',
                    wp_nonce_url("?page={$page}&quick_action=deactivate&id={$id}", "limpvix_quick_action_deactivate_{$id}")
                );
            } else {
                $actions[] = sprintf(
                    '<a href="%s" style="color:#16a34a;">✅ Ativar</a>',
                    wp_nonce_url("?page={$page}&quick_action=activate&id={$id}", "limpvix_quick_action_activate_{$id}")
                );
            }
        }

        // ── KYC ───────────────────────────────────────────────────────────
        $actions[] = sprintf(
            '<a href="?page=%s&tab=kyc&kyc_action=view&id=%d">🔐 KYC</a>',
            $page, $id
        );

        // ── Ver Detalhes ───────────────────────────────────────────────────
        $actions[] = sprintf(
            '<a href="?page=%s&action=view&id=%d">👁 Detalhes</a>',
            $page, $id
        );

        return implode('<span style="color:#d1d5db;"> | </span>', $actions);
    }

    // ─── Bulk actions ─────────────────────────────────────────────────────────

    public function get_bulk_actions(): array
    {
        return [
            'bulk_verify'     => '✓ Verificar Selecionados',
            'bulk_suspend'    => '⛔ Suspender Selecionados',
            'bulk_deactivate' => '⬛ Desativar Selecionados',
            'bulk_ban'        => '🚫 Banir Permanentemente',
        ];
    }

    // ─── Extra filters (tablenav) ─────────────────────────────────────────────

    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }

        $filterStatus   = sanitize_text_field($_GET['filter_status']  ?? 'all');
        $filterVerified = sanitize_text_field($_GET['filter_verified'] ?? 'all');
        $filterKyc      = sanitize_text_field($_GET['filter_kyc']      ?? 'all');
        $filterScore    = (float) ($_GET['filter_score'] ?? 0);
        ?>
        <div class="alignleft actions" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; padding:4px 0;">

            <!-- Status operacional -->
            <select name="filter_status" style="min-width:170px;">
                <option value="all"      <?php selected($filterStatus, 'all'); ?>>Todos os Status</option>
                <option value="active"   <?php selected($filterStatus, 'active'); ?>>✅ Ativos</option>
                <option value="inactive" <?php selected($filterStatus, 'inactive'); ?>>⬛ Inativos</option>
                <option value="suspended"<?php selected($filterStatus, 'suspended'); ?>>⛔ Suspensos</option>
                <option value="banned"   <?php selected($filterStatus, 'banned'); ?>>🚫 Banidos Permanentemente</option>
            </select>

            <!-- Verificação -->
            <select name="filter_verified" style="min-width:150px;">
                <option value="all"         <?php selected($filterVerified, 'all'); ?>>Toda Verificação</option>
                <option value="verified"    <?php selected($filterVerified, 'verified'); ?>>✓ Verificados</option>
                <option value="not_verified"<?php selected($filterVerified, 'not_verified'); ?>>⚠ Não Verificados</option>
            </select>

            <!-- KYC -->
            <select name="filter_kyc" style="min-width:160px;">
                <option value="all"        <?php selected($filterKyc, 'all'); ?>>Todo KYC</option>
                <option value="not_started"<?php selected($filterKyc, 'not_started'); ?>>— Não Iniciado</option>
                <option value="pending"    <?php selected($filterKyc, 'pending'); ?>>⏳ Pendente</option>
                <option value="processing" <?php selected($filterKyc, 'processing'); ?>>🔄 Processando</option>
                <option value="approved"   <?php selected($filterKyc, 'approved'); ?>>✅ KYC Aprovado</option>
                <option value="rejected"   <?php selected($filterKyc, 'rejected'); ?>>❌ KYC Rejeitado</option>
                <option value="expired"    <?php selected($filterKyc, 'expired'); ?>>⏰ KYC Expirado</option>
            </select>

            <!-- Score mínimo -->
            <label style="display:flex; align-items:center; gap:5px; font-size:13px; color:#374151;">
                Score ≥
                <input type="number" name="filter_score"
                       value="<?php echo esc_attr($filterScore); ?>"
                       min="0" max="5" step="0.1" style="width:58px;">
            </label>

            <button type="submit" class="button button-primary">Filtrar</button>

            <?php
            $hasFilter = $filterStatus !== 'all' || $filterVerified !== 'all' || $filterKyc !== 'all' || $filterScore > 0 || !empty($_GET['s']);
            if ($hasFilter):
            ?>
                <a href="?page=limpvix-professionals&tab=professionals" class="button">Limpar</a>
            <?php endif; ?>

        </div>
        <?php
    }

    // ─── Search box ───────────────────────────────────────────────────────────

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
                   style="min-width:280px;" />
            <?php submit_button($text, '', '', false, ['id' => 'search-submit']); ?>
        </p>
        <?php
    }

    // ─── Empty state ──────────────────────────────────────────────────────────

    public function no_items(): void
    {
        echo '<span style="color:#6b7280;">Nenhum profissional encontrado com os filtros aplicados.</span> '
           . '<a href="?page=limpvix-professionals&action=create">Cadastrar primeiro profissional</a>';
    }
}
