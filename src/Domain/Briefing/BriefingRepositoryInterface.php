<?php
/**
 * BriefingRepositoryInterface
 *
 * Interface para persistência de Briefings.
 *
 * RESPONSABILIDADE:
 * - Definir contrato de persistência do Aggregate Root Briefing
 * - Abstrair detalhes de implementação (WordPress, MySQL, etc)
 * - Permitir testes unitários com mocks
 *
 * IMPLEMENTAÇÃO:
 * - WpBriefingRepository (FASE 3)
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

interface BriefingRepositoryInterface
{
    /**
     * Buscar Briefing por UUID
     *
     * @param string $uuid UUID do Briefing
     * @return Briefing|null
     */
    public function findByUuid(string $uuid): ?Briefing;

    /**
     * Buscar Briefing por Order ID
     *
     * @param int $orderId Order ID vinculada
     * @return Briefing|null
     */
    public function findByOrderId(int $orderId): ?Briefing;

    /**
     * Buscar Briefings por User ID
     *
     * @param int $userId WordPress user ID
     * @param int $limit Limite de resultados
     * @return array<Briefing>
     */
    public function findByUserId(int $userId, int $limit = 10): array;

    /**
     * Buscar Briefings por status
     *
     * @param BriefingStatus $status
     * @param int $limit Limite de resultados
     * @return array<Briefing>
     */
    public function findByStatus(BriefingStatus $status, int $limit = 100): array;

    /**
     * Salvar Briefing (insert ou update)
     *
     * @param Briefing $briefing
     * @return bool Sucesso da operação
     */
    public function save(Briefing $briefing): bool;

    /**
     * Verificar se Briefing existe
     *
     * @param string $uuid UUID do Briefing
     * @return bool
     */
    public function exists(string $uuid): bool;

    /**
     * Deletar Briefing (soft delete, apenas muda status)
     *
     * Nota: Briefings locked NÃO podem ser deletados
     *
     * @param string $uuid UUID do Briefing
     * @return bool Sucesso da operação
     */
    public function delete(string $uuid): bool;

    /**
     * Contar Briefings por status
     *
     * @param BriefingStatus|null $status Status específico (null = todos)
     * @return int
     */
    public function count(?BriefingStatus $status = null): int;
}
