<?php
/**
 * RejectEvidence - Use Case para Rejeitar Evidências de Execução
 *
 * RESPONSABILIDADE:
 * - Validar que execution existe e tem evidências
 * - Rejeitar evidências (mudar status para 'rejected')
 * - Registrar motivo da rejeição
 * - Registrar quem rejeitou e quando
 * - Despachar evento EvidenceRejected
 * - Notificar professional sobre rejeição
 * - Bloquear payout até re-submission de evidências
 *
 * REGRAS DE NEGÓCIO:
 * - Apenas admin pode rejeitar evidências
 * - Motivo da rejeição é obrigatório (feedback ao professional)
 * - Evidências rejeitadas bloqueiam payout
 * - Professional pode re-submeter evidências (novo check-out)
 * - Rejection notifica professional via email/push
 *
 * @package LimpVix\Application\UseCases\Execution
 * @since 0.11.0 (GAP #4)
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Common\Result;

defined('ABSPATH') || exit;

class RejectEvidence
{
    private \wpdb $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Execute use case
     *
     * @param int $executionId Execution ID
     * @param int $adminUserId User ID do admin que está rejeitando
     * @param string $rejectionReason Motivo da rejeição (feedback para professional)
     * @return Result<array{execution_id: int, status: string, rejection_reason: string}>
     * @throws \RuntimeException Se execution não encontrada
     */
    public function execute(int $executionId, int $adminUserId, string $rejectionReason): Result
    {
        // 1. Validar rejection reason
        $rejectionReason = trim($rejectionReason);
        if (empty($rejectionReason)) {
            return Result::fail('Rejection reason is required');
        }

        if (strlen($rejectionReason) < 10) {
            return Result::fail('Rejection reason must be at least 10 characters');
        }

        $table = $this->wpdb->prefix . 'limpvix_executions';

        // 2. Buscar execution
        $execution = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $executionId
        ), ARRAY_A);

        if (!$execution) {
            return Result::fail("Execution #{$executionId} not found");
        }

        // 3. Validar que tem evidências
        if (empty($execution['evidence'])) {
            return Result::fail('Execution has no evidence to reject');
        }

        // 4. Verificar se já está rejeitado com mesmo motivo
        if ($execution['evidence_status'] === 'rejected' 
            && $execution['evidence_rejection_reason'] === $rejectionReason) {
            return Result::ok([
                'execution_id' => $executionId,
                'status' => 'already_rejected',
                'message' => 'Evidence was already rejected with same reason',
                'rejection_reason' => $rejectionReason,
                'rejected_at' => $execution['evidence_validated_at'],
                'rejected_by' => $execution['evidence_validated_by'],
            ]);
        }

        // 5. Rejeitar evidências
        $updated = $this->wpdb->update(
            $table,
            [
                'evidence_status' => 'rejected',
                'evidence_validated_at' => current_time('mysql'),
                'evidence_validated_by' => $adminUserId,
                'evidence_rejection_reason' => $rejectionReason,
            ],
            ['id' => $executionId],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return Result::fail('Failed to update evidence status');
        }

        // 6. Despachar evento (para notificar professional)
        do_action('limpvix_evidence_rejected', $executionId, $adminUserId, $rejectionReason);

        // 7. Retornar sucesso
        return Result::ok([
            'execution_id' => $executionId,
            'status' => 'rejected',
            'message' => 'Evidence rejected successfully',
            'rejection_reason' => $rejectionReason,
            'rejected_at' => current_time('mysql'),
            'rejected_by' => $adminUserId,
        ]);
    }
}
