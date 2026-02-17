<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Financial;

use LimpVix\Domain\Finance\PayoutRepositoryInterface;

/**
 * Approve Manual Payout Use Case (GAP C)
 *
 * Permite segundo admin aprovar payout manual criado por outro admin.
 *
 * BUSINESS RULES:
 * - Aprovador ≠ Criador (4-eyes policy)
 * - Apenas payouts com status 'manual_pending' podem ser aprovados
 * - Após aprovação, status muda para 'approved' (pronto para processar)
 * - Payout aprovado entra na fila normal de processamento
 *
 * AUDIT TRAIL:
 * - Registra quem aprovou, quando
 * - Salva em wp_limpvix_payout_audit_trail
 *
 * @package LimpVix\Application\UseCases\Financial
 * @since 0.9.0 (GAP C)
 */
final class ApproveManualPayout
{
    public function __construct(
        private PayoutRepositoryInterface $payoutRepository
    ) {}

    /**
     * Approve manual payout
     *
     * @param int $payout_id
     * @param int $approved_by User ID do admin aprovador
     * @return array{success: bool, error?: string}
     */
    public function approve(int $payout_id, int $approved_by): array
    {
        // 1. Fetch payout
        $payout = $this->payoutRepository->getById($payout_id);

        if (!$payout) {
            return [
                'success' => false,
                'error' => 'Payout não encontrado (ID: ' . $payout_id . ')',
            ];
        }

        // 2. Validate is manual payout
        if (!$payout['is_manual']) {
            return [
                'success' => false,
                'error' => 'Este payout não é manual. Use o fluxo automático.',
            ];
        }

        // 3. Validate status is manual_pending
        if ($payout['status'] !== 'manual_pending') {
            return [
                'success' => false,
                'error' => 'Payout não está aguardando aprovação (status: ' . $payout['status'] . ')',
            ];
        }

        // 4. Validate 4-eyes policy (approver ≠ creator)
        if ($payout['created_by'] === $approved_by) {
            return [
                'success' => false,
                'error' => 'Você não pode aprovar um payout criado por você mesmo (4-eyes policy)',
            ];
        }

        // 5. Update payout status to 'approved'
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_payouts';

        $updated = $wpdb->update(
            $table,
            [
                'status' => 'approved',
                'approved_by' => $approved_by,
                'approved_manually_at' => current_time('mysql'),
                'approved_at' => current_time('mysql'), // Também preencher approved_at geral
            ],
            ['id' => $payout_id],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return [
                'success' => false,
                'error' => 'Erro ao atualizar status do payout no banco',
            ];
        }

        // 6. Create audit trail
        $this->createAuditTrail($payout_id, 'approved', $approved_by);

        // 7. Notify creator and professional
        $this->notifyApproval($payout_id, $payout, $approved_by);

        return [
            'success' => true,
            'message' => 'Payout aprovado com sucesso. Será processado automaticamente.',
        ];
    }

    /**
     * Reject manual payout
     *
     * @param int $payout_id
     * @param int $rejected_by User ID do admin que rejeitou
     * @param string $reason Motivo da rejeição (obrigatório)
     * @return array{success: bool, error?: string}
     */
    public function reject(int $payout_id, int $rejected_by, string $reason): array
    {
        // 1. Validate reason
        if (empty(trim($reason))) {
            return [
                'success' => false,
                'error' => 'Motivo da rejeição é obrigatório',
            ];
        }

        // 2. Fetch payout
        $payout = $this->payoutRepository->getById($payout_id);

        if (!$payout) {
            return [
                'success' => false,
                'error' => 'Payout não encontrado (ID: ' . $payout_id . ')',
            ];
        }

        // 3. Validate is manual payout
        if (!$payout['is_manual']) {
            return [
                'success' => false,
                'error' => 'Este payout não é manual',
            ];
        }

        // 4. Validate status
        if ($payout['status'] !== 'manual_pending') {
            return [
                'success' => false,
                'error' => 'Payout não está aguardando aprovação',
            ];
        }

        // 5. Update status to 'cancelled'
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_payouts';

        $updated = $wpdb->update(
            $table,
            [
                'status' => 'cancelled',
                'failure_reason' => 'Rejeitado por admin: ' . $reason,
            ],
            ['id' => $payout_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return [
                'success' => false,
                'error' => 'Erro ao atualizar status do payout',
            ];
        }

        // 6. Create audit trail
        $this->createAuditTrail($payout_id, 'rejected', $rejected_by, $reason);

        // 7. Notify creator
        $this->notifyRejection($payout_id, $payout, $rejected_by, $reason);

        return [
            'success' => true,
            'message' => 'Payout rejeitado com sucesso',
        ];
    }

    /**
     * Create audit trail entry
     */
    private function createAuditTrail(
        int $payout_id,
        string $action,
        int $performed_by,
        ?string $reason = null
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_payout_audit_trail';

        $wpdb->insert(
            $table,
            [
                'payout_id' => $payout_id,
                'action' => $action,
                'performed_by' => $performed_by,
                'reason' => $reason,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%s', '%s']
        );
    }

    /**
     * Notify about approval
     */
    private function notifyApproval(int $payout_id, array $payout, int $approved_by): void
    {
        $approver = get_userdata($approved_by);
        $creator = get_userdata($payout['created_by']);

        // Notify creator
        if ($creator) {
            $subject = '[LimpVix] Payout Manual Aprovado';
            $message = sprintf(
                "Seu payout manual foi aprovado:\n\n" .
                "ID: #%d\n" .
                "Valor: R$ %.2f\n" .
                "Profissional ID: %d\n" .
                "Aprovado por: %s\n\n" .
                "O payout será processado automaticamente em breve.",
                $payout_id,
                $payout['net_amount'],
                $payout['professional_id'],
                $approver->display_name
            );

            wp_mail($creator->user_email, $subject, $message);
        }

        // TODO: Notify professional via SMS/Email
    }

    /**
     * Notify about rejection
     */
    private function notifyRejection(
        int $payout_id,
        array $payout,
        int $rejected_by,
        string $reason
    ): void {
        $rejecter = get_userdata($rejected_by);
        $creator = get_userdata($payout['created_by']);

        if ($creator) {
            $subject = '[LimpVix] Payout Manual Rejeitado';
            $message = sprintf(
                "Seu payout manual foi rejeitado:\n\n" .
                "ID: #%d\n" .
                "Valor: R$ %.2f\n" .
                "Profissional ID: %d\n" .
                "Rejeitado por: %s\n" .
                "Motivo: %s\n\n" .
                "Se necessário, você pode criar um novo payout com os ajustes solicitados.",
                $payout_id,
                $payout['net_amount'],
                $payout['professional_id'],
                $rejecter->display_name,
                $reason
            );

            wp_mail($creator->user_email, $subject, $message);
        }
    }
}
