<?php
/**
 * ProfessionalReallocated - Domain Event
 *
 * Fired when a professional is reallocated to a different contract
 * or when a contract's professional allocation changes.
 *
 * Use cases:
 * - Admin reallocates professional manually
 * - System auto-reallocates due to professional unavailability
 * - Professional is swapped between contracts
 *
 * LISTENERS:
 * - NotificationService: Notify old/new professional and client
 * - AllocationHistoryService: Record in allocation history
 * - AuditLogger: Log for compliance
 *
 * @package LimpVix\Domain\Contract\Events
 * @since 0.9.0
 */

namespace LimpVix\Domain\Contract\Events;

use LimpVix\Domain\Contract\ContractId;

defined('ABSPATH') || exit;

final class ProfessionalReallocated
{
    public function __construct(
        public readonly ContractId $contractId,
        public readonly int $oldProfessionalId,
        public readonly int $newProfessionalId,
        public readonly string $reason,
        public readonly ?int $reallocatedBy,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable()
    ) {}

    public function getEventName(): string
    {
        return 'contract.professional_reallocated';
    }

    public function getPayload(): array
    {
        return [
            'contract_id' => $this->contractId->toInt(),
            'old_professional_id' => $this->oldProfessionalId,
            'new_professional_id' => $this->newProfessionalId,
            'reason' => $this->reason,
            'reallocated_by' => $this->reallocatedBy,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            'Professional reallocated for Contract #%d: %d → %d (Reason: %s)',
            $this->contractId->toInt(),
            $this->oldProfessionalId,
            $this->newProfessionalId,
            $this->reason
        );
    }
}
