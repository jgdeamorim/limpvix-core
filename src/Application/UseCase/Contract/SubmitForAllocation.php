<?php
/**
 * SubmitForAllocation - Use Case para enviar contrato para alocação
 *
 * RESPONSABILIDADE:
 * - Transição: DRAFT → PENDING_ALLOCATION
 * - Validar que contrato está pronto para alocação
 * - Persistir mudanças
 * - Disparar evento (se necessário)
 *
 * CONTEXTO DE NEGÓCIO:
 * - Cliente finaliza cadastro do contrato (draft)
 * - Sistema envia para fila de alocação de profissional
 * - Profissional será alocado posteriormente
 *
 * @package LimpVix\Application\UseCase\Contract
 * @since 0.8.0
 */

namespace LimpVix\Application\UseCase\Contract;

use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\Exceptions\ContractNotFoundException;

defined('ABSPATH') || exit;

final class SubmitForAllocation
{
    private ContractRepositoryInterface $repository;

    public function __construct(ContractRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Executar use case
     *
     * FLUXO:
     * 1. Buscar contrato por ID
     * 2. Validar que está em status DRAFT
     * 3. Transição: DRAFT → PENDING_ALLOCATION
     * 4. Persistir mudanças
     *
     * @param int $contractId
     * @return void
     * @throws ContractNotFoundException Se contrato não existe
     * @throws \DomainException Se transição inválida (não está em draft)
     */
    public function execute(int $contractId): void
    {
        // Buscar contrato
        $contract = $this->repository->findById(ContractId::fromInt($contractId));

        if (!$contract) {
            throw new ContractNotFoundException("Contract ID: {$contractId}");
        }

        // Transição de estado (Domain lança exceção se transição inválida)
        // Validação: só pode submeter se estiver em DRAFT
        $contract->submitForAllocation();

        // Persistir
        $this->repository->save($contract);

    }
}
