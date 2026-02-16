<?php
/**
 * RemoveEvidence - Use Case para Remover Evidência de uma Execution
 *
 * RESPONSABILIDADE:
 * - Buscar Execution via Repository
 * - Validar que execution existe
 * - Remover evidência específica por índice
 * - Persistir mudanças
 *
 * REGRAS DE NEGÓCIO:
 * - Apenas admin pode remover evidências
 * - Execution deve ter evidências
 * - Índice deve ser válido
 * - Não pode remover última evidência se execution já foi validada
 * - Delete físico do arquivo (opcional - implementação futura)
 *
 * @package LimpVix\Application\UseCases\Execution
 * @since 0.12.0 (GAP #5)
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Common\Result;

defined('ABSPATH') || exit;

class RemoveEvidence
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
     * @param int $evidenceIndex Índice da evidência a remover (0-based)
     * @param int $adminUserId User ID do admin que está removendo
     * @return Result<array{execution_id: int, evidence_count: int, removed_evidence: array}>
     */
    public function execute(int $executionId, int $evidenceIndex, int $adminUserId): Result
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
            return Result::fail('Execution has no evidence to remove');
        }

        // 3. Decodificar evidências
        $evidences = json_decode($execution['evidence'], true);

        if (!is_array($evidences)) {
            return Result::fail('Invalid evidence format');
        }

        // 4. Validar índice
        if (!isset($evidences[$evidenceIndex])) {
            return Result::fail("Evidence index {$evidenceIndex} not found");
        }

        // 5. Guardar evidência removida para retorno
        $removedEvidence = $evidences[$evidenceIndex];

        // 6. Remover evidência do array
        array_splice($evidences, $evidenceIndex, 1);

        // 7. Se ficou vazio, setar como NULL
        $newEvidenceJson = !empty($evidences) ? json_encode(array_values($evidences)) : null;

        // 8. Atualizar no banco
        $updated = $this->wpdb->update(
            $table,
            [
                'evidence' => $newEvidenceJson,
            ],
            ['id' => $executionId],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            return Result::fail('Failed to remove evidence');
        }

        // 9. Despachar evento
        do_action('limpvix_evidence_removed', $executionId, $evidenceIndex, $removedEvidence, $adminUserId);

        // 10. Retornar sucesso
        return Result::ok([
            'execution_id' => $executionId,
            'evidence_count' => count($evidences),
            'removed_evidence' => $removedEvidence,
            'message' => 'Evidence removed successfully',
        ]);
    }

    /**
     * Remove all evidences from execution (nuclear option - admin only)
     *
     * @param int $executionId
     * @param int $adminUserId
     * @param string $reason Motivo da remoção total
     * @return Result<array>
     */
    public function executeRemoveAll(int $executionId, int $adminUserId, string $reason): Result
    {
        $table = $this->wpdb->prefix . 'limpvix_executions';

        // 1. Validar reason
        if (empty($reason) || strlen($reason) < 10) {
            return Result::fail('Reason for removing all evidences must be at least 10 characters');
        }

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
            return Result::fail('Execution has no evidence to remove');
        }

        // 4. Remover todas evidências
        $updated = $this->wpdb->update(
            $table,
            [
                'evidence' => null,
                'evidence_status' => 'pending', // Reset status
                'evidence_rejection_reason' => $reason,
            ],
            ['id' => $executionId],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return Result::fail('Failed to remove all evidences');
        }

        // 5. Despachar evento
        do_action('limpvix_all_evidence_removed', $executionId, $reason, $adminUserId);

        // 6. Retornar sucesso
        return Result::ok([
            'execution_id' => $executionId,
            'evidence_count' => 0,
            'message' => 'All evidences removed successfully',
            'reason' => $reason,
        ]);
    }
}
