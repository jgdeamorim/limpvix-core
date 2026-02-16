<?php
/**
 * ContractResponse - DTO for Contract API responses
 *
 * RESPONSABILIDADE:
 * - Converter aggregate Contract para formato de API
 * - Formatar dados para consumo via REST
 * - Ocultar detalhes internos do domínio
 *
 * @package LimpVix\Application\DTO\Response
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Response;

use LimpVix\Domain\Contract\Contract;

defined('ABSPATH') || exit;

final class ContractResponse extends BaseResponseDTO
{
    public function __construct(
        private readonly Contract $contract
    ) {}

    public static function fromAggregate(Contract $contract): self
    {
        return new self($contract);
    }

    public function toArray(): array
    {
        $user = get_userdata($this->contract->getClientUserId());

        return [
            'id' => $this->contract->getId()->toInt(),
            'contract_number' => $this->contract->getContractNumber(),
            'client' => [
                'user_id' => $this->contract->getClientUserId(),
                'name' => $user ? $user->display_name : 'Unknown',
                'email' => $user ? $user->user_email : '',
            ],
            'contract_type' => $this->contract->getContractType(),
            'contract_type_label' => $this->getContractTypeLabel($this->contract->getContractType()),
            'recurrence_day' => $this->contract->getRecurrenceDay(),
            'service_code' => $this->contract->getServiceCode(),
            'property_type' => $this->contract->getPropertyType(),
            'monthly_value' => $this->contract->getMonthlyValue(),
            'monthly_value_formatted' => $this->formatMoney($this->contract->getMonthlyValue()),
            'start_date' => $this->formatDate($this->contract->getStartDate(), 'Y-m-d'),
            'end_date' => $this->formatDate($this->contract->getEndDate(), 'Y-m-d'),
            'auto_renew' => $this->contract->isAutoRenew(),
            'status' => $this->contract->getStatus()->toString(),
            'status_label' => $this->getStatusLabel($this->contract->getStatus()->toString()),
            'allocated_professional_id' => $this->contract->getAllocatedProfessionalId(),
            'next_execution_date' => $this->formatDate($this->contract->getNextExecutionDate(), 'Y-m-d'),
            'created_at' => $this->formatDate($this->contract->getCreatedAt()),
            'updated_at' => $this->formatDate($this->contract->getUpdatedAt()),
        ];
    }

    private function getContractTypeLabel(string $type): string
    {
        return $this->mapLabel($type, [
            'monthly' => 'Mensal',
            'weekly' => 'Semanal',
            'biweekly' => 'Quinzenal',
        ]);
    }

    private function getStatusLabel(string $status): string
    {
        return $this->mapLabel($status, [
            'draft' => 'Rascunho',
            'pending_allocation' => 'Aguardando Alocação',
            'active' => 'Ativo',
            'paused' => 'Pausado',
            'completed' => 'Concluído',
            'cancelled' => 'Cancelado',
        ]);
    }
}
