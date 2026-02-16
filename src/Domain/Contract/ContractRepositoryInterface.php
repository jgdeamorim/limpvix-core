<?php
/**
 * ContractRepositoryInterface - Interface de persistência para Contract
 *
 * RESPONSABILIDADE:
 * - Definir contrato de persistência do Aggregate Root
 * - Abstrair detalhes de implementação (WordPress, SQL, etc)
 * - Permitir testabilidade com mocks
 *
 * @package LimpVix\Domain\Contract
 * @since 0.8.0
 */

namespace LimpVix\Domain\Contract;

defined('ABSPATH') || exit;

interface ContractRepositoryInterface
{
    /**
     * Salvar contrato (insert ou update)
     *
     * @param Contract $contract
     * @return void
     */
    public function save(Contract $contract): void;

    /**
     * Buscar por ID
     *
     * @param ContractId $id
     * @return Contract|null
     */
    public function findById(ContractId $id): ?Contract;

    /**
     * Buscar por número do contrato
     *
     * @param string $contractNumber
     * @return Contract|null
     */
    public function findByNumber(string $contractNumber): ?Contract;

    /**
     * Buscar contratos de um cliente
     *
     * @param int $clientUserId
     * @return Contract[]
     */
    public function findByClientId(int $clientUserId): array;

    /**
     * Buscar contratos ativos
     *
     * @return Contract[]
     */
    public function findActiveContracts(): array;

    /**
     * Buscar contratos expirando antes de uma data
     *
     * @param \DateTimeImmutable $beforeDate
     * @return Contract[]
     */
    public function findExpiringContracts(\DateTimeImmutable $beforeDate): array;

    /**
     * Buscar contratos que precisam de próxima execução
     *
     * @param \DateTimeImmutable $date
     * @return Contract[]
     */
    public function findContractsNeedingExecution(\DateTimeImmutable $date): array;

    /**
     * Deletar contrato (soft delete recomendado)
     *
     * @param ContractId $id
     * @return void
     */
    public function delete(ContractId $id): void;

    /**
     * Gerar próximo número de contrato
     *
     * @return string
     */
    public function generateContractNumber(): string;
}
