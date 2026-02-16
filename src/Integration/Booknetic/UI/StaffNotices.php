<?php
/**
 * StaffNotices - Avisos Financeiros no Painel do Staff
 *
 * RESPONSABILIDADE:
 * - Exibir avisos sobre status financeiro
 * - Notificar profissional sobre bloqueios/pendências
 * - Transparência sobre prazos de payout
 *
 * @package LimpVix\Integration\Booknetic\UI
 */

namespace LimpVix\Integration\Booknetic\UI;

defined('ABSPATH') || exit;

final class StaffNotices
{
    /**
     * Renderizar avisos no header do painel
     *
     * Hook: bkntc_staff_panel_header
     */
    public static function renderHeader(): void
    {
        $staffId = self::getCurrentStaffId();

        if (!$staffId) {
            return;
        }

        $notices = self::getStaffNotices($staffId);

        if (empty($notices)) {
            return;
        }

        echo '<div class="limpvix-staff-notices">';
        foreach ($notices as $notice) {
            self::renderNotice($notice);
        }
        echo '</div>';

        self::enqueueStyles();
    }

    /**
     * Obter avisos do profissional
     *
     * @param int $staffId
     * @return array
     */
    private static function getStaffNotices(int $staffId): array
    {
        $notices = [];
        $userId = self::getWordPressUserId($staffId);

        if (!$userId) {
            return $notices;
        }

        // Conta MP inválida
        if (!self::hasValidMercadoPagoAccount($userId)) {
            $notices[] = [
                'type' => 'error',
                'icon' => 'warning',
                'title' => 'Conta Mercado Pago Inválida',
                'message' => 'Você não pode receber pagamentos. <a href="' . admin_url('admin.php?page=limpvix-perfil') . '">Conectar conta agora</a>',
            ];
        }

        // Disputas ativas
        $disputeCount = self::getActiveDisputeCount($userId);
        if ($disputeCount > 0) {
            $notices[] = [
                'type' => 'warning',
                'icon' => 'flag',
                'title' => sprintf('%d Disputa(s) Ativa(s)', $disputeCount),
                'message' => 'Você tem disputas abertas. Pagamentos podem estar retidos. <a href="' . admin_url('admin.php?page=limpvix-disputas') . '">Ver disputas</a>',
            ];
        }

        // Payouts pendentes
        $pendingPayouts = self::getPendingPayoutsValue($userId);
        if ($pendingPayouts > 0) {
            $notices[] = [
                'type' => 'info',
                'icon' => 'money-alt',
                'title' => sprintf('R$ %.2f Pendentes', $pendingPayouts),
                'message' => 'Você tem pagamentos autorizados aguardando solicitação de payout. <a href="' . admin_url('admin.php?page=limpvix-payouts') . '">Solicitar transferência</a>',
            ];
        }

        // Bloqueio manual
        if ((bool)get_user_meta($userId, 'limpvix_staff_blocked', true)) {
            $reason = get_user_meta($userId, 'limpvix_staff_blocked_reason', true);
            $notices[] = [
                'type' => 'error',
                'icon' => 'lock',
                'title' => 'Acesso Bloqueado',
                'message' => $reason ?: 'Seu acesso foi bloqueado. Entre em contato com o suporte.',
            ];
        }

        return $notices;
    }

    /**
     * Renderizar um aviso
     *
     * @param array $notice
     */
    private static function renderNotice(array $notice): void
    {
        $type = $notice['type'] ?? 'info';
        $icon = $notice['icon'] ?? 'info';
        ?>
        <div class="limpvix-notice limpvix-notice-<?php echo esc_attr($type); ?>">
            <div class="limpvix-notice-icon">
                <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
            </div>
            <div class="limpvix-notice-content">
                <strong><?php echo wp_kses_post($notice['title']); ?></strong>
                <p><?php echo wp_kses_post($notice['message']); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Enfileirar estilos inline
     */
    private static function enqueueStyles(): void
    {
        ?>
        <style>
        .limpvix-staff-notices {
            margin: 20px 0;
        }
        .limpvix-notice {
            display: flex;
            align-items: flex-start;
            padding: 16px;
            margin-bottom: 12px;
            border-radius: 6px;
            border-left: 4px solid;
        }
        .limpvix-notice-info {
            background: #e7f5ff;
            border-left-color: #3498db;
        }
        .limpvix-notice-warning {
            background: #fff3cd;
            border-left-color: #f39c12;
        }
        .limpvix-notice-error {
            background: #f8d7da;
            border-left-color: #e74c3c;
        }
        .limpvix-notice-icon {
            flex-shrink: 0;
            margin-right: 12px;
        }
        .limpvix-notice-icon .dashicons {
            font-size: 24px;
            width: 24px;
            height: 24px;
        }
        .limpvix-notice-info .dashicons { color: #3498db; }
        .limpvix-notice-warning .dashicons { color: #f39c12; }
        .limpvix-notice-error .dashicons { color: #e74c3c; }
        .limpvix-notice-content strong {
            display: block;
            margin-bottom: 4px;
            font-size: 14px;
        }
        .limpvix-notice-content p {
            margin: 0;
            font-size: 13px;
        }
        .limpvix-notice-content a {
            font-weight: 600;
            text-decoration: underline;
        }
        </style>
        <?php
    }

    /**
     * Obter staff_id atual
     *
     * @return int|null
     */
    private static function getCurrentStaffId(): ?int
    {
        // Lógica para obter staff_id do contexto
        // Pode ser via session, query param, ou user meta
        $userId = get_current_user_id();

        if (!$userId) {
            return null;
        }

        global $wpdb;
        $staffId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bkntc_staff WHERE user_id = %d",
            $userId
        ));

        return $staffId ? (int)$staffId : null;
    }

    /**
     * Obter user_id WordPress
     *
     * @param int $staffId
     * @return int|null
     */
    private static function getWordPressUserId(int $staffId): ?int
    {
        global $wpdb;
        $userId = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}bkntc_staff WHERE id = %d",
            $staffId
        ));
        return $userId ? (int)$userId : null;
    }

    /**
     * Verificar conta MP
     *
     * @param int $userId
     * @return bool
     */
    private static function hasValidMercadoPagoAccount(int $userId): bool
    {
        return !empty(get_user_meta($userId, 'limpvix_mp_access_token', true));
    }

    /**
     * Obter número de disputas ativas
     *
     * @param int $userId
     * @return int
     */
    private static function getActiveDisputeCount(int $userId): int
    {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_financial_ledger 
             WHERE professional_id = %d AND event_type = 'dispute_opened' AND resolved = 0",
            $userId
        ));
    }

    /**
     * Obter valor pendente de payouts
     *
     * @param int $userId
     * @return float
     */
    private static function getPendingPayoutsValue(int $userId): float
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM {$wpdb->prefix}limpvix_financial_ledger 
             WHERE professional_id = %d AND event_type = 'authorized' AND payout_requested = 0",
            $userId
        ));
        return $value ? (float)$value : 0.0;
    }
}
