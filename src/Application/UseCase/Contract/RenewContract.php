<?php
/**
 * RenewContract - Use Case para renovar contrato
 *
 * RESPONSABILIDADE:
 * - Renovar contrato expirado/completado (se auto_renew = true)
 * - Transição: COMPLETED/CANCELLED → ACTIVE
 * - Recalcular próxima execução
 * - Persistir mudanças
 *
 * @package LimpVix\Application\UseCase\Contract
 * @since 0.8.0
 */

namespace LimpVix\Application\UseCase\Contract;

use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\Exceptions\ContractNotFoundException;

defined('ABSPATH') || exit;

final class RenewContract
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
     * @param \DateTimeImmutable $newEndDate
     * @return void
     * @throws ContractNotFoundException
     * @throws \LimpVix\Domain\Contract\Exceptions\InvalidContractTransition
     */
    public function execute(int $contractId, \DateTimeImmutable $newEndDate): void
    {
        // Buscar contrato
        $contract = $this->repository->findById(ContractId::fromInt($contractId));

        if (!$contract) {
            throw new ContractNotFoundException("Contract ID: {$contractId}");
        }

        // Renovar (Domain valida se auto_renew = true)
        $contract->renew($newEndDate);

        // Persistir
        $this->repository->save($contract);

        // Opcional: Dispatch events
        $events = $contract->releaseEvents();
        // TODO: Implementar Event Dispatcher
    }
}
