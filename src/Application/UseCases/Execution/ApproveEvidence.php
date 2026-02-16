<?php
/**
 * ApproveEvidence - Use Case para Aprovar Evidências de Execução
 *
 * RESPONSABILIDADE:
 * - Validar que execution existe e tem evidências
 * - Aprovar evidências (mudar status para 'approved')
 * - Registrar quem aprovou e quando
 * - Despachar evento EvidenceApproved
 * - Liberar payout (se feedback window expirado)
 *
 * REGRAS DE NEGÓCIO:
 * - Apenas admin pode aprovar evidências
 * - Evidências já aprovadas não podem ser aprovadas novamente
 * - Evidências rejeitadas podem ser re-aprovadas (segunda chance)
 * - Approval libera o payout se feedback window já expirou
 *
 * @package LimpVix\Application\UseCases\Execution
 * @since 0.11.0 (GAP #4)
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Common\Result;

defined('ABSPATH') || exit;

class ApproveEvidence
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
     * @param int $adminUserId User ID do admin que está aprovando
     * @return Result<array{execution_id: int, status: string}>
     * @throws \RuntimeException Se execution não encontrada
     */
    public function execute(int $executionId, int $adminUserId): Result
    {
        $table = $this->wpdb->prefix . 'limpvix_executions';

        // 1. Buscar execution
        $execution = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $executionId
        ), ARRAY_A);

        if (!$execution) {
            return Result::fail("Execution #{$executionId} not found");
        }

        // 2. Validar que tem evidências
        if (empty($execution['evidence'])) {
            return Result::fail('Execution has no evidence to approve');
        }

        // 3. Verificar se já está aprovado
        if ($execution['evidence_status'] === 'approved') {
            return Result::ok([
                'execution_id' => $executionId,
                'status' => 'already_approved',
                'message' => 'Evidence was already approved',
                'approved_at' => $execution['evidence_validated_at'],
                'approved_by' => $execution['evidence_validated_by'],
            ]);
        }

        // 4. Aprovar evidências
        $updated = $this->wpdb->update(
            $table,
            [
                'evidence_status' => 'approved',
                'evidence_validated_at' => current_time('mysql'),
                'evidence_validated_by' => $adminUserId,
                'evidence_rejection_reason' => null, // Limpar rejection reason se existir
            ],
            ['id' => $executionId],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return Result::fail('Failed to update evidence status');
        }

        // 5. Despachar evento
        do_action('limpvix_evidence_approved', $executionId, $adminUserId);

        // 6. Retornar sucesso
        return Result::ok([
            'execution_id' => $executionId,
            'status' => 'approved',
            'message' => 'Evidence approved successfully',
            'approved_at' => current_time('mysql'),
            'approved_by' => $adminUserId,
        ]);
    }
}
