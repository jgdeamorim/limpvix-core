<?php
/**
 * CompleteContract - Use Case para completar contrato
 *
 * RESPONSABILIDADE:
 * - Transição: ACTIVE → COMPLETED (terminal)
 * - Limpar próxima execução
 * - Persistir mudanças
 * - Disparar evento ContractCompleted
 *
 * @package LimpVix\Application\UseCase\Contract
 * @since 0.8.0
 */

namespace LimpVix\Application\UseCase\Contract;

use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\Exceptions\ContractNotFoundException;
use LimpVix\Infrastructure\Events\WordPressEventDispatcher;

defined('ABSPATH') || exit;

final class CompleteContract
{
    private ContractRepositoryInterface $repository;
    private WordPressEventDispatcher $eventDispatcher;

    public function __construct(
        ContractRepositoryInterface $repository,
        WordPressEventDispatcher $eventDispatcher
    ) {
        $this->repository = $repository;
        $this->eventDispatcher = $eventDispatcher;
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

        // Transição de estado (terminal)
        $contract->complete();

        // Persistir
        $this->repository->save($contract);

        // Dispatch events
        $events = $contract->releaseEvents();
        $this->eventDispatcher->dispatchAll($events);
    }
}
