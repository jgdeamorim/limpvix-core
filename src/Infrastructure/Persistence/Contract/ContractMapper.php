<?php
/**
 * ContractMapper - Converte entre Contract (domain) e array (database)
 *
 * RESPONSABILIDADE:
 * - Converter Aggregate Root para formato de persistência
 * - Reconstruir Aggregate Root a partir de dados do banco
 * - Mapear Value Objects para tipos primitivos
 *
 * @package LimpVix\Infrastructure\Persistence\Contract
 * @since 0.8.0
 */

namespace LimpVix\Infrastructure\Persistence\Contract;

use LimpVix\Domain\Contract\Contract;
use LimpVix\Domain\Contract\ContractId;

defined('ABSPATH') || exit;

final class ContractMapper
{
    /**
     * Converter Contract para array do banco
     *
     * @param Contract $contract
     * @return array
     */
    public static function toDatabase(Contract $contract): array
    {
        $data = [
            'contract_number' => $contract->getContractNumber(),
            'client_user_id' => $contract->getClientUserId(),
            'contract_type' => $contract->getContractType(),
            'recurrence_day' => $contract->getRecurrenceDay(),
            'service_code' => $contract->getServiceCode(),
            'complexity_slug' => $contract->getComplexitySlug(),
            'property_type' => $contract->getPropertyType(),
            'monthly_value' => $contract->getMonthlyValue(),
            'start_date' => $contract->getStartDate()->format('Y-m-d'),
            'end_date' => $contract->getEndDate()?->format('Y-m-d'),
            'auto_renew' => (int) $contract->isAutoRenew(),
            'status' => $contract->getStatus()->toString(),
            'allocated_professional_id' => $contract->getAllocatedProfessionalId(),
            'updated_at' => $contract->getUpdatedAt()->format('Y-m-d H:i:s'),
        ];

        // Incluir ID apenas se não for 0 (update)
        $id = $contract->getId()->toInt();
        if ($id > 0) {
            $data['id'] = $id;
        } else {
            // Insert: incluir created_at
            $data['created_at'] = $contract->getCreatedAt()->format('Y-m-d H:i:s');
        }

        return $data;
    }

    /**
     * Reconstruir Contract a partir de dados do banco
     *
     * @param object|array $row
     * @return Contract
     */
    public static function toDomain($row): Contract
    {
        $data = is_object($row) ? (array) $row : $row;

        return Contract::fromPersistence(
            (int) $data['id'],
            (int) $data['client_user_id'],
            $data['contract_number'],
            $data['status'],
            $data['contract_type'],
            (int) $data['recurrence_day'],
            $data['start_date'],
            $data['end_date'] ?? null,
            $data['service_code'],
            $data['property_type'],
            (float) $data['monthly_value'],
            isset($data['allocated_professional_id']) ? (int) $data['allocated_professional_id'] : null,
            (bool) $data['auto_renew'],
            null, // nextExecutionDate será calculado pelo AR
            $data['created_at'],
            $data['updated_at'],
            $data['complexity_slug'] ?? null
        );
    }

    /**
     * Converter array de rows para array de Contracts
     *
     * @param array $rows
     * @return Contract[]
     */
    public static function toDomainCollection(array $rows): array
    {
        return array_map([self::class, 'toDomain'], $rows);
    }
}
