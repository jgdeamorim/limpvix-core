<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Events;

/**
 * Domain Event: ScheduleCreated
 *
 * Disparado quando um novo Schedule é criado.
 * IMUTÁVEL.
 */
final class ScheduleCreated
{
    private string $scheduleUuid;
    private string $orderUuid;
    private int $briefingId;
    private string $requestedTime;
    private int $requiredProfessionals;
    private \DateTimeImmutable $occurredAt;

    private function __construct(
        string $scheduleUuid,
        string $orderUuid,
        int $briefingId,
        string $requestedTime,
        int $requiredProfessionals,
        \DateTimeImmutable $occurredAt
    ) {
        $this->scheduleUuid = $scheduleUuid;
        $this->orderUuid = $orderUuid;
        $this->briefingId = $briefingId;
        $this->requestedTime = $requestedTime;
        $this->requiredProfessionals = $requiredProfessionals;
        $this->occurredAt = $occurredAt;
    }

    public static function create(
        string $scheduleUuid,
        string $orderUuid,
        int $briefingId,
        \DateTimeImmutable $requestedTime,
        int $requiredProfessionals
    ): self {
        return new self(
            $scheduleUuid,
            $orderUuid,
            $briefingId,
            $requestedTime->format('Y-m-d H:i:s'),
            $requiredProfessionals,
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

    public function getBriefingId(): int
    {
        return $this->briefingId;
    }

    public function getRequestedTime(): string
    {
        return $this->requestedTime;
    }

    public function getRequiredProfessionals(): int
    {
        return $this->requiredProfessionals;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event_type' => 'schedule_created',
            'schedule_uuid' => $this->scheduleUuid,
            'order_uuid' => $this->orderUuid,
            'briefing_id' => $this->briefingId,
            'requested_time' => $this->requestedTime,
            'required_professionals' => $this->requiredProfessionals,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
