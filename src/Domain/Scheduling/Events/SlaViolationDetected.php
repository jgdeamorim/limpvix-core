<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Events;

/**
 * Domain Event: SlaViolationDetected
 *
 * Disparado quando violação de SLA é detectada.
 * IMUTÁVEL.
 */
final class SlaViolationDetected
{
    private string $scheduleUuid;
    private string $violationType;
    private string $severity;
    private array $details;
    private \DateTimeImmutable $occurredAt;

    private function __construct(
        string $scheduleUuid,
        string $violationType,
        string $severity,
        array $details,
        \DateTimeImmutable $occurredAt
    ) {
        $this->scheduleUuid = $scheduleUuid;
        $this->violationType = $violationType;
        $this->severity = $severity;
        $this->details = $details;
        $this->occurredAt = $occurredAt;
    }

    public static function create(
        string $scheduleUuid,
        string $violationType,
        string $severity,
        array $details = []
    ): self {
        return new self(
            $scheduleUuid,
            $violationType,
            $severity,
            $details,
            new \DateTimeImmutable()
        );
    }

    public function getScheduleUuid(): string
    {
        return $this->scheduleUuid;
    }

    public function getViolationType(): string
    {
        return $this->violationType;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event_type' => 'sla_violation_detected',
            'schedule_uuid' => $this->scheduleUuid,
            'violation_type' => $this->violationType,
            'severity' => $this->severity,
            'details' => $this->details,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
