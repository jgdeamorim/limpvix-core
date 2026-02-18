<?php
/**
 * CreateContract - Use Case para criar novo contrato
 *
 * RESPONSABILIDADE:
 * - Criar novo contrato em status DRAFT
 * - Gerar contract_number automático
 * - Validar dados de entrada
 * - Persistir no banco
 * - Disparar evento ContractCreated
 * - Opcionalmente ativar imediatamente
 *
 * @package LimpVix\Application\UseCases\Contract
 * @since 0.8.0
 */

namespace LimpVix\Application\UseCases\Contract;

use LimpVix\Domain\Contract\Contract;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Application\Services\ContractNumberGenerator;

defined('ABSPATH') || exit;

final class CreateContract
{
    private ContractRepositoryInterface $repository;
    private ContractNumberGenerator $numberGenerator;

    /**
     * Construtor com Dependency Injection
     *
     * @param ContractRepositoryInterface $repository
     * @param ContractNumberGenerator $numberGenerator Service para gerar contract_number único
     */
    public function __construct(
        ContractRepositoryInterface $repository,
        ContractNumberGenerator $numberGenerator
    ) {
        $this->repository = $repository;
        $this->numberGenerator = $numberGenerator;
    }

    /**
     * Executar use case
     *
     * REFATORADO (SPRINT 7 - Item 1.8):
     * - contract_number agora gerado via ContractNumberGenerator service
     * - Formato: LMPVX-YYYYMM-NNNNNN (ex: LMPVX-202602-000123)
     * - Unicidade garantida via retry mechanism + UNIQUE constraint
     *
     * @param array $data Dados do contrato
     * @return Contract Contrato criado
     * @throws \InvalidArgumentException Se dados inválidos
     * @throws \RuntimeException Se não conseguir gerar contract_number único
     */
    public function execute(array $data): Contract
    {
        // Gerar contract_number único (REFATORADO - antes era repository.generateContractNumber())
        $contractNumber = $this->numberGenerator->generate();

        // Criar contrato (status = DRAFT)
        $contract = Contract::create(
            clientUserId: (int) $data['client_user_id'],
            contractNumber: $contractNumber,
            contractType: $data['contract_type'],
            recurrenceDay: (int) $data['recurrence_day'],
            startDate: new \DateTimeImmutable($data['start_date']),
            endDate: isset($data['end_date']) ? new \DateTimeImmutable($data['end_date']) : null,
            serviceCode: $data['service_code'],
            propertyType: $data['property_type'],
            monthlyValue: (float) $data['monthly_value'],
            autoRenew: $data['auto_renew'] ?? true
        );

        // Persistir
        $this->repository->save($contract);

        return $contract;
    }
}
