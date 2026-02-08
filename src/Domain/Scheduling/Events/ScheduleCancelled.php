<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Events;

/**
 * Domain Event: ScheduleCancelled
 *
 * Disparado quando Schedule é cancelado.
 * IMUTÁVEL.
 */
final class ScheduleCancelled
{
    private string $scheduleUuid;
    private string $orderUuid;
    private string $reason;
    private string $cancelledBy; // 'customer'|'system'|'admin'
    private \DateTimeImmutable $occurredAt;

    private function __construct(
        string $scheduleUuid,
        string $orderUuid,
        string $reason,
        string $cancelledBy,
        \DateTimeImmutable $occurredAt
    ) {
        $this->scheduleUuid = $scheduleUuid;
        $this->orderUuid = $orderUuid;
        $this->reason = $reason;
        $this->cancelledBy = $cancelledBy;
        $this->occurredAt = $occurredAt;
    }

    public static function create(
        string $scheduleUuid,
        string $orderUuid,
        string $reason,
        string $cancelledBy = 'system'
    ): self {
        return new self(
            $scheduleUuid,
            $orderUuid,
            $reason,
            $cancelledBy,
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

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCancelledBy(): string
    {
        return $this->cancelledBy;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event_type' => 'schedule_cancelled',
            'schedule_uuid' => $this->scheduleUuid,
            'order_uuid' => $this->orderUuid,
            'reason' => $this->reason,
            'cancelled_by' => $this->cancelledBy,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
