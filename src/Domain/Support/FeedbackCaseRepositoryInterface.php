<?php
/**
 * FeedbackCaseRepositoryInterface
 *
 * Interface para persistência de casos de feedback negativo C2.
 *
 * @package LimpVix\Domain\Support
 * @since 0.1.4
 */

namespace LimpVix\Domain\Support;

defined('ABSPATH') || exit;

interface FeedbackCaseRepositoryInterface
{
    /**
     * Salvar status de um caso
     *
     * @param int $orderId ID do pedido WooCommerce
     * @param FeedbackCaseStatus $status Status do caso
     * @param string|null $resolvedAt Data de resolução (formato MySQL: Y-m-d H:i:s)
     * @return bool Sucesso da operação
     */
    public function saveStatus(int $orderId, FeedbackCaseStatus $status, ?string $resolvedAt = null): bool;

    /**
     * Buscar status de um caso
     *
     * @param int $orderId ID do pedido
     * @return array|null ['status' => string, 'resolved_at' => string|null]
     */
    public function getStatus(int $orderId): ?array;

    /**
     * Verificar se um caso existe
     *
     * @param int $orderId ID do pedido
     * @return bool
     */
    public function exists(int $orderId): bool;
}
