<?php
declare(strict_types=1);

/**
 * ExecutionRepositoryInterface - Port for Execution persistence (Sprint 1 - Dia 5)
 *
 * RESPONSABILIDADE:
 * - Definir contrato de persistência para Execution Aggregate
 * - Isolar Domain de detalhes de infraestrutura
 *
 * PRINCÍPIOS:
 * - Interface pura (sem implementação)
 * - Sem dependências de WordPress/Database
 * - Retorna Aggregate hidratado completamente
 *
 * @package LimpVix\Domain\Execution
 */

namespace LimpVix\Domain\Execution;

defined('ABSPATH') || exit;

interface ExecutionRepositoryInterface
{
    /**
     * Salvar Execution (insert ou update)
     *
     * @param Execution $execution Aggregate para persistir
     * @return void
     * @throws \RuntimeException Se falhar ao salvar
     */
    public function save(Execution $execution): void;

    /**
     * Buscar Execution por UUID
     *
     * @param string $uuid UUID da Execution
     * @return Execution|null Execution hidratado ou null se não encontrado
     */
    public function findByUuid(string $uuid): ?Execution;

    /**
     * Buscar Execution por Order UUID
     *
     * @param string $orderUuid UUID da Order
     * @return Execution|null Execution hidratado ou null se não encontrado
     */
    public function findByOrderUuid(string $orderUuid): ?Execution;

    /**
     * Verificar se Execution existe
     *
     * @param string $uuid UUID da Execution
     * @return bool True se existe
     */
    public function exists(string $uuid): bool;

    /**
     * Deletar Execution
     *
     * @param string $uuid UUID da Execution
     * @return void
     * @throws \RuntimeException Se falhar ao deletar
     */
    public function delete(string $uuid): void;
}
