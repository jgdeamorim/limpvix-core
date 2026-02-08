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
 * - Actions via Use Cases (quando disponíveis)
 * - Security first (nonce, caps, validation)
 * - JSON response
 *
 * AÇÕES:
 * - block_order ✅
 * - unblock_order ✅
 * - manual_authorize ✅
 * - refund_order ✅
 * - execute_payout ✅
 *
 * PASSO 5.6.1 - Infraestrutura Admin (Esqueleto)
 * PASSO 5.6.4 - Controles Administrativos (Implementação completa) ✅
 *
 * @package LimpVix\Admin\Controllers
 */

namespace LimpVix\Admin\Controllers;

use LimpVix\Admin\Capabilities\FinanceCapabilities;
use LimpVix\Application\UseCases\Financial\ExecutePayout;
use LimpVix\Infrastructure\Persistence\WpFinancialRepository;
use LimpVix\Infrastructure\Persistence\WpOrderRepository;
use LimpVix\Infrastructure\Persistence\WpFinancialLedgerRepository;
use LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository;
use LimpVix\Infrastructure\Finance\PaymentProviders\MercadoPagoProvider;

defined('ABSPATH') || exit;

class AdminActionsController
{
    private WpOrderRepository $orderRepository;
    private WpFinancialRepository $financialRepository;
    private WpFinancialLedgerRepository $ledgerRepository;
    private WpPayoutRepository $payoutRepository;

    public function __construct()
    {
        $this->orderRepository = new WpOrderRepository();
        $this->financialRepository = new WpFinancialRepository();
        $this->ledgerRepository = new WpFinancialLedgerRepository();
        $this->payoutRepository = new WpPayoutRepository();
    }

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
     * Transiciona Financial para HELD (hold)
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

