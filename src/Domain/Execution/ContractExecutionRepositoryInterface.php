<?php
/**
 * ContractExecutionRepositoryInterface - Contrato do Repository
 *
 * @package LimpVix\Domain\Execution
 * @since 0.9.0
 */

namespace LimpVix\Domain\Execution;

defined('ABSPATH') || exit;

interface ContractExecutionRepositoryInterface
{
    /**
     * Salvar execução (insert ou update)
     *
     * @param ContractExecution $execution
     * @return void
     */
    public function save(ContractExecution $execution): void;

    /**
     * Buscar execução por ID
     *
     * @param ExecutionId $id
     * @return ContractExecution|null
     */
    public function findById(ExecutionId $id): ?ContractExecution;

    /**
     * Buscar todas as execuções de um contrato
     *
     * @param \LimpVix\Domain\Contract\ContractId $contractId
     * @return array
     */
    public function findByContractId(\LimpVix\Domain\Contract\ContractId $contractId): array;

    /**
     * Buscar execuções de um professional
     *
     * @param int $professionalUserId
     * @return array
     */
    public function findByProfessionalId(int $professionalUserId): array;

    /**
     * Buscar execuções agendadas em um período
     *
     * @param \DateTimeImmutable $startDate
     * @param \DateTimeImmutable $endDate
     * @return array
     */
    public function findScheduledBetween(\DateTimeImmutable $startDate, \DateTimeImmutable $endDate): array;
}
