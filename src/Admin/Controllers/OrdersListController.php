<?php
/**
 * OrdersListController - Controller para Lista de Orders
 *
 * RESPONSABILIDADE:
 * - Renderizar lista de orders financeiras
 * - Filtros e paginação
 * - Ações rápidas
 *
 * PRINCÍPIOS:
 * - MVC Pattern
 * - Read-only (não modifica estado)
 * - Permissions gated
 *
 * DADOS EXIBIDOS:
 * - Order UUID
 * - Status financeiro (cache)
 * - Último evento
 * - Flags (erro, bloqueio, etc)
 *
 * PASSO 5.6.1 - Infraestrutura Admin (Esqueleto)
 * PASSO 5.6.2 - Orders List (Implementação completa)
 *
 * @package LimpVix\Admin\Controllers
 */

namespace LimpVix\Admin\Controllers;

use LimpVix\Admin\Capabilities\FinanceCapabilities;

defined('ABSPATH') || exit;

class OrdersListController
{
    /**
     * Renderizar página
     *
     * @return void
     */
    public function render(): void
    {
        // Gate de permissão
        if (!FinanceCapabilities::canView()) {
            wp_die('Você não tem permissão para acessar esta página.');
        }

        // TODO: PASSO 5.6.2
        // - Buscar orders
        // - Aplicar filtros
        // - Paginar
        // - Renderizar view

        $this->renderSkeleton();
    }

    /**
     * Renderizar esqueleto (placeholder)
     *
     * @return void
     */
    private function renderSkeleton(): void
    {
        ?>
        <div class="wrap limpvix-finance">
            <h1>Orders Financeiras</h1>

            <div class="notice notice-info">
                <p><strong>PASSO 5.6.1 concluído:</strong> Infraestrutura administrativa registrada.</p>
                <p><strong>PASSO 5.6.2:</strong> Lista de orders será implementada na próxima fase.</p>
            </div>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <select disabled>
                        <option>Filtrar por status</option>
                    </select>
                    <button class="button" disabled>Aplicar</button>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Order UUID</th>
                        <th>Status Financeiro</th>
                        <th>Último Evento</th>
                        <th>Flags</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px;">
                            <em>Implementação completa: PASSO 5.6.2</em>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
