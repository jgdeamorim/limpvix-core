<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Events;

/**
 * Domain Event: CheckOutPerformed
 *
 * Disparado quando check-out é realizado.
 * IMUTÁVEL.
 */
final class CheckOutPerformed
{
    private string $scheduleUuid;
    private string $checkOutUuid;
    private int $professionalId;
    private int $actualDurationMinutes;
    private \DateTimeImmutable $occurredAt;

    private function __construct(
        string $scheduleUuid,
        string $checkOutUuid,
        int $professionalId,
        int $actualDurationMinutes,
        \DateTimeImmutable $occurredAt
    ) {
        $this->scheduleUuid = $scheduleUuid;
        $this->checkOutUuid = $checkOutUuid;
        $this->professionalId = $professionalId;
        $this->actualDurationMinutes = $actualDurationMinutes;
        $this->occurredAt = $occurredAt;
    }

    public static function create(
        string $scheduleUuid,
        string $checkOutUuid,
        int $professionalId,
        int $actualDurationMinutes
    ): self {
        return new self(
            $scheduleUuid,
            $checkOutUuid,
            $professionalId,
            $actualDurationMinutes,
            new \DateTimeImmutable()
        );
    }

    public function getScheduleUuid(): string
    {
        return $this->scheduleUuid;
    }

    public function getCheckOutUuid(): string
    {
        return $this->checkOutUuid;
    }

    public function getProfessionalId(): int
    {
        return $this->professionalId;
    }

    public function getActualDurationMinutes(): int
    {
        return $this->actualDurationMinutes;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event_type' => 'check_out_performed',
            'schedule_uuid' => $this->scheduleUuid,
            'check_out_uuid' => $this->checkOutUuid,
            'professional_id' => $this->professionalId,
            'actual_duration_minutes' => $this->actualDurationMinutes,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
