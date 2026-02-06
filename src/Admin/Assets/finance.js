/**
 * Limpvix Finance - Admin Scripts
 *
 * PASSO 5.6.1 - Infraestrutura Admin
 *
 * @package LimpVix\Admin\Assets
 */

(function($) {
    'use strict';

    /**
     * Limpvix Finance Admin
     */
    const LimpvixFinance = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.logCapabilities();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            // Admin actions (PASSO 5.6.4)
            $('.limpvix-action-block').on('click', this.handleBlockOrder);
            $('.limpvix-action-unblock').on('click', this.handleUnblockOrder);
            $('.limpvix-action-authorize').on('click', this.handleManualAuthorize);
            $('.limpvix-action-payout').on('click', this.handleExecutePayout);
            $('.limpvix-action-refund').on('click', this.handleRefundOrder);
        },

        /**
         * Log capabilities (debug)
         */
        logCapabilities: function() {
            if (typeof limpvixFinance === 'undefined') {
                return;
            }

            console.log('Limpvix Finance - Capabilities:', limpvixFinance.capabilities);
        },

        /**
         * Handle: Block Order
         */
        handleBlockOrder: function(e) {
            e.preventDefault();

            const orderUuid = $(this).data('order-uuid');
            const reason = prompt('Motivo do bloqueio:');

            if (!reason) {
                return;
            }

            LimpvixFinance.executeAction('limpvix_block_order', {
                order_uuid: orderUuid,
                reason: reason
            });
        },

        /**
         * Handle: Unblock Order
         */
        handleUnblockOrder: function(e) {
            e.preventDefault();

            const orderUuid = $(this).data('order-uuid');

            if (!confirm('Tem certeza que deseja liberar esta order?')) {
                return;
            }

            LimpvixFinance.executeAction('limpvix_unblock_order', {
                order_uuid: orderUuid
            });
        },

        /**
         * Handle: Manual Authorize
         */
        handleManualAuthorize: function(e) {
            e.preventDefault();

            const orderUuid = $(this).data('order-uuid');

            if (!confirm('Tem certeza que deseja autorizar manualmente?')) {
                return;
            }

            LimpvixFinance.executeAction('limpvix_manual_authorize', {
                order_uuid: orderUuid
            });
        },

        /**
         * Handle: Execute Payout
         */
        handleExecutePayout: function(e) {
            e.preventDefault();

            const orderUuid = $(this).data('order-uuid');

            if (!confirm('ATENÇÃO: Executar payout irá transferir dinheiro real. Confirmar?')) {
                return;
            }

            LimpvixFinance.executeAction('limpvix_execute_payout', {
                order_uuid: orderUuid
            });
        },

        /**
         * Handle: Refund Order
         */
        handleRefundOrder: function(e) {
            e.preventDefault();

            const orderUuid = $(this).data('order-uuid');
            const reason = prompt('Motivo do reembolso:');

            if (!reason) {
                return;
            }

            if (!confirm('ATENÇÃO: Reembolso é uma ação final. Confirmar?')) {
                return;
            }

            LimpvixFinance.executeAction('limpvix_refund_order', {
                order_uuid: orderUuid,
                reason: reason
            });
        },

        /**
         * Execute AJAX action
         */
        executeAction: function(action, data) {
            if (typeof limpvixFinance === 'undefined') {
                alert('Erro: Configuração do Limpvix Finance não carregada');
                return;
            }

            // Show loading
            const button = $('[data-action="' + action + '"]');
            button.prop('disabled', true).text('Processando...');

            $.ajax({
                url: limpvixFinance.ajaxUrl,
                method: 'POST',
                data: {
                    action: action,
                    nonce: limpvixFinance.nonce,
                    ...data
                },
                success: function(response) {
                    if (response.success) {
                        alert('Ação executada com sucesso!');
                        location.reload();
                    } else {
                        alert('Erro: ' + (response.data.message || 'Erro desconhecido'));
                    }
                },
                error: function(xhr, status, error) {
                    alert('Erro na comunicação: ' + error);
                },
                complete: function() {
                    button.prop('disabled', false).text(button.data('original-text'));
                }
            });
        }
    };

    /**
     * Document ready
     */
    $(document).ready(function() {
        LimpvixFinance.init();
    });

})(jQuery);
