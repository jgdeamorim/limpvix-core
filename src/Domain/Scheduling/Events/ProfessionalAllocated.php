<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Events;

/**
 * Domain Event: ProfessionalAllocated
 *
 * Disparado quando profissional é alocado a um Schedule.
 * IMUTÁVEL.
 */
final class ProfessionalAllocated
{
    private string $scheduleUuid;
    private int $professionalId;
    private string $allocatedStart;
    private string $allocatedEnd;
    private float $allocationScore;
    private \DateTimeImmutable $occurredAt;

    private function __construct(
        string $scheduleUuid,
        int $professionalId,
        string $allocatedStart,
        string $allocatedEnd,
        float $allocationScore,
        \DateTimeImmutable $occurredAt
    ) {
        $this->scheduleUuid = $scheduleUuid;
        $this->professionalId = $professionalId;
        $this->allocatedStart = $allocatedStart;
        $this->allocatedEnd = $allocatedEnd;
        $this->allocationScore = $allocationScore;
        $this->occurredAt = $occurredAt;
    }

    public static function create(
        string $scheduleUuid,
        int $professionalId,
        \DateTimeImmutable $allocatedStart,
        \DateTimeImmutable $allocatedEnd,
        float $allocationScore
    ): self {
        return new self(
            $scheduleUuid,
            $professionalId,
            $allocatedStart->format('Y-m-d H:i:s'),
            $allocatedEnd->format('Y-m-d H:i:s'),
            $allocationScore,
            new \DateTimeImmutable()
        );
    }

    public function getScheduleUuid(): string
    {
        return $this->scheduleUuid;
    }

    public function getProfessionalId(): int
    {
        return $this->professionalId;
    }

    public function getAllocatedStart(): string
    {
        return $this->allocatedStart;
    }

    public function getAllocatedEnd(): string
    {
        return $this->allocatedEnd;
    }

    public function getAllocationScore(): float
    {
        return $this->allocationScore;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event_type' => 'professional_allocated',
            'schedule_uuid' => $this->scheduleUuid,
            'professional_id' => $this->professionalId,
            'allocated_start' => $this->allocatedStart,
            'allocated_end' => $this->allocatedEnd,
            'allocation_score' => $this->allocationScore,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
