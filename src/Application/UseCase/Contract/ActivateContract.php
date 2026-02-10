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
use LimpVix\Domain\Contract\Exceptions\ProfessionalNotFoundException;
use LimpVix\Domain\Contract\Exceptions\ProfessionalNotAvailableException;

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
     * @throws ProfessionalNotFoundException
     * @throws ProfessionalNotAvailableException
     * @throws \LimpVix\Domain\Contract\Exceptions\InvalidContractTransition
     */
    public function execute(int $contractId, int $professionalId): void
    {
        // Buscar contrato
        $contract = $this->repository->findById(ContractId::fromInt($contractId));

        if (!$contract) {
            throw new ContractNotFoundException("Contract ID: {$contractId}");
        }

        // Validar ID do profissional
        if ($professionalId <= 0) {
            throw new \InvalidArgumentException('Invalid professional ID');
        }

        // Validar que profissional existe e está disponível
        // Retorna o ID (PK) do professional, não o user_id
        $professionalDbId = $this->validateProfessional($professionalId);

        // Transição de estado (Domain lança exceção se transição inválida)
        // Usar o ID da tabela professionals, não o user_id
        $contract->activate($professionalDbId);

        // Persistir
        $this->repository->save($contract);
    }

    /**
     * Validar que profissional existe e está disponível para alocação
     *
     * VALIDAÇÕES:
     * - Professional existe na tabela wp_limpvix_professionals
     * - is_active = 1 (profissional ativo)
     * - is_permanently_banned = 0 (não está banido)
     * - suspended_until é NULL ou data passada (não está suspenso)
     *
     * @param int $professionalUserId User ID do professional (wp_users.ID)
     * @return int ID (PK) do professional na tabela wp_limpvix_professionals
     * @throws ProfessionalNotFoundException
     * @throws ProfessionalNotAvailableException
     */
    private function validateProfessional(int $professionalUserId): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_professionals';

        // Buscar profissional (incluir o ID para FK)
        $professional = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, is_active, is_permanently_banned, suspended_until
             FROM {$table}
             WHERE user_id = %d",
            $professionalUserId
        ));

        // Validar existência
        if (!$professional) {
            throw ProfessionalNotFoundException::withId($professionalUserId);
        }

        // Validar se está ativo
        if (!$professional->is_active) {
            throw ProfessionalNotAvailableException::inactive($professionalUserId);
        }

        // Validar se não está banido
        if ($professional->is_permanently_banned) {
            throw ProfessionalNotAvailableException::banned($professionalUserId);
        }

        // Validar se não está suspenso
        if ($professional->suspended_until) {
            $suspendedUntil = new \DateTimeImmutable($professional->suspended_until);
            $now = new \DateTimeImmutable();

            if ($suspendedUntil > $now) {
                throw ProfessionalNotAvailableException::suspended(
                    $professionalUserId,
                    $suspendedUntil->format('Y-m-d H:i:s')
                );
            }
        }

        // Retornar o ID (PK) do professional para usar na FK
        return (int) $professional->id;
    }
}
