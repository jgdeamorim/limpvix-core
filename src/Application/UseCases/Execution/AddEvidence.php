<?php
/**
 * AddEvidence - Use Case para Adicionar Evidências a uma Execution
 *
 * RESPONSABILIDADE:
 * - Buscar Execution via Repository
 * - Validar que execution existe
 * - Validar tipo de evidência (photo ou video)
 * - Adicionar nova evidência à coleção existente
 * - Persistir mudanças
 *
 * REGRAS DE NEGÓCIO:
 * - Execution deve existir
 * - Evidência deve ser photo ou video
 * - URL deve ser válida
 * - Pode adicionar múltiplas evidências
 * - Não há limite de evidências
 *
 * @package LimpVix\Application\UseCases\Execution
 * @since 0.12.0 (GAP #5)
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Common\Result;

defined('ABSPATH') || exit;

class AddEvidence
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
     * @param string $type Tipo de evidência (photo ou video)
     * @param string $url URL da evidência
     * @param string $category Categoria (default: location)
     * @param int|null $uploadedBy User ID (default: current user)
     * @param string|null $stage Estágio (check_in, execution, check_out)
     * @return Result<array{execution_id: int, evidence_count: int, new_evidence: array}>
     */
    public function execute(
        int $executionId,
        string $type,
        string $url,
        string $category = 'location',
        ?int $uploadedBy = null,
        ?string $stage = null
    ): Result
    {
        $table = $this->wpdb->prefix . 'limpvix_executions';

        // 1. Validar tipo
        if (!in_array($type, ['photo', 'video'], true)) {
            return Result::fail('Invalid evidence type. Must be "photo" or "video"');
        }

        // 2. Validar URL
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return Result::fail('Invalid evidence URL');
        }

        // 3. Buscar execution
        $execution = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $executionId
        ), ARRAY_A);

        if (!$execution) {
            return Result::fail("Execution #{$executionId} not found");
        }

        // 4. Decodificar evidências existentes
        $currentEvidence = !empty($execution['evidence'])
            ? json_decode($execution['evidence'], true)
            : [];

        if (!is_array($currentEvidence)) {
            $currentEvidence = [];
        }

        // 5. Criar nova evidência (com novos campos GAP #2)
        $newEvidence = [
            'type' => $type,
            'url' => $url,
            'uploadedAt' => current_time('mysql'),
            'category' => $category,
            'uploadedBy' => $uploadedBy ?? get_current_user_id(),
            'stage' => $stage,
            'status' => 'pending',
        ];

        // 6. Adicionar à coleção
        $currentEvidence[] = $newEvidence;

        // 7. Atualizar no banco
        $updated = $this->wpdb->update(
            $table,
            [
                'evidence' => json_encode($currentEvidence),
            ],
            ['id' => $executionId],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            return Result::fail('Failed to add evidence');
        }

        // 8. Despachar evento
        do_action('limpvix_evidence_added', $executionId, $type, $url);

        // 9. Retornar sucesso
        return Result::ok([
            'execution_id' => $executionId,
            'evidence_count' => count($currentEvidence),
            'new_evidence' => $newEvidence,
            'message' => 'Evidence added successfully',
        ]);
    }

    /**
     * Add multiple evidences at once
     *
     * @param int $executionId
     * @param array $evidences Array of ['type' => 'photo|video', 'url' => '...']
     * @return Result<array>
     */
    public function executeMultiple(int $executionId, array $evidences): Result
    {
        $table = $this->wpdb->prefix . 'limpvix_executions';

        // 1. Validar array não vazio
        if (empty($evidences)) {
            return Result::fail('No evidences provided');
        }

        // 2. Validar cada evidência
        foreach ($evidences as $evidence) {
            if (!isset($evidence['type']) || !isset($evidence['url'])) {
                return Result::fail('Each evidence must have "type" and "url"');
            }

            if (!in_array($evidence['type'], ['photo', 'video'], true)) {
                return Result::fail('Invalid evidence type: ' . $evidence['type']);
            }

            if (!filter_var($evidence['url'], FILTER_VALIDATE_URL)) {
                return Result::fail('Invalid evidence URL: ' . $evidence['url']);
            }
        }

        // 3. Buscar execution
        $execution = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $executionId
        ), ARRAY_A);

        if (!$execution) {
            return Result::fail("Execution #{$executionId} not found");
        }

        // 4. Decodificar evidências existentes
        $currentEvidence = !empty($execution['evidence'])
            ? json_decode($execution['evidence'], true)
            : [];

        if (!is_array($currentEvidence)) {
            $currentEvidence = [];
        }

        // 5. Adicionar novas evidências com timestamp
        $uploadedAt = current_time('mysql');
        foreach ($evidences as $evidence) {
            $currentEvidence[] = [
                'type' => $evidence['type'],
                'url' => $evidence['url'],
                'uploadedAt' => $uploadedAt,
            ];
        }

        // 6. Atualizar no banco
        $updated = $this->wpdb->update(
            $table,
            [
                'evidence' => json_encode($currentEvidence),
            ],
            ['id' => $executionId],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            return Result::fail('Failed to add evidences');
        }

        // 7. Retornar sucesso
        return Result::ok([
            'execution_id' => $executionId,
            'evidence_count' => count($currentEvidence),
            'added_count' => count($evidences),
            'message' => sprintf('%d evidence(s) added successfully', count($evidences)),
        ]);
    }
}
