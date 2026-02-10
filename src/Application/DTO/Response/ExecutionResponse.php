<?php
/**
 * ExecutionResponse - DTO for Execution API responses
 *
 * @package LimpVix\Application\DTO\Response
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Response;

use LimpVix\Domain\Execution\ContractExecution;

defined('ABSPATH') || exit;

final class ExecutionResponse extends BaseResponseDTO
{
    public function __construct(
        private readonly ContractExecution $execution
    ) {}

    public static function fromAggregate(ContractExecution $execution): self
    {
        return new self($execution);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->execution->getId()->toInt(),
            'contract_id' => $this->execution->getContractId()->toInt(),
            'professional_user_id' => $this->execution->getProfessionalUserId(),
            'scheduled_date' => $this->formatDate($this->execution->getScheduledDate()),
            'started_at' => $this->formatDate($this->execution->getStartedAt()),
            'completed_at' => $this->formatDate($this->execution->getCompletedAt()),
            'status' => $this->execution->getStatus()->toString(),
            'status_label' => $this->getStatusLabel($this->execution->getStatus()->toString()),
            'notes' => $this->execution->getNotes(),
            'photos' => $this->execution->getPhotos(),
            'rating' => $this->execution->getRating(),
            'created_at' => $this->formatDate($this->execution->getCreatedAt()),
            'updated_at' => $this->formatDate($this->execution->getUpdatedAt()),
        ];
    }

    private function getStatusLabel(string $status): string
    {
        return $this->mapLabel($status, [
            'draft' => 'Rascunho',
            'scheduled' => 'Agendado',
            'in_progress' => 'Em Andamento',
            'completed' => 'Concluído',
            'cancelled' => 'Cancelado',
            'no_show' => 'Não Compareceu',
        ]);
    }
}
