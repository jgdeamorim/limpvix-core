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
 * - execute_payout ✅ (usa ExecutePayout Use Case)
 * - block_order, unblock_order, manual_authorize, refund_order (implementação simplificada)
 *
 * PASSO 5.6.1 - Infraestrutura Admin (Esqueleto)
 * PASSO 5.6.4 - Controles Administrativos (Implementação completa) ✅
 *
 * @package LimpVix\Admin\Controllers
 */

namespace LimpVix\Admin\Controllers;

use LimpVix\Admin\Capabilities\FinanceCapabilities;
use LimpVix\Application\UseCases\Financial\ExecutePayout;
use LimpVix\Application\UseCases\Feedback\CalculateProfessionalScore;
use LimpVix\Infrastructure\Persistence\WpFinancialLedgerRepository;
use LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository;
use LimpVix\Infrastructure\Finance\Providers\EfiPayoutProvider;
use LimpVix\Infrastructure\Persistence\WpExecutionRepository;
use LimpVix\Infrastructure\Feedback\Repositories\WpFeedbackRepository;
use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;

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

        // PIX manual — admin marca payout como pago via PIX
        add_action('wp_ajax_limpvix_mark_pix_paid', [$this, 'handleMarkPixPaid']);

        // Resolução de feedback bloqueante antes do payout
        add_action('wp_ajax_limpvix_resolve_feedback_and_payout', [$this, 'handleResolveFeedbackAndPayout']);

        // Pré-aprovação role-based: gerente autoriza, financeiro processa
        add_action('wp_ajax_limpvix_authorize_payout', [$this, 'handleAuthorizePayout']);
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

        try {
            global $wpdb;

            $orderUuid = sanitize_text_field($_POST['order_uuid'] ?? '');
            $reason = sanitize_textarea_field($_POST['reason'] ?? 'Bloqueio administrativo');

            if (empty($orderUuid)) {
                wp_send_json_error(['message' => 'order_uuid é obrigatório'], 400);
                return;
            }

            // Atualizar status financeiro para HELD
            $updated = $wpdb->update(
                $wpdb->prefix . 'limpvix_orders',
                ['financial_status' => 'HELD'],
                ['uuid' => $orderUuid],
                ['%s'],
                ['%s']
            );

            if ($updated === false) {
                wp_send_json_error(['message' => 'Erro ao bloquear order'], 500);
                return;
            }

            // Registrar no ledger
            $ledgerRepo = new WpFinancialLedgerRepository();
            $ledgerRepo->append([
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
            global $wpdb;

            $orderUuid = sanitize_text_field($_POST['order_uuid'] ?? '');

            if (empty($orderUuid)) {
                wp_send_json_error(['message' => 'order_uuid é obrigatório'], 400);
                return;
            }

            // Verificar status atual
            $currentStatus = $wpdb->get_var($wpdb->prepare(
                "SELECT financial_status FROM {$wpdb->prefix}limpvix_orders WHERE uuid = %s",
                $orderUuid
            ));

            if ($currentStatus !== 'HELD') {
                wp_send_json_error([
                    'message' => 'Order não está bloqueada (status atual: ' . $currentStatus . ')'
                ], 400);
                return;
            }

            // Liberar para AUTHORIZED
            $updated = $wpdb->update(
                $wpdb->prefix . 'limpvix_orders',
                ['financial_status' => 'AUTHORIZED'],
                ['uuid' => $orderUuid],
                ['%s'],
                ['%s']
            );

            if ($updated === false) {
                wp_send_json_error(['message' => 'Erro ao liberar order'], 500);
                return;
            }

            // Registrar no ledger
            $ledgerRepo = new WpFinancialLedgerRepository();
            $ledgerRepo->append([
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
            global $wpdb;

            $orderUuid = sanitize_text_field($_POST['order_uuid'] ?? '');
            $reason = sanitize_textarea_field($_POST['reason'] ?? '');

            if (empty($orderUuid)) {
                wp_send_json_error(['message' => 'order_uuid é obrigatório'], 400);
                return;
            }

            if (empty($reason)) {
                wp_send_json_error(['message' => 'reason é obrigatório para autorização manual'], 400);
                return;
            }

            // Verificar status atual
            $currentStatus = $wpdb->get_var($wpdb->prepare(
                "SELECT financial_status FROM {$wpdb->prefix}limpvix_orders WHERE uuid = %s",
                $orderUuid
            ));

            if (in_array($currentStatus, ['AUTHORIZED', 'PAID', 'TRANSFERRED'])) {
                wp_send_json_error([
                    'message' => 'Order já está autorizada ou paga (status: ' . $currentStatus . ')'
                ], 400);
                return;
            }

            // Forçar autorização
            $updated = $wpdb->update(
                $wpdb->prefix . 'limpvix_orders',
                ['financial_status' => 'AUTHORIZED'],
                ['uuid' => $orderUuid],
                ['%s'],
                ['%s']
            );

            if ($updated === false) {
                wp_send_json_error(['message' => 'Erro ao autorizar order'], 500);
                return;
            }

            // Registrar no ledger (CRÍTICO para auditoria)
            $ledgerRepo = new WpFinancialLedgerRepository();
            $ledgerRepo->append([
                'order_uuid' => $orderUuid,
                'event_type' => 'admin_manual_authorize',
                'event_data' => json_encode([
                    'reason' => $reason,
                    'admin_user' => get_current_user_id(),
                    'previous_status' => $currentStatus,
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
            $payoutId = intval($_POST['payout_id'] ?? 0);

            if ($payoutId <= 0) {
                wp_send_json_error(['message' => 'payout_id inválido'], 400);
                return;
            }

            // Executar payout via Use Case (Golden Rule protegido)
            $executionRepo    = new WpExecutionRepository();
            $payoutProvider   = new EfiPayoutProvider();
            $payoutRepo       = new WpPayoutRepository();
            $feedbackRepo     = new WpFeedbackRepository();
            $professionalRepo = new WpMarketplaceProfessionalRepository();

            $useCase = new ExecutePayout(
                $executionRepo,
                $payoutProvider,
                $payoutRepo,
                $feedbackRepo,
                $professionalRepo
            );

            $result = $useCase->execute($payoutId);

            // Retornar resultado
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
            global $wpdb;

            $orderUuid = sanitize_text_field($_POST['order_uuid'] ?? '');
            $reason = sanitize_textarea_field($_POST['reason'] ?? 'Reembolso administrativo');
            $amount = floatval($_POST['amount'] ?? 0);

            if (empty($orderUuid)) {
                wp_send_json_error(['message' => 'order_uuid é obrigatório'], 400);
                return;
            }

            // Verificar status atual
            $currentStatus = $wpdb->get_var($wpdb->prepare(
                "SELECT financial_status FROM {$wpdb->prefix}limpvix_orders WHERE uuid = %s",
                $orderUuid
            ));

            if ($currentStatus !== 'PAID') {
                wp_send_json_error([
                    'message' => 'Apenas orders pagas podem ser reembolsadas (status atual: ' . $currentStatus . ')'
                ], 400);
                return;
            }

            // Aplicar refund
            $updated = $wpdb->update(
                $wpdb->prefix . 'limpvix_orders',
                ['financial_status' => 'REFUNDED'],
                ['uuid' => $orderUuid],
                ['%s'],
                ['%s']
            );

            if ($updated === false) {
                wp_send_json_error(['message' => 'Erro ao reembolsar order'], 500);
                return;
            }

            // Registrar no ledger
            $ledgerRepo = new WpFinancialLedgerRepository();
            $ledgerRepo->append([
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
     * Handler AJAX: Marcar payout PIX como pago manualmente
     *
     * Chamado via $.post(ajaxurl, {action:'limpvix_mark_pix_paid', ...})
     * O nonce é gerado por payout: limpvix_mark_pix_paid_{id}
     */
    public function handleMarkPixPaid(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada.'], 403);
            return;
        }

        $payoutId = (int) ($_POST['payout_id'] ?? 0);
        if ($payoutId <= 0) {
            wp_send_json_error(['message' => 'payout_id inválido.'], 400);
            return;
        }

        // Nonce por payout (gerado em renderPayoutsTable)
        if (!check_ajax_referer('limpvix_mark_pix_paid_' . $payoutId, '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Nonce inválido ou expirado.'], 403);
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_payouts';

        // Verificar que payout existe e está aprovado
        $payout = $wpdb->get_row(
            $wpdb->prepare("SELECT id, status, payout_method, net_amount, professional_id FROM {$table} WHERE id = %d", $payoutId),
            ARRAY_A
        );

        if (!$payout) {
            wp_send_json_error(['message' => "Payout #{$payoutId} não encontrado."], 404);
            return;
        }

        if ($payout['status'] !== 'approved') {
            wp_send_json_error(['message' => "Payout #{$payoutId} não está no status 'approved' (atual: {$payout['status']})."], 400);
            return;
        }

        $proof   = sanitize_textarea_field($_POST['payment_proof'] ?? '');
        $adminId = get_current_user_id();
        $now     = current_time('mysql');

        $updated = $wpdb->update(
            $table,
            [
                'status'                  => 'completed',
                'manually_marked_paid_by' => $adminId,
                'manually_marked_paid_at' => $now,
                'manual_payment_proof'    => $proof ?: null,
                'completed_at'            => $now,
            ],
            ['id' => $payoutId],
            ['%s', '%d', '%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => 'Erro ao atualizar payout no banco de dados.'], 500);
            return;
        }

        // Log de auditoria
        limpvix_log_event('pix_manual_paid', [
            'payout_id'   => $payoutId,
            'admin_id'    => $adminId,
            'net_amount'  => $payout['net_amount'],
            'has_proof'   => !empty($proof),
        ]);

        wp_send_json_success([
            'message'   => "Payout PIX #{$payoutId} marcado como pago com sucesso.",
            'payout_id' => $payoutId,
            'admin'     => wp_get_current_user()->display_name,
            'paid_at'   => $now,
        ]);
    }

    /**
     * Handler AJAX: Resolver feedback bloqueante e (opcionalmente) processar payout
     *
     * mode=execute   → resolve feedback + recalcula score + executa payout EFI Bank (auto PIX / on_hold)
     * mode=resolve_only → resolve feedback + recalcula score apenas (PIX manual abre modal próprio depois)
     *
     * Nonce por feedback: limpvix_resolve_feedback_{feedbackId}
     */
    public function handleResolveFeedbackAndPayout(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada.'], 403);
            return;
        }

        $feedbackId = (int) ($_POST['feedback_id'] ?? 0);
        if ($feedbackId <= 0) {
            wp_send_json_error(['message' => 'feedback_id inválido.'], 400);
            return;
        }

        if (!check_ajax_referer('limpvix_resolve_feedback_' . $feedbackId, '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Nonce inválido ou expirado.'], 403);
            return;
        }

        $payoutId       = (int)    ($_POST['payout_id']        ?? 0);
        $resolutionText = sanitize_textarea_field($_POST['resolution_text']   ?? '');
        $severity       = sanitize_key($_POST['severity']        ?? '');
        $resolvedByName = sanitize_text_field($_POST['resolved_by_name']   ?? '');
        $mode           = sanitize_key($_POST['mode']            ?? 'execute'); // execute | resolve_only

        if (empty($resolutionText)) {
            wp_send_json_error(['message' => 'O texto da resolução é obrigatório.'], 400);
            return;
        }

        if (!in_array($severity, ['grave', 'medio', 'leve'], true)) {
            wp_send_json_error(['message' => 'Gravidade inválida. Use: grave, medio, leve.'], 400);
            return;
        }

        try {
            $feedbackRepo     = new WpFeedbackRepository();
            $professionalRepo = new WpMarketplaceProfessionalRepository();

            // 1. Carregar feedback
            $feedback = $feedbackRepo->findById($feedbackId);
            if (!$feedback) {
                wp_send_json_error(['message' => "Feedback #{$feedbackId} não encontrado."], 404);
                return;
            }

            if ($feedback->isApproved()) {
                wp_send_json_error(['message' => "Feedback #{$feedbackId} já foi aprovado/resolvido."], 400);
                return;
            }

            // 2. Registrar resolução (chama approve() internamente → blocksPayout() = false)
            $resolvedByName = $resolvedByName ?: wp_get_current_user()->display_name;
            $feedback->resolve(get_current_user_id(), $resolvedByName, $resolutionText, $severity);
            $feedbackRepo->save($feedback);

            // 3. Recalcular score do profissional com a penalidade aplicada
            $scoreUseCase = new CalculateProfessionalScore($feedbackRepo, $professionalRepo);
            $scoreResult  = $scoreUseCase->execute($feedback->getProfessionalId());

            $newScore = $scoreResult->isOk() ? $scoreResult->value() : null;

            // 4. Executar payout (somente em modo auto)
            if ($mode === 'execute') {
                if ($payoutId <= 0) {
                    wp_send_json_error(['message' => 'payout_id inválido para execução.'], 400);
                    return;
                }

                $executionRepo  = new WpExecutionRepository();
                $payoutProvider = new EfiPayoutProvider();
                $payoutRepo     = new WpPayoutRepository();

                $executeUseCase = new ExecutePayout(
                    $executionRepo,
                    $payoutProvider,
                    $payoutRepo,
                    $feedbackRepo,
                    $professionalRepo
                );

                $payoutResult = $executeUseCase->execute($payoutId);

                if (!$payoutResult->isOk()) {
                    wp_send_json_error([
                        'message'   => 'Feedback resolvido, mas falha ao processar payout: ' . $payoutResult->error(),
                        'new_score' => $newScore,
                    ], 400);
                    return;
                }

                limpvix_log_event('feedback_resolved_payout_executed', [
                    'feedback_id' => $feedbackId,
                    'payout_id'   => $payoutId,
                    'severity'    => $severity,
                    'new_score'   => $newScore,
                    'admin_id'    => get_current_user_id(),
                ]);

                wp_send_json_success([
                    'message'   => 'Feedback resolvido e payout processado com sucesso.',
                    'new_score' => $newScore,
                    'severity'  => $severity,
                ]);

            } else {
                // resolve_only: feedback resolvido, JS abre modal PIX manual
                limpvix_log_event('feedback_resolved_pix_manual_pending', [
                    'feedback_id' => $feedbackId,
                    'payout_id'   => $payoutId,
                    'severity'    => $severity,
                    'new_score'   => $newScore,
                    'admin_id'    => get_current_user_id(),
                ]);

                wp_send_json_success([
                    'message'   => 'Feedback resolvido. Prossiga com o pagamento PIX manual.',
                    'new_score' => $newScore,
                    'severity'  => $severity,
                    'open_pix_modal' => true,
                ]);
            }

        } catch (\InvalidArgumentException $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            error_log('AdminActionsController::handleResolveFeedbackAndPayout error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Erro ao resolver feedback: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handler: Pré-autorizar payout (step 1 do fluxo dual)
     *
     * Requer capability configurável em Settings > Profissionais > Resolução:
     *   limpvix_payout_cap_authorize (padrão: limpvix_authorize_payout)
     *
     * Muda status: approved → authorized
     * POST params: payout_id, nonce (limpvix_authorize_payout_{id})
     */
    public function handleAuthorizePayout(): void
    {
        $payoutId = (int) ($_POST['payout_id'] ?? 0);

        if ($payoutId <= 0) {
            wp_send_json_error(['message' => 'ID de payout inválido.'], 400);
            return;
        }

        // Verificar nonce específico do payout
        if (!check_ajax_referer('limpvix_authorize_payout_' . $payoutId, 'nonce', false)) {
            wp_send_json_error(['message' => 'Nonce inválido.'], 403);
            return;
        }

        // Capability configurável no Settings
        $requiredCap = get_option('limpvix_payout_cap_authorize', 'limpvix_authorize_payout');
        if (!current_user_can($requiredCap) && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão para autorizar este payout.'], 403);
            return;
        }

        try {
            global $wpdb;
            $table = $wpdb->prefix . 'limpvix_payouts';

            // Verificar estado atual
            $payout = $wpdb->get_row(
                $wpdb->prepare("SELECT id, status FROM {$table} WHERE id = %d", $payoutId),
                ARRAY_A
            );

            if (!$payout) {
                wp_send_json_error(['message' => 'Payout não encontrado.'], 404);
                return;
            }

            if (!in_array($payout['status'], ['approved', 'pending'], true)) {
                wp_send_json_error([
                    'message' => sprintf('Payout não pode ser autorizado no status atual (%s).', $payout['status']),
                ], 400);
                return;
            }

            // Atualizar para authorized
            $updated = $wpdb->update(
                $table,
                [
                    'status'        => 'authorized',
                    'authorized_by' => get_current_user_id(),
                    'authorized_at' => current_time('mysql'),
                ],
                ['id' => $payoutId],
                ['%s', '%d', '%s'],
                ['%d']
            );

            if ($updated === false) {
                wp_send_json_error(['message' => 'Erro ao atualizar banco de dados.'], 500);
                return;
            }

            // Log
            if (function_exists('limpvix_log_event')) {
                limpvix_log_event('payout_authorized', [
                    'payout_id'     => $payoutId,
                    'authorized_by' => get_current_user_id(),
                ]);
            }

            wp_send_json_success([
                'message'    => sprintf('Payout #%d autorizado com sucesso. Aguardando processamento pelo financeiro.', $payoutId),
                'payout_id'  => $payoutId,
                'new_status' => 'authorized',
            ]);

        } catch (\Exception $e) {
            error_log('AdminActionsController::handleAuthorizePayout error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Erro interno: ' . $e->getMessage()], 500);
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
