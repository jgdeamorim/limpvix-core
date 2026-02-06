<?php
/**
 * AdminActionsController - Controller para Ações Administrativas
 *
 * RESPONSABILIDADE:
 * - Processar ações administrativas via AJAX
 * - Bloquear, liberar, autorizar, reembolsar
 * - Validação de nonce e permissions
 *
 * PRINCÍPIOS:
 * - Actions via Use Cases (NUNCA direto)
 * - Security first (nonce, caps, validation)
 * - JSON response
 *
 * AÇÕES:
 * - block_order
 * - unblock_order
 * - manual_authorize
 * - refund_order
 * - execute_payout (futuro)
 *
 * PASSO 5.6.1 - Infraestrutura Admin (Esqueleto)
 * PASSO 5.6.4 - Controles Administrativos (Implementação completa)
 *
 * @package LimpVix\Admin\Controllers
 */

namespace LimpVix\Admin\Controllers;

use LimpVix\Admin\Capabilities\FinanceCapabilities;

defined('ABSPATH') || exit;

class AdminActionsController
{
    /**
     * Registrar hooks AJAX
     *
     * @return void
     */
    public function registerAjaxHandlers(): void
    {
        // Ações que requerem limpvix_finance_manage
        add_action('wp_ajax_limpvix_block_order', [$this, 'handleBlockOrder']);
        add_action('wp_ajax_limpvix_unblock_order', [$this, 'handleUnblockOrder']);
        add_action('wp_ajax_limpvix_manual_authorize', [$this, 'handleManualAuthorize']);

        // Ações que requerem limpvix_finance_payout
        add_action('wp_ajax_limpvix_execute_payout', [$this, 'handleExecutePayout']);
        add_action('wp_ajax_limpvix_refund_order', [$this, 'handleRefundOrder']);
    }

    /**
     * Handler: Bloquear order
     *
     * @return void
     */
    public function handleBlockOrder(): void
    {
        // Validação de segurança
        if (!$this->validateRequest('limpvix_finance_manage')) {
            wp_send_json_error(['message' => 'Permissão negada'], 403);
            return;
        }

        // TODO: PASSO 5.6.4
        // $orderUuid = $_POST['order_uuid'];
        // $reason = $_POST['reason'];
        // $useCase = new AdminBlockOrder(...);
        // $result = $useCase->execute($orderUuid, $reason);

        wp_send_json_error([
            'message' => 'Implementação pendente: PASSO 5.6.4'
        ], 501);
    }

    /**
     * Handler: Liberar order
     *
     * @return void
     */
    public function handleUnblockOrder(): void
    {
        // Validação de segurança
        if (!$this->validateRequest('limpvix_finance_manage')) {
            wp_send_json_error(['message' => 'Permissão negada'], 403);
            return;
        }

        // TODO: PASSO 5.6.4
        wp_send_json_error([
            'message' => 'Implementação pendente: PASSO 5.6.4'
        ], 501);
    }

    /**
     * Handler: Autorizar manualmente
     *
     * @return void
     */
    public function handleManualAuthorize(): void
    {
        // Validação de segurança
        if (!$this->validateRequest('limpvix_finance_payout')) {
            wp_send_json_error(['message' => 'Permissão negada'], 403);
            return;
        }

        // TODO: PASSO 5.6.4
        wp_send_json_error([
            'message' => 'Implementação pendente: PASSO 5.6.4'
        ], 501);
    }

    /**
     * Handler: Executar payout
     *
     * @return void
     */
    public function handleExecutePayout(): void
    {
        // Validação de segurança
        if (!$this->validateRequest('limpvix_finance_payout')) {
            wp_send_json_error(['message' => 'Permissão negada'], 403);
            return;
        }

        // TODO: PASSO 5.6.4
        wp_send_json_error([
            'message' => 'Implementação pendente: PASSO 5.6.4'
        ], 501);
    }

    /**
     * Handler: Reembolsar order
     *
     * @return void
     */
    public function handleRefundOrder(): void
    {
        // Validação de segurança
        if (!$this->validateRequest('limpvix_finance_payout')) {
            wp_send_json_error(['message' => 'Permissão negada'], 403);
            return;
        }

        // TODO: PASSO 5.6.4
        wp_send_json_error([
            'message' => 'Implementação pendente: PASSO 5.6.4'
        ], 501);
    }

    /**
     * Validar request AJAX
     *
     * @param string $requiredCap Capability requerida
     * @return bool
     */
    private function validateRequest(string $requiredCap): bool
    {
        // Verificar nonce
        if (!check_ajax_referer('limpvix_finance_actions', 'nonce', false)) {
            return false;
        }

        // Verificar capability
        if (!current_user_can($requiredCap)) {
            return false;
        }

        return true;
    }
}
