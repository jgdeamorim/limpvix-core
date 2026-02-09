<?php
/**
 * ResumeContract - Use Case para retomar contrato pausado
 *
 * RESPONSABILIDADE:
 * - Transição: PAUSED → ACTIVE
 * - Recalcular próxima data de execução
 * - Persistir mudanças
 * - Disparar evento ContractResumed
 *
 * @package LimpVix\Application\UseCase\Contract
 * @since 0.8.0
 */

namespace LimpVix\Application\UseCase\Contract;

use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\Exceptions\ContractNotFoundException;

defined('ABSPATH') || exit;

final class ResumeContract
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

        // Transição de estado
        $contract->resume();

        // Persistir
        $this->repository->save($contract);

        // Opcional: Dispatch events
        $events = $contract->releaseEvents();
        // TODO: Implementar Event Dispatcher
    }
}
