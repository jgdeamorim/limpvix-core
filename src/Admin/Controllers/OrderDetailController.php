<?php
/**
 * OrderDetailController - Controller para Detalhes da Order
 *
 * RESPONSABILIDADE:
 * - Renderizar detalhes de uma order específica
 * - Timeline do Ledger
 * - Histórico de payouts
 * - Ações administrativas
 *
 * PRINCÍPIOS:
 * - MVC Pattern
 * - Read-only + Actions via Use Cases
 * - Permissions gated
 *
 * DADOS EXIBIDOS:
 * - Informações da order
 * - Timeline do Ledger (ReconstructFinancialState)
 * - Payouts executados
 * - Ações disponíveis (baseadas no estado)
 *
 * PASSO 5.6.1 - Infraestrutura Admin (Esqueleto)
 * PASSO 5.6.3 - Order Detail + Timeline (Implementação completa)
 *
 * @package LimpVix\Admin\Controllers
 */

namespace LimpVix\Admin\Controllers;

use LimpVix\Admin\Capabilities\FinanceCapabilities;

defined('ABSPATH') || exit;

class OrderDetailController
{
    /**
     * Renderizar página
     *
     * @param string $orderUuid UUID da order
     * @return void
     */
    public function render(string $orderUuid): void
    {
        // Gate de permissão
        if (!FinanceCapabilities::canView()) {
            wp_die('Você não tem permissão para acessar esta página.');
        }

        // TODO: PASSO 5.6.3
        // - Buscar order
        // - Reconstruir estado via Ledger
        // - Buscar payouts
        // - Renderizar timeline
        // - Exibir ações disponíveis

        $this->renderSkeleton($orderUuid);
    }

    /**
     * Renderizar esqueleto (placeholder)
     *
     * @param string $orderUuid
     * @return void
     */
    private function renderSkeleton(string $orderUuid): void
    {
        ?>
        <div class="wrap limpvix-finance">
            <h1>Detalhes da Order</h1>

            <div class="notice notice-info">
                <p><strong>Order UUID:</strong> <?php echo esc_html($orderUuid); ?></p>
                <p><strong>PASSO 5.6.3:</strong> Timeline e detalhes serão implementados na próxima fase.</p>
            </div>

            <h2>Timeline Financeira (Ledger)</h2>
            <div class="limpvix-timeline" style="border: 1px dashed #ccc; padding: 20px; text-align: center;">
                <em>Timeline do Ledger será renderizada aqui (PASSO 5.6.3)</em>
            </div>

            <h2>Histórico de Payouts</h2>
            <div class="limpvix-payouts" style="border: 1px dashed #ccc; padding: 20px; text-align: center; margin-top: 20px;">
                <em>Histórico de payouts será exibido aqui (PASSO 5.6.3)</em>
            </div>

            <h2>Ações Administrativas</h2>
            <div class="limpvix-actions" style="margin-top: 20px;">
                <button class="button" disabled>Bloquear</button>
                <button class="button" disabled>Liberar</button>
                <button class="button" disabled>Autorizar Manualmente</button>
                <button class="button button-primary" disabled>Executar Payout</button>
                <p><small><em>Ações serão implementadas no PASSO 5.6.4</em></small></p>
            </div>
        </div>
        <?php
    }
}
