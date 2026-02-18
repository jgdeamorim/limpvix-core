<?php
/**
 * PayoutsPage
 *
 * Página admin para gerenciamento de payouts (repasses financeiros)
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.1.3
 */

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository;
use LimpVix\Infrastructure\Finance\Providers\MercadoPagoPayoutProvider;

class PayoutsPage
{
    private WpPayoutRepository $repository;
    private MercadoPagoPayoutProvider $mpProvider;

    public function __construct()
    {
        $this->repository = new WpPayoutRepository();
        $this->mpProvider = new MercadoPagoPayoutProvider();
    }

    /**
     * Registrar hooks
     */
    public static function register(): void
    {
        $instance = new self();
        add_action('admin_post_limpvix_process_payout', [$instance, 'handleProcessPayout']);
        add_action('admin_post_limpvix_sync_payouts', [$instance, 'handleSyncPayouts']);
    }

    /**
     * Renderizar página
     */
    public function render(): void
    {
        // Verificar permissão
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        // Filtros
        $status_filter = $_GET['status'] ?? 'all';
        $professional_filter = absint($_GET['professional_id'] ?? 0);

        // Buscar payouts
        if ($professional_filter > 0) {
            $payouts = $this->repository->getByProfessional($professional_filter, $status_filter === 'all' ? null : $status_filter);
        } elseif ($status_filter !== 'all') {
            $payouts = $this->repository->getByStatus($status_filter, 100);
        } else {
            // Buscar todos (limitado)
            global $wpdb;
            $payouts = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}limpvix_payouts ORDER BY created_at DESC LIMIT 100",
                ARRAY_A
            );
        }

        // Estatísticas
        $stats = $this->repository->getStats();

        // Verificar MP configurado
        $mp_configured = $this->mpProvider->isAvailable();
        $mp_sandbox = $this->mpProvider->isSandbox();

        ?>
        <div class="wrap">
            <h1>💰 Payouts (Repasses Financeiros)</h1>

            <?php if (!$mp_configured): ?>
                <div class="notice notice-error">
                    <p><strong>⚠️ Mercado Pago não configurado!</strong></p>
                    <p>Configure o Access Token em <a href="<?php echo admin_url('admin.php?page=limpvix-settings'); ?>">Configurações</a>.</p>
                </div>
            <?php elseif ($mp_sandbox): ?>
                <div class="notice notice-warning">
                    <p><strong>🧪 Modo Sandbox Ativo</strong> - Transferências são simuladas (não reais).</p>
                </div>
            <?php endif; ?>

            <!-- Estatísticas -->
            <div class="limpvix-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 20px 0;">
                <div class="limpvix-stat-card" style="background: #fff; border-left: 4px solid #f59e0b; padding: 16px; border-radius: 4px;">
                    <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Pendentes</div>
                    <div style="font-size: 24px; font-weight: bold; color: #1f2937;"><?php echo $stats['total_pending']; ?></div>
                    <div style="color: #6b7280; font-size: 14px;">R$ <?php echo number_format($stats['amount_pending'], 2, ',', '.'); ?></div>
                </div>

                <div class="limpvix-stat-card" style="background: #fff; border-left: 4px solid #3b82f6; padding: 16px; border-radius: 4px;">
                    <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Aprovados</div>
                    <div style="font-size: 24px; font-weight: bold; color: #1f2937;"><?php echo $stats['total_approved']; ?></div>
                </div>

                <div class="limpvix-stat-card" style="background: #fff; border-left: 4px solid #8b5cf6; padding: 16px; border-radius: 4px;">
                    <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Processando</div>
                    <div style="font-size: 24px; font-weight: bold; color: #1f2937;"><?php echo $stats['total_processing']; ?></div>
                </div>

                <div class="limpvix-stat-card" style="background: #fff; border-left: 4px solid #10b981; padding: 16px; border-radius: 4px;">
                    <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Concluídos</div>
                    <div style="font-size: 24px; font-weight: bold; color: #1f2937;"><?php echo $stats['total_completed']; ?></div>
                    <div style="color: #6b7280; font-size: 14px;">R$ <?php echo number_format($stats['amount_completed'], 2, ',', '.'); ?></div>
                </div>

                <div class="limpvix-stat-card" style="background: #fff; border-left: 4px solid #ef4444; padding: 16px; border-radius: 4px;">
                    <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Falhas</div>
                    <div style="font-size: 24px; font-weight: bold; color: #1f2937;"><?php echo $stats['total_failed']; ?></div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="tablenav top" style="background: #fff; padding: 16px; margin: 20px 0; border-radius: 4px;">
                <form method="get" style="display: flex; gap: 12px; align-items: center;">
                    <input type="hidden" name="page" value="limpvix-professionals">

                    <select name="status" style="min-width: 150px;">
                        <option value="all" <?php selected($status_filter, 'all'); ?>>Todos os Status</option>
                        <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pendentes</option>
                        <option value="approved" <?php selected($status_filter, 'approved'); ?>>Aprovados</option>
                        <option value="processing" <?php selected($status_filter, 'processing'); ?>>Processando</option>
                        <option value="completed" <?php selected($status_filter, 'completed'); ?>>Concluídos</option>
                        <option value="failed" <?php selected($status_filter, 'failed'); ?>>Falhas</option>
                        <option value="cancelled" <?php selected($status_filter, 'cancelled'); ?>>Cancelados</option>
                    </select>

                    <button type="submit" class="button">Filtrar</button>

                    <div style="flex: 1;"></div>

                    <?php if ($mp_configured): ?>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                            <input type="hidden" name="action" value="limpvix_sync_payouts">
                            <?php wp_nonce_field('limpvix_sync_payouts'); ?>
                            <button type="submit" class="button button-secondary">🔄 Sincronizar com MP</button>
                        </form>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Tabela de Payouts -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Pedido</th>
                        <th>Profissional</th>
                        <th>Valor Bruto</th>
                        <th>Taxa</th>
                        <th>Valor Líquido</th>
                        <th>Destinatário</th>
                        <th>Status</th>
                        <th>Gateway</th>
                        <th>Criado em</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payouts)): ?>
                        <tr>
                            <td colspan="11" style="text-align: center; padding: 40px;">
                                <p style="color: #6b7280;">Nenhum payout encontrado.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payouts as $payout): ?>
                            <tr>
                                <td><strong>#<?php echo $payout['id']; ?></strong></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=limpvix-orders&order_id=' . $payout['order_id']); ?>">
                                        Pedido #<?php echo $payout['order_id']; ?>
                                    </a>
                                </td>
                                <td>Prof. #<?php echo $payout['professional_id']; ?></td>
                                <td>R$ <?php echo number_format($payout['gross_amount'], 2, ',', '.'); ?></td>
                                <td>R$ <?php echo number_format($payout['platform_fee'], 2, ',', '.'); ?></td>
                                <td><strong>R$ <?php echo number_format($payout['net_amount'], 2, ',', '.'); ?></strong></td>
                                <td>
                                    <div style="font-size: 12px;">
                                        <div><strong><?php echo esc_html($payout['recipient_name']); ?></strong></div>
                                        <div style="color: #6b7280;"><?php echo $this->formatRecipientType($payout['recipient_type']); ?></div>
                                        <div style="color: #6b7280; font-family: monospace;"><?php echo $this->maskRecipientKey($payout['recipient_key'], $payout['recipient_type']); ?></div>
                                    </div>
                                </td>
                                <td><?php echo $this->renderStatusBadge($payout['status']); ?></td>
                                <td>
                                    <div style="font-size: 12px;">
                                        <div><?php echo esc_html($payout['gateway']); ?></div>
                                        <?php if ($payout['gateway_transfer_id']): ?>
                                            <div style="color: #6b7280; font-family: monospace;">
                                                <?php echo substr($payout['gateway_transfer_id'], 0, 12); ?>...
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($payout['created_at'])); ?></td>
                                <td>
                                    <?php if ($payout['status'] === 'approved' && $mp_configured): ?>
                                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                            <input type="hidden" name="action" value="limpvix_process_payout">
                                            <input type="hidden" name="payout_id" value="<?php echo $payout['id']; ?>">
                                            <?php wp_nonce_field('limpvix_process_payout_' . $payout['id']); ?>
                                            <button type="submit" class="button button-primary button-small" 
                                                    onclick="return confirm('Processar payout #<?php echo $payout['id']; ?>?');">
                                                ▶️ Processar
                                            </button>
                                        </form>
                                    <?php elseif ($payout['status'] === 'failed' && $payout['retry_count'] < $payout['max_retries']): ?>
                                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                            <input type="hidden" name="action" value="limpvix_process_payout">
                                            <input type="hidden" name="payout_id" value="<?php echo $payout['id']; ?>">
                                            <?php wp_nonce_field('limpvix_process_payout_' . $payout['id']); ?>
                                            <button type="submit" class="button button-secondary button-small">
                                                🔄 Retry (<?php echo $payout['retry_count']; ?>/<?php echo $payout['max_retries']; ?>)
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">—</span>
                                    <?php endif; ?>

                                    <?php if (!empty($payout['failure_reason'])): ?>
                                        <button type="button" class="button button-small" 
                                                onclick="alert('<?php echo esc_js($payout['failure_reason']); ?>');">
                                            ⚠️ Ver Erro
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Handler: Processar payout
     *
     * ✅ CORREÇÃO P0-001: Agora usa Use Case ExecutePayout
     * - ANTES: Chamava $this->mpProvider->createPayout() direto (BYPASS)
     * - AGORA: Usa ExecutePayout que valida Execution::VALIDATED (GOLDEN RULE)
     *
     * GOLDEN RULE:
     * - Payout SÓ executa se Execution::VALIDATED
     * - Se não validado, admin vê mensagem de erro clara
     */
    public function handleProcessPayout(): void
    {
        $payout_id = absint($_POST['payout_id'] ?? 0);

        check_admin_referer('limpvix_process_payout_' . $payout_id);

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        // ✅ CORREÇÃO: Usar Use Case com todos os 5 parametros obrigatorios
        $executionRepository = new \LimpVix\Infrastructure\Persistence\WpExecutionRepository();
        $payoutRepository = new \LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository();
        $feedbackRepository = new \LimpVix\Infrastructure\Persistence\WpStructuredFeedbackRepository();
        $professionalRepository = new \LimpVix\Infrastructure\Persistence\WpProfessionalRepository();

        $useCase = new \LimpVix\Application\UseCases\Financial\ExecutePayout(
            $executionRepository,
            $this->mpProvider,
            $payoutRepository,
            $feedbackRepository,
            $professionalRepository
        );

        $result = $useCase->execute($payout_id);

        if ($result->isOk()) {
            wp_redirect(add_query_arg([
                'page' => 'limpvix-professionals', 'tab' => 'payouts',
                'message' => 'payout_processed'
            ], admin_url('admin.php')));
        } else {
            // ✅ Agora mostra ERRO se Execution não validada (Golden Rule)
            wp_die(
                '<h1>Erro ao Processar Payout</h1>' .
                '<p><strong>Motivo:</strong> ' . esc_html($result->error()) . '</p>' .
                '<p><a href="' . admin_url('admin.php?page=limpvix-professionals&tab=payouts') . '">← Voltar para Payouts</a></p>',
                'Erro - Payout Bloqueado'
            );
        }
        exit;
    }

    /**
     * Handler: Sincronizar payouts
     */
    public function handleSyncPayouts(): void
    {
        check_admin_referer('limpvix_sync_payouts');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $synced = $this->mpProvider->syncProcessingPayouts();

        wp_redirect(add_query_arg([
            'page' => 'limpvix-professionals', 'tab' => 'payouts',
            'message' => 'payouts_synced',
            'synced_count' => $synced
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Renderizar badge de status
     */
    private function renderStatusBadge(string $status): string
    {
        $badges = [
            'pending' => '<span style="background: #fbbf24; color: #78350f; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">⏳ Pendente</span>',
            'approved' => '<span style="background: #3b82f6; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">✓ Aprovado</span>',
            'processing' => '<span style="background: #8b5cf6; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">⚙️ Processando</span>',
            'completed' => '<span style="background: #10b981; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">✓ Concluído</span>',
            'failed' => '<span style="background: #ef4444; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">✗ Falhou</span>',
            'cancelled' => '<span style="background: #6b7280; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">⊘ Cancelado</span>',
            'on_hold' => '<span style="background: #f59e0b; color: #78350f; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">⏸ Retido</span>',
        ];

        return $badges[$status] ?? '<span style="color: #6b7280;">' . esc_html($status) . '</span>';
    }

    /**
     * Formatar tipo de destinatário
     */
    private function formatRecipientType(?string $type): string
    {
        if ($type === null) {
            return '— Não definido';
        }

        $types = [
            'pix' => '💳 PIX',
            'bank_account' => '🏦 Conta Bancária',
            'mp_account' => '💰 Saldo MP',
        ];

        return $types[$type] ?? esc_html($type);
    }

    /**
     * Mascarar chave de destinatário (privacidade)
     */
    /**
     * Mask recipient key (PIX, bank account, etc.)
     *
     * FASE 1.3 - ADMIN UI REFACTORING:
     * - Added admin master check (ID 1 can see full keys)
     * - Improved masking for regular admins
     * - Enhanced security for sensitive financial data
     *
     * @param string $key Recipient key (PIX, account, etc.)
     * @param string $type Type of key ('pix', 'bank_account', etc.)
     * @return string Masked key
     */
    private function maskRecipientKey(?string $key, ?string $type): string
    {
        if ($key === null || $type === null) {
            return '— Não definido';
        }

        // Admin master (ID 1) can see full keys for auditing
        if ($this->isAdminMaster()) {
            return $key;
        }

        // Regular admins see masked data
        if ($type === 'pix') {
            // Mascarar PIX (email, telefone, CPF)
            if (strpos($key, '@') !== false) {
                // Email: show only first 2 chars + domain
                $parts = explode('@', $key);
                return substr($parts[0], 0, 2) . '***@' . $parts[1];
            } elseif (strlen($key) === 11) {
                // CPF: show only first 3 and last 2
                return substr($key, 0, 3) . '.***.**' . substr($key, -2);
            } else {
                // Telefone ou outro: show only first 2 and last 2
                return substr($key, 0, 2) . '****' . substr($key, -2);
            }
        } elseif ($type === 'bank_account') {
            // Conta bancária: mask completely
            return 'Banco ***-****';
        } else {
            // Saldo MP: show only first 2
            return 'ID ' . substr($key, 0, 2) . '***';
        }
    }

    /**
     * Mask transfer ID
     *
     * FASE 1.3 - NEW METHOD:
     * - Always mask transfer IDs (even for admin master)
     * - Prevents accidental exposure in logs/screenshots
     *
     * @param string $transferId Mercado Pago transfer ID
     * @return string Masked transfer ID
     */
    private function maskTransferId(string $transferId): string
    {
        if (empty($transferId) || strlen($transferId) <= 12) {
            return str_repeat('*', strlen($transferId));
        }

        // Show first 4 and last 4 characters
        return substr($transferId, 0, 4) . str_repeat('*', strlen($transferId) - 8) . substr($transferId, -4);
    }

    /**
     * Check if current user is admin master (ID 1)
     *
     * FASE 1.3 - NEW METHOD:
     * - Admin master has full visibility for auditing purposes
     * - Can be extended to check for specific capability
     *
     * @return bool True if admin master
     */
    private function isAdminMaster(): bool
    {
        return get_current_user_id() === 1 && current_user_can('manage_options');
    }
}
