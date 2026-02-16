<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Events;

/**
 * Domain Event: ServiceCompleted
 *
 * Disparado quando serviço é marcado como completo.
 * IMUTÁVEL.
 */
final class ServiceCompleted
{
    private string $scheduleUuid;
    private string $orderUuid;
    private int $actualDurationMinutes;
    private int $estimatedDurationMinutes;
    private bool $hadSlaViolation;
    private \DateTimeImmutable $occurredAt;

    private function __construct(
        string $scheduleUuid,
        string $orderUuid,
        int $actualDurationMinutes,
        int $estimatedDurationMinutes,
        bool $hadSlaViolation,
        \DateTimeImmutable $occurredAt
    ) {
        $this->scheduleUuid = $scheduleUuid;
        $this->orderUuid = $orderUuid;
        $this->actualDurationMinutes = $actualDurationMinutes;
        $this->estimatedDurationMinutes = $estimatedDurationMinutes;
        $this->hadSlaViolation = $hadSlaViolation;
        $this->occurredAt = $occurredAt;
    }

    public static function create(
        string $scheduleUuid,
        string $orderUuid,
        int $actualDurationMinutes,
        int $estimatedDurationMinutes,
        bool $hadSlaViolation
    ): self {
        return new self(
            $scheduleUuid,
            $orderUuid,
            $actualDurationMinutes,
            $estimatedDurationMinutes,
            $hadSlaViolation,
            new \DateTimeImmutable()
        );
    }

    public function getScheduleUuid(): string
    {
        return $this->scheduleUuid;
    }

    public function getOrderUuid(): string
    {
        return $this->orderUuid;
    }

    public function getActualDurationMinutes(): int
    {
        return $this->actualDurationMinutes;
    }

    public function getEstimatedDurationMinutes(): int
    {
        return $this->estimatedDurationMinutes;
    }

    public function hadSlaViolation(): bool
    {
        return $this->hadSlaViolation;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event_type' => 'service_completed',
            'schedule_uuid' => $this->scheduleUuid,
            'order_uuid' => $this->orderUuid,
            'actual_duration_minutes' => $this->actualDurationMinutes,
            'estimated_duration_minutes' => $this->estimatedDurationMinutes,
            'had_sla_violation' => $this->hadSlaViolation,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
