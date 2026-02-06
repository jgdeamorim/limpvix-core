<?php
/**
 * StaffPanelOverride - Customização do Painel do Staff
 *
 * RESPONSABILIDADE:
 * - Ocultar abas/menus indevidos do Booknetic
 * - Redirecionar para páginas Limpvix quando necessário
 * - Garantir que profissional veja apenas o necessário
 *
 * @package LimpVix\Integration\Booknetic\UI
 */

namespace LimpVix\Integration\Booknetic\UI;

defined('ABSPATH') || exit;

final class StaffPanelOverride
{
    /**
     * Abas do Booknetic que devem ser ocultadas
     */
    private const HIDDEN_TABS = [
        'financial', // Financeiro é soberano do Limpvix
        'reports',   // Relatórios podem induzir erro
        'earnings',  // Ganhos controlados pelo Limpvix
        'settings',  // Settings que podem conflitar
    ];

    /**
     * Menus do Booknetic que devem ser ocultados
     */
    private const HIDDEN_MENUS = [
        'booknetic_payments',
        'booknetic_payouts',
        'booknetic_reports',
    ];

    /**
     * Ocultar abas financeiras via JS
     *
     * Hook: bkntc_staff_panel_footer
     */
    public static function hideFinancialTabs(): void
    {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Ocultar abas indevidas
            const tabsToHide = <?php echo json_encode(self::HIDDEN_TABS); ?>;
            
            tabsToHide.forEach(tabSlug => {
                const tab = document.querySelector(`[data-tab="${tabSlug}"]`);
                if (tab) {
                    tab.style.display = 'none';
                }
            });

            // Adicionar link para painel Limpvix
            const staffMenu = document.querySelector('.booknetic-staff-menu');
            if (staffMenu) {
                const limpvixLink = document.createElement('li');
                limpvixLink.innerHTML = `
                    <a href="<?php echo admin_url('admin.php?page=limpvix-painel-profissional'); ?>">
                        <span class="dashicons dashicons-chart-line"></span>
                        Painel Financeiro Limpvix
                    </a>
                `;
                staffMenu.appendChild(limpvixLink);
            }
        });
        </script>
        <style>
        /* Forçar ocultação por CSS também */
        <?php foreach (self::HIDDEN_TABS as $tab): ?>
        [data-tab="<?php echo esc_attr($tab); ?>"],
        .booknetic-tab-<?php echo esc_attr($tab); ?> {
            display: none !important;
        }
        <?php endforeach; ?>
        </style>
        <?php
    }

    /**
     * Ocultar menus do Booknetic
     *
     * Hook: admin_menu (priority 999)
     */
    public static function hideMenus(): void
    {
        // Verificar se usuário é staff
        if (!self::isStaffUser()) {
            return;
        }

        // Remover menus indevidos
        foreach (self::HIDDEN_MENUS as $menuSlug) {
            remove_menu_page($menuSlug);
        }

        // Adicionar menu Limpvix para profissional
        add_menu_page(
            'Painel Limpvix',
            'Meu Painel',
            'read', // Capability básica
            'limpvix-painel-profissional',
            [__CLASS__, 'renderProfessionalPanel'],
            'dashicons-chart-line',
            3
        );
    }

    /**
     * Renderizar painel do profissional Limpvix
     */
    public static function renderProfessionalPanel(): void
    {
        $userId = get_current_user_id();
        
        ?>
        <div class="wrap limpvix-professional-panel">
            <h1>
                <span class="dashicons dashicons-businessman"></span>
                Painel do Profissional
            </h1>

            <div class="limpvix-panel-grid">
                <!-- Status Financeiro -->
                <div class="limpvix-card">
                    <h2>Status Financeiro</h2>
                    <?php self::renderFinancialStatus($userId); ?>
                </div>

                <!-- Próximos Payouts -->
                <div class="limpvix-card">
                    <h2>Próximos Repasses</h2>
                    <?php self::renderUpcomingPayouts($userId); ?>
                </div>

                <!-- Histórico -->
                <div class="limpvix-card">
                    <h2>Histórico de Pagamentos</h2>
                    <?php self::renderPayoutHistory($userId); ?>
                </div>

                <!-- Conta Mercado Pago -->
                <div class="limpvix-card">
                    <h2>Conta Mercado Pago</h2>
                    <?php self::renderMercadoPagoStatus($userId); ?>
                </div>
            </div>
        </div>

        <style>
        .limpvix-professional-panel h1 {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .limpvix-panel-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .limpvix-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 20px;
        }
        .limpvix-card h2 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        </style>
        <?php
    }

    /**
     * Renderizar status financeiro
     *
     * @param int $userId
     */
    private static function renderFinancialStatus(int $userId): void
    {
        global $wpdb;

        $authorized = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM {$wpdb->prefix}limpvix_financial_ledger 
             WHERE professional_id = %d AND event_type = 'authorized'",
            $userId
        ));

        $transferred = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM {$wpdb->prefix}limpvix_financial_ledger 
             WHERE professional_id = %d AND event_type = 'transferred'",
            $userId
        ));

        ?>
        <table class="widefat">
            <tr>
                <td><strong>Autorizado (disponível)</strong></td>
                <td style="text-align: right;">R$ <?php echo number_format($authorized ?: 0, 2, ',', '.'); ?></td>
            </tr>
            <tr>
                <td><strong>Já transferido</strong></td>
                <td style="text-align: right;">R$ <?php echo number_format($transferred ?: 0, 2, ',', '.'); ?></td>
            </tr>
        </table>

        <?php if ($authorized > 0): ?>
        <p style="margin-top: 16px;">
            <a href="<?php echo admin_url('admin.php?page=limpvix-solicitar-payout'); ?>" class="button button-primary">
                <span class="dashicons dashicons-money-alt"></span>
                Solicitar Transferência
            </a>
        </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Renderizar próximos payouts
     *
     * @param int $userId
     */
    private static function renderUpcomingPayouts(int $userId): void
    {
        echo '<p>Em breve serão exibidos os próximos repasses autorizados.</p>';
    }

    /**
     * Renderizar histórico
     *
     * @param int $userId
     */
    private static function renderPayoutHistory(int $userId): void
    {
        echo '<p>Histórico completo em breve.</p>';
    }

    /**
     * Renderizar status Mercado Pago
     *
     * @param int $userId
     */
    private static function renderMercadoPagoStatus(int $userId): void
    {
        $hasMP = !empty(get_user_meta($userId, 'limpvix_mp_access_token', true));

        if ($hasMP) {
            echo '<p style="color: green;">✅ Conta conectada</p>';
        } else {
            echo '<p style="color: red;">❌ Conta não conectada</p>';
            echo '<a href="' . admin_url('admin.php?page=limpvix-conectar-mp') . '" class="button">Conectar agora</a>';
        }
    }

    /**
     * Verificar se usuário é staff
     *
     * @return bool
     */
    private static function isStaffUser(): bool
    {
        $userId = get_current_user_id();

        if (!$userId) {
            return false;
        }

        global $wpdb;
        $staffId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bkntc_staff WHERE user_id = %d",
            $userId
        ));

        return (bool)$staffId;
    }
}
