<?php
/**
 * CancelContract - Use Case para cancelar contrato
 *
 * RESPONSABILIDADE:
 * - Transição: * → CANCELLED (terminal)
 * - Limpar próxima execução
 * - Persistir mudanças
 * - Disparar evento ContractCancelled
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

final class CancelContract
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
     * @param string $reason
     * @return void
     * @throws ContractNotFoundException
     * @throws \LimpVix\Domain\Contract\Exceptions\InvalidContractTransition
     */
    public function execute(int $contractId, string $reason = ''): void
    {
        // Buscar contrato
        $contract = $this->repository->findById(ContractId::fromInt($contractId));

        if (!$contract) {
            throw new ContractNotFoundException("Contract ID: {$contractId}");
        }

        // Transição de estado (terminal)
        $contract->cancel($reason);

        // Persistir
        $this->repository->save($contract);

        // Dispatch events
        $events = $contract->releaseEvents();
        $this->eventDispatcher->dispatchAll($events);
    }
}
