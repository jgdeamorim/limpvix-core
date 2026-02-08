<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Events;

/**
 * Domain Event: CheckInPerformed
 *
 * Disparado quando check-in é realizado.
 * IMUTÁVEL.
 */
final class CheckInPerformed
{
    private string $scheduleUuid;
    private string $checkInUuid;
    private int $professionalId;
    private bool $withinWindow;
    private bool $withinGeofence;
    private bool $hasViolation;
    private \DateTimeImmutable $occurredAt;

    private function __construct(
        string $scheduleUuid,
        string $checkInUuid,
        int $professionalId,
        bool $withinWindow,
        bool $withinGeofence,
        bool $hasViolation,
        \DateTimeImmutable $occurredAt
    ) {
        $this->scheduleUuid = $scheduleUuid;
        $this->checkInUuid = $checkInUuid;
        $this->professionalId = $professionalId;
        $this->withinWindow = $withinWindow;
        $this->withinGeofence = $withinGeofence;
        $this->hasViolation = $hasViolation;
        $this->occurredAt = $occurredAt;
    }

    public static function create(
        string $scheduleUuid,
        string $checkInUuid,
        int $professionalId,
        bool $withinWindow,
        bool $withinGeofence,
        bool $hasViolation
    ): self {
        return new self(
            $scheduleUuid,
            $checkInUuid,
            $professionalId,
            $withinWindow,
            $withinGeofence,
            $hasViolation,
            new \DateTimeImmutable()
        );
    }

    public function getScheduleUuid(): string
    {
        return $this->scheduleUuid;
    }

    public function getCheckInUuid(): string
    {
        return $this->checkInUuid;
    }

    public function getProfessionalId(): int
    {
        return $this->professionalId;
    }

    public function isWithinWindow(): bool
    {
        return $this->withinWindow;
    }

    public function isWithinGeofence(): bool
    {
        return $this->withinGeofence;
    }

    public function hasViolation(): bool
    {
        return $this->hasViolation;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event_type' => 'check_in_performed',
            'schedule_uuid' => $this->scheduleUuid,
            'check_in_uuid' => $this->checkInUuid,
            'professional_id' => $this->professionalId,
            'within_window' => $this->withinWindow,
            'within_geofence' => $this->withinGeofence,
            'has_violation' => $this->hasViolation,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
