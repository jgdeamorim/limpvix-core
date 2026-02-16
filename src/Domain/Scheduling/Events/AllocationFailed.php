<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Events;

/**
 * Domain Event: AllocationFailed
 *
 * Disparado quando não é possível alocar profissionais para um Schedule.
 * IMUTÁVEL.
 */
final class AllocationFailed
{
    private string $scheduleUuid;
    private string $reason;
    private array $context;
    private \DateTimeImmutable $occurredAt;

    private function __construct(
        string $scheduleUuid,
        string $reason,
        array $context,
        \DateTimeImmutable $occurredAt
    ) {
        $this->scheduleUuid = $scheduleUuid;
        $this->reason = $reason;
        $this->context = $context;
        $this->occurredAt = $occurredAt;
    }

    public static function create(
        string $scheduleUuid,
        string $reason,
        array $context = []
    ): self {
        return new self(
            $scheduleUuid,
            $reason,
            $context,
            new \DateTimeImmutable()
        );
    }

    public function getScheduleUuid(): string
    {
        return $this->scheduleUuid;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event_type' => 'allocation_failed',
            'schedule_uuid' => $this->scheduleUuid,
            'reason' => $this->reason,
            'context' => $this->context,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
