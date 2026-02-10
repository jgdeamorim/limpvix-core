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
 * @package LimpVix\Application\UseCase\Contract
 * @since 0.8.0
 */

namespace LimpVix\Application\UseCase\Contract;

use LimpVix\Domain\Contract\Contract;
use LimpVix\Domain\Contract\ContractRepositoryInterface;

defined('ABSPATH') || exit;

final class CreateContract
{
    private ContractRepositoryInterface $repository;

    public function __construct(ContractRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Executar use case
     *
     * @param array $data Dados do contrato
     * @return Contract Contrato criado
     * @throws \InvalidArgumentException
     */
    public function execute(array $data): Contract
    {
        // Gerar contract_number
        $contractNumber = $this->repository->generateContractNumber();

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
