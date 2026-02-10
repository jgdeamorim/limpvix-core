<?php
/**
 * ActivateContract - Use Case para ativar contrato
 *
 * RESPONSABILIDADE:
 * - Transição: PENDING_ALLOCATION → ACTIVE
 * - Alocar profissional ao contrato
 * - Calcular próxima data de execução
 * - Persistir mudanças
 * - Disparar evento ContractActivated
 *
 * @package LimpVix\Application\UseCase\Contract
 * @since 0.8.0
 */

namespace LimpVix\Application\UseCase\Contract;

use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\Exceptions\ContractNotFoundException;

defined('ABSPATH') || exit;

final class ActivateContract
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
     * @param int $professionalId
     * @return void
     * @throws ContractNotFoundException
     * @throws \LimpVix\Domain\Contract\Exceptions\InvalidContractTransition
     */
    public function execute(int $contractId, int $professionalId): void
    {
        // Buscar contrato
        $contract = $this->repository->findById(ContractId::fromInt($contractId));

        if (!$contract) {
            throw new ContractNotFoundException("Contract ID: {$contractId}");
        }

        // Validar profissional (pode adicionar validações extras aqui)
        if ($professionalId <= 0) {
            throw new \InvalidArgumentException('Invalid professional ID');
        }

        // Transição de estado (Domain lança exceção se transição inválida)
        $contract->activate($professionalId);

        // Persistir
        $this->repository->save($contract);
    }
}
