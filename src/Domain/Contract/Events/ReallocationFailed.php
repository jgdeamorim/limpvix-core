<?php
/**
 * ReallocationFailed - Domain Event
 *
 * Fired when professional reallocation fails validation or execution.
 *
 * Reasons for failure:
 * - New professional not eligible (skills, location, availability)
 * - Contract has active executions in progress
 * - Transaction rollback due to system error
 * - Professional not found or inactive
 *
 * LISTENERS:
 * - NotificationService: Notify admin of failure
 * - AuditLogger: Log failed attempt for compliance
 * - MetricsService: Track reallocation failure rate
 *
 * @package LimpVix\Domain\Contract\Events
 * @since 0.9.0
 */

namespace LimpVix\Domain\Contract\Events;

use LimpVix\Domain\Contract\ContractId;

defined('ABSPATH') || exit;

final class ReallocationFailed
{
    public function __construct(
        public readonly ContractId $contractId,
        public readonly int $attemptedProfessionalId,
        public readonly string $failureReason,
        public readonly ?int $attemptedBy = null,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable()
    ) {}

    public function getEventName(): string
    {
        return 'contract.reallocation_failed';
    }

    public function getPayload(): array
    {
        return [
            'contract_id' => $this->contractId->toInt(),
            'attempted_professional_id' => $this->attemptedProfessionalId,
            'failure_reason' => $this->failureReason,
            'attempted_by' => $this->attemptedBy,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            'Reallocation failed for Contract #%d to Professional #%d: %s',
            $this->contractId->toInt(),
            $this->attemptedProfessionalId,
            $this->failureReason
        );
    }
}
