<?php
/**
 * PauseContract - Use Case para pausar contrato
 *
 * RESPONSABILIDADE:
 * - Transição: ACTIVE → PAUSED
 * - Persistir mudanças
 * - Disparar evento ContractPaused
 *
 * @package LimpVix\Application\UseCase\Contract
 * @since 0.8.0
 */

namespace LimpVix\Application\UseCase\Contract;

use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\Exceptions\ContractNotFoundException;

defined('ABSPATH') || exit;

final class PauseContract
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

        // Transição de estado
        $contract->pause($reason);

        // Persistir
        $this->repository->save($contract);

        // Opcional: Dispatch events
        $events = $contract->releaseEvents();
        // TODO: Implementar Event Dispatcher
    }
}
