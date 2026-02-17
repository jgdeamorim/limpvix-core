<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Admin\Ajax;

use LimpVix\Application\UseCases\Financial\CreateManualPayout;
use LimpVix\Application\UseCases\Financial\CreateManualPayoutCommand;
use LimpVix\Application\UseCases\Financial\ApproveManualPayout;
use LimpVix\Domain\Finance\PayoutRepositoryInterface;
use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;

/**
 * AJAX Handler for Manual Payout Operations (GAP C)
 *
 * Handles:
 * - wp_ajax_limpvix_create_manual_payout
 * - wp_ajax_limpvix_approve_manual_payout
 * - wp_ajax_limpvix_reject_manual_payout
 *
 * @package LimpVix\Infrastructure\Admin\Ajax
 * @since 0.9.0 (GAP C)
 */
final class ManualPayoutAjaxHandler
{
    private CreateManualPayout $createUseCase;
    private ApproveManualPayout $approveUseCase;

    public function __construct()
    {
        // Get repository from container
        $container = \LimpVix\Core\ServiceContainer::getInstance();

        $payoutRepository = $container->has('payout_repository')
            ? $container->get('payout_repository')
            : new \LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository();

        $professionalRepository = $container->has('professional_repository')
            ? $container->get('professional_repository')
            : new WpMarketplaceProfessionalRepository();

        $this->createUseCase = new CreateManualPayout($payoutRepository, $professionalRepository);
        $this->approveUseCase = new ApproveManualPayout($payoutRepository);
    }

    /**
     * Register AJAX handlers
     */
    public function register(): void
    {
        add_action('wp_ajax_limpvix_create_manual_payout', [$this, 'handleCreate']);
        add_action('wp_ajax_limpvix_approve_manual_payout', [$this, 'handleApprove']);
        add_action('wp_ajax_limpvix_reject_manual_payout', [$this, 'handleReject']);
    }

    /**
     * Handle create manual payout AJAX request
     */
    public function handleCreate(): void
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'limpvix_manual_payout')) {
            wp_send_json_error([
                'message' => 'Nonce verification failed',
            ], 403);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'Access denied',
            ], 403);
        }

        // Validate required fields
        $professional_id = isset($_POST['professional_id']) ? (int) $_POST['professional_id'] : 0;
        $amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';
        $deduct_fee = isset($_POST['deduct_fee']) ? (bool) $_POST['deduct_fee'] : true;

        if ($professional_id <= 0) {
            wp_send_json_error([
                'message' => 'ID do profissional é obrigatório',
            ], 400);
        }

        if ($amount <= 0) {
            wp_send_json_error([
                'message' => 'Valor deve ser maior que zero',
            ], 400);
        }

        if (empty(trim($reason))) {
            wp_send_json_error([
                'message' => 'Motivo é obrigatório',
            ], 400);
        }

        try {
            // Create command
            $command = new CreateManualPayoutCommand(
                professional_id: $professional_id,
                amount: $amount,
                reason: $reason,
                created_by: get_current_user_id(),
                deduct_fee: $deduct_fee
            );

            // Execute use case
            $result = $this->createUseCase->execute($command);

            if ($result['success']) {
                wp_send_json_success([
                    'message' => $result['requires_approval']
                        ? 'Payout criado com sucesso. Aguardando aprovação de outro admin.'
                        : 'Payout criado e aprovado automaticamente (valor < R$ 500).',
                    'payout_id' => $result['payout_id'],
                    'requires_approval' => $result['requires_approval'],
                    'net_amount' => $result['net_amount'],
                ]);
            } else {
                wp_send_json_error([
                    'message' => $result['error'],
                ], 400);
            }

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'Internal server error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle approve manual payout AJAX request
     */
    public function handleApprove(): void
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'limpvix_manual_payout')) {
            wp_send_json_error([
                'message' => 'Nonce verification failed',
            ], 403);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'Access denied',
            ], 403);
        }

        // Get payout ID
        $payout_id = isset($_POST['payout_id']) ? (int) $_POST['payout_id'] : 0;

        if (!$payout_id) {
            wp_send_json_error([
                'message' => 'Payout ID is required',
            ], 400);
        }

        try {
            $result = $this->approveUseCase->approve($payout_id, get_current_user_id());

            if ($result['success']) {
                wp_send_json_success([
                    'message' => $result['message'],
                ]);
            } else {
                wp_send_json_error([
                    'message' => $result['error'],
                ], 400);
            }

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'Internal server error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle reject manual payout AJAX request
     */
    public function handleReject(): void
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'limpvix_manual_payout')) {
            wp_send_json_error([
                'message' => 'Nonce verification failed',
            ], 403);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'Access denied',
            ], 403);
        }

        // Get payout ID and reason
        $payout_id = isset($_POST['payout_id']) ? (int) $_POST['payout_id'] : 0;
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';

        if (!$payout_id) {
            wp_send_json_error([
                'message' => 'Payout ID is required',
            ], 400);
        }

        if (empty(trim($reason))) {
            wp_send_json_error([
                'message' => 'Motivo da rejeição é obrigatório',
            ], 400);
        }

        try {
            $result = $this->approveUseCase->reject($payout_id, get_current_user_id(), $reason);

            if ($result['success']) {
                wp_send_json_success([
                    'message' => $result['message'],
                ]);
            } else {
                wp_send_json_error([
                    'message' => $result['error'],
                ], 400);
            }

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'Internal server error: ' . $e->getMessage(),
            ], 500);
        }
    }
}
