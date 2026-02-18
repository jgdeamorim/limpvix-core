<?php
/**
 * ExpireContract - Use Case para expirar contratos
 *
 * RESPONSABILIDADE:
 * - Marcar contratos como expirados (end_date passou)
 * - Transição: * → CANCELLED (por expiração)
 * - Persistir mudanças
 * - Disparar evento ContractExpired
 *
 * NOTA: Este Use Case é executado por um Cron Job
 *
 * @package LimpVix\Application\UseCases\Contract
 * @since 0.8.0
 */

namespace LimpVix\Application\UseCases\Contract;

use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Infrastructure\Events\WordPressEventDispatcher;

defined('ABSPATH') || exit;

final class ExpireContract
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
     * Executar use case - Expirar contratos até uma data
     *
     * @param \DateTimeImmutable|null $date Data de referência (default: hoje)
     * @return int Número de contratos expirados
     */
    public function execute(?\DateTimeImmutable $date = null): int
    {
        $date = $date ?? new \DateTimeImmutable();

        // Buscar contratos expirando
        $expiringContracts = $this->repository->findExpiringContracts($date);

        $expiredCount = 0;

        foreach ($expiringContracts as $contract) {
            // Validar se realmente expirou
            if ($contract->hasExpired()) {
                $contract->expire();
                $this->repository->save($contract);

                // Dispatch events
                $events = $contract->releaseEvents();
                $this->eventDispatcher->dispatchAll($events);

                $expiredCount++;
            }
        }

        return $expiredCount;
    }
}