        try {
            // 1. Validar parâmetros
            $orderUuid = sanitize_text_field($_POST['order_uuid'] ?? '');
            $reason = sanitize_textarea_field($_POST['reason'] ?? 'Bloqueio administrativo');

            if (empty($orderUuid)) {
                wp_send_json_error(['message' => 'order_uuid é obrigatório'], 400);
                return;
            }

            // 2. Buscar Financial
            $financial = $this->financialRepository->findByOrderUuid($orderUuid);

            if (!$financial) {
                wp_send_json_error(['message' => 'Financial não encontrado'], 404);
                return;
            }

            // 3. Aplicar hold
            $financial->holdPayment($reason);

            // 4. Salvar
            $this->financialRepository->save($financial);

            // 5. Registrar no ledger
            $this->ledgerRepository->append([
                'order_uuid' => $orderUuid,
                'event_type' => 'admin_block_order',
                'event_data' => json_encode([
                    'reason' => $reason,
                    'admin_user' => get_current_user_id()
                ]),
                'resolved' => 0
            ]);

            wp_send_json_success([
                'message' => 'Order bloqueada com sucesso',
                'new_status' => 'HELD'
            ]);

        } catch (\Exception $e) {
            error_log('AdminActionsController::handleBlockOrder error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => 'Erro ao bloquear order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handler: Liberar order
     *
     * Remove hold da Financial
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

        try {
            // 1. Validar parâmetros
            $orderUuid = sanitize_text_field($_POST['order_uuid'] ?? '');

            if (empty($orderUuid)) {
                wp_send_json_error(['message' => 'order_uuid é obrigatório'], 400);
                return;
            }

            // 2. Buscar Financial
            $financial = $this->financialRepository->findByOrderUuid($orderUuid);

            if (!$financial) {
                wp_send_json_error(['message' => 'Financial não encontrado'], 404);
                return;
            }

            // 3. Verificar se está em HELD
            if (!$financial->getStatus()->isHeld()) {
                wp_send_json_error([
                    'message' => 'Order não está bloqueada (status atual: ' . $financial->getStatus()->value . ')'
                ], 400);
                return;
            }

            // 4. Liberar hold (transicionar para AUTHORIZED)
            $financial->authorizePayment();

            // 5. Salvar
            $this->financialRepository->save($financial);

            // 6. Registrar no ledger
            $this->ledgerRepository->append([
                'order_uuid' => $orderUuid,
                'event_type' => 'admin_unblock_order',
                'event_data' => json_encode([
                    'admin_user' => get_current_user_id()
                ]),
                'resolved' => 1
            ]);

            wp_send_json_success([
                'message' => 'Order liberada com sucesso',
                'new_status' => 'AUTHORIZED'
            ]);

        } catch (\Exception $e) {
            error_log('AdminActionsController::handleUnblockOrder error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => 'Erro ao liberar order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handler: Autorizar manualmente
     *
     * Força transição para AUTHORIZED (bypassing validações normais)
     * USO: Apenas para casos excepcionais (disputas, compensações)
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

        try {
            // 1. Validar parâmetros
            $orderUuid = sanitize_text_field($_POST['order_uuid'] ?? '');
            $reason = sanitize_textarea_field($_POST['reason'] ?? 'Autorização manual administrativa');

            if (empty($orderUuid)) {
                wp_send_json_error(['message' => 'order_uuid é obrigatório'], 400);
                return;
            }

            if (empty($reason)) {
                wp_send_json_error(['message' => 'reason é obrigatório para autorização manual'], 400);
                return;
            }

            // 2. Buscar Financial
            $financial = $this->financialRepository->findByOrderUuid($orderUuid);

            if (!$financial) {
                wp_send_json_error(['message' => 'Financial não encontrado'], 404);
                return;
            }

            // 3. Verificar se já está AUTHORIZED ou PAID
            $currentStatus = $financial->getStatus();
            if ($currentStatus->isAuthorized() || $currentStatus->isPaid()) {
                wp_send_json_error([
                    'message' => 'Order já está autorizada ou paga (status: ' . $currentStatus->value . ')'
                ], 400);
                return;
            }

            // 4. Forçar autorização
            $financial->authorizePayment();

            // 5. Salvar
            $this->financialRepository->save($financial);

            // 6. Registrar no ledger (CRÍTICO para auditoria)
            $this->ledgerRepository->append([
                'order_uuid' => $orderUuid,
                'event_type' => 'admin_manual_authorize',
                'event_data' => json_encode([
                    'reason' => $reason,
                    'admin_user' => get_current_user_id(),
                    'previous_status' => $currentStatus->value,
                    'timestamp' => current_time('mysql')
                ]),
                'resolved' => 0
            ]);

            wp_send_json_success([
                'message' => 'Order autorizada manualmente com sucesso',
                'new_status' => 'AUTHORIZED',
                'warning' => 'Autorização manual registrada no ledger para auditoria'
            ]);

        } catch (\Exception $e) {
            error_log('AdminActionsController::handleManualAuthorize error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => 'Erro ao autorizar manualmente: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handler: Executar payout
     *
     * Usa ExecutePayout Use Case (respeitando Golden Rule)
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

        try {
            // 1. Validar parâmetros
            $payoutId = intval($_POST['payout_id'] ?? 0);

            if ($payoutId <= 0) {
                wp_send_json_error(['message' => 'payout_id inválido'], 400);
                return;
            }

            // 2. Executar payout via Use Case (Golden Rule protegido)
            $mercadoPagoProvider = new MercadoPagoProvider();
            $useCase = new ExecutePayout(
                $this->payoutRepository,
                $mercadoPagoProvider
            );

            $result = $useCase->execute($payoutId);

            // 3. Retornar resultado
            if ($result->isOk()) {
                wp_send_json_success([
                    'message' => 'Payout executado com sucesso',
                    'data' => $result->value()
                ]);
            } else {
                wp_send_json_error([
                    'message' => $result->error()
                ], 400);
            }

        } catch (\Exception $e) {
            error_log('AdminActionsController::handleExecutePayout error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => 'Erro ao executar payout: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handler: Reembolsar order
     *
     * Transiciona Financial para REFUNDED
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

        try {
            // 1. Validar parâmetros
            $orderUuid = sanitize_text_field($_POST['order_uuid'] ?? '');
            $reason = sanitize_textarea_field($_POST['reason'] ?? 'Reembolso administrativo');
            $amount = floatval($_POST['amount'] ?? 0);

            if (empty($orderUuid)) {
                wp_send_json_error(['message' => 'order_uuid é obrigatório'], 400);
                return;
            }

            // 2. Buscar Financial
            $financial = $this->financialRepository->findByOrderUuid($orderUuid);

            if (!$financial) {
                wp_send_json_error(['message' => 'Financial não encontrado'], 404);
                return;
            }

            // 3. Verificar se pode reembolsar (deve estar PAID)
            if (!$financial->getStatus()->isPaid()) {
                wp_send_json_error([
                    'message' => 'Apenas orders pagas podem ser reembolsadas (status atual: ' . $financial->getStatus()->value . ')'
                ], 400);
                return;
            }

            // 4. Aplicar refund
            $financial->refundPayment($reason);

            // 5. Salvar
            $this->financialRepository->save($financial);

            // 6. Registrar no ledger
            $this->ledgerRepository->append([
                'order_uuid' => $orderUuid,
                'event_type' => 'admin_refund_order',
                'event_data' => json_encode([
                    'reason' => $reason,
                    'amount' => $amount,
                    'admin_user' => get_current_user_id(),
                    'timestamp' => current_time('mysql')
                ]),
                'resolved' => 1
            ]);

            wp_send_json_success([
                'message' => 'Order reembolsada com sucesso',
                'new_status' => 'REFUNDED',
                'note' => 'Reembolso registrado. Processo financeiro no gateway deve ser feito manualmente.'
            ]);

        } catch (\Exception $e) {
            error_log('AdminActionsController::handleRefundOrder error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => 'Erro ao reembolsar order: ' . $e->getMessage()
            ], 500);
        }
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
