<?php
/**
 * ScheduleNextExecution - Use Case para agendar próxima execução
 *
 * RESPONSABILIDADE:
 * - Atualizar próxima data de execução após execução concluída
 * - Validar que contrato está ativo
 * - Persistir mudanças
 *
 * NOTA: Este Use Case é chamado após conclusão de uma execução de contrato
 *
 * @package LimpVix\Application\UseCases\Contract
 * @since 0.8.0
 */

namespace LimpVix\Application\UseCases\Contract;

use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\Exceptions\ContractNotFoundException;

defined('ABSPATH') || exit;

final class ScheduleNextExecution
{
    private ContractRepositoryInterface $repository;

    public function __construct(ContractRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Executar use case
     *
     * @param int $contractId
     * @return void
     * @throws ContractNotFoundException
     * @throws \LimpVix\Domain\Contract\Exceptions\InvalidContractTransition
     */
    public function execute(int $contractId): void
    {
        // Buscar contrato
        $contract = $this->repository->findById(ContractId::fromInt($contractId));

        if (!$contract) {
            throw new ContractNotFoundException("Contract ID: {$contractId}");
        }

        // Agendar próxima execução (Domain valida se está ativo)
        $contract->scheduleNextExecution();

        // Persistir
        $this->repository->save($contract);
    }
}
