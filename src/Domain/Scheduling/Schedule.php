<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling;

use LimpVix\Domain\Scheduling\ValueObjects\TimeWindow;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceLocation;
use LimpVix\Domain\Scheduling\ValueObjects\CheckIn;
use LimpVix\Domain\Scheduling\ValueObjects\CheckOut;
use LimpVix\Domain\Scheduling\ValueObjects\SlaViolation;
use LimpVix\Domain\Scheduling\ValueObjects\TimeSlot;

/**
 * Aggregate Root: Schedule
 *
 * Representa um agendamento de serviço com alocação de profissionais,
 * check-in/out e tracking de SLA.
 *
 * State Machine: draft → allocated → in_progress → completed
 */
final class Schedule
{
    private const STATUS_DRAFT = 'draft';
    private const STATUS_ALLOCATED = 'allocated';
    private const STATUS_IN_PROGRESS = 'in_progress';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_CANCELLED = 'cancelled';

    private string $uuid;
    private string $orderUuid;
    private int $briefingId;

    // Tempo
    private \DateTimeImmutable $requestedTime;
    private TimeWindow $validWindow;
    private int $estimatedDurationMinutes;

    // Localização
    private ServiceLocation $location;

    // Alocação
    private array $allocatedProfessionals; // [ professionalId => TimeSlot ]
    private int $requiredProfessionals;

    // Check-in/out
    private ?CheckIn $checkIn;
    private ?CheckOut $checkOut;

    // SLA
    private ?SlaViolation $slaViolation;

    // Status
    private string $status;

    // Timestamps
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $allocatedAt;
    private ?\DateTimeImmutable $completedAt;

    private function __construct(
        string $uuid,
        string $orderUuid,
        int $briefingId,
        \DateTimeImmutable $requestedTime,
        TimeWindow $validWindow,
        int $estimatedDurationMinutes,
        ServiceLocation $location,
        int $requiredProfessionals
    ) {
        if (trim($uuid) === '') {
            throw new \InvalidArgumentException('UUID cannot be empty');
        }

        if (trim($orderUuid) === '') {
            throw new \InvalidArgumentException('Order UUID cannot be empty');
        }

        if ($briefingId <= 0) {
            throw new \InvalidArgumentException('Briefing ID must be positive');
        }

        if ($estimatedDurationMinutes <= 0) {
            throw new \InvalidArgumentException('Estimated duration must be positive');
        }

        if ($requiredProfessionals <= 0) {
            throw new \InvalidArgumentException('Required professionals must be positive');
        }

        $this->uuid = $uuid;
        $this->orderUuid = $orderUuid;
        $this->briefingId = $briefingId;
        $this->requestedTime = $requestedTime;
        $this->validWindow = $validWindow;
        $this->estimatedDurationMinutes = $estimatedDurationMinutes;
        $this->location = $location;
        $this->requiredProfessionals = $requiredProfessionals;

        $this->allocatedProfessionals = [];
        $this->checkIn = null;
        $this->checkOut = null;
        $this->slaViolation = null;
        $this->status = self::STATUS_DRAFT;

        $this->createdAt = new \DateTimeImmutable();
        $this->allocatedAt = null;
        $this->completedAt = null;
    }

    public static function create(
        string $uuid,
        string $orderUuid,
        int $briefingId,
        \DateTimeImmutable $requestedTime,
        TimeWindow $validWindow,
        int $estimatedDurationMinutes,
        ServiceLocation $location,
        int $requiredProfessionals
    ): self {
        return new self(
            $uuid,
            $orderUuid,
            $briefingId,
            $requestedTime,
            $validWindow,
            $estimatedDurationMinutes,
            $location,
            $requiredProfessionals
        );
    }

    /**
     * Aloca profissional ao schedule
     */
    public function allocateProfessional(int $professionalId, TimeSlot $slot): void
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new \DomainException(
                sprintf('Cannot allocate professional in status: %s', $this->status)
            );
        }

        if (isset($this->allocatedProfessionals[$professionalId])) {
            throw new \DomainException('Professional already allocated to this schedule');
        }

        $this->allocatedProfessionals[$professionalId] = $slot;

        // Se alocou todos os profissionais necessários, transicionar para allocated
        if (count($this->allocatedProfessionals) === $this->requiredProfessionals) {
            $this->status = self::STATUS_ALLOCATED;
            $this->allocatedAt = new \DateTimeImmutable();
        }
    }

    /**
     * Registra check-in
     */
    public function performCheckIn(CheckIn $checkIn): void
    {
        if ($this->status !== self::STATUS_ALLOCATED) {
            throw new \DomainException(
                sprintf('Cannot check-in in status: %s', $this->status)
            );
        }

        if (!isset($this->allocatedProfessionals[$checkIn->getProfessionalId()])) {
            throw new \DomainException('Professional is not allocated to this schedule');
        }

        $this->checkIn = $checkIn;
        $this->status = self::STATUS_IN_PROGRESS;

        // Se check-in tem violação, registrar
        if ($checkIn->hasViolation()) {
            $this->slaViolation = $checkIn->getViolation();
        }
    }

    /**
     * Registra check-out
     */
    public function performCheckOut(CheckOut $checkOut): void
    {
        if ($this->status !== self::STATUS_IN_PROGRESS) {
            throw new \DomainException(
                sprintf('Cannot check-out in status: %s', $this->status)
            );
        }

        if ($this->checkIn === null) {
            throw new \DomainException('Cannot check-out without check-in');
        }

        if ($checkOut->getProfessionalId() !== $this->checkIn->getProfessionalId()) {
            throw new \DomainException('Check-out professional must match check-in professional');
        }

        $this->checkOut = $checkOut;
    }

    /**
     * Marca como completo
     */
    public function markAsCompleted(): void
    {
        if ($this->status !== self::STATUS_IN_PROGRESS) {
            throw new \DomainException(
                sprintf('Cannot complete schedule in status: %s', $this->status)
            );
        }

        if ($this->checkOut === null) {
            throw new \DomainException('Cannot complete schedule without check-out');
        }

        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = new \DateTimeImmutable();
    }

    /**
     * Cancela schedule
     */
    public function cancel(): void
    {
        if ($this->status === self::STATUS_IN_PROGRESS) {
            throw new \DomainException('Cannot cancel schedule after check-in');
        }

        if ($this->status === self::STATUS_COMPLETED) {
            throw new \DomainException('Cannot cancel completed schedule');
        }

        $this->status = self::STATUS_CANCELLED;
    }

    /**
     * Registra violação de SLA
     */
    public function recordSlaViolation(SlaViolation $violation): void
    {
        $this->slaViolation = $violation;
    }

    // Checagens de estado

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isAllocated(): bool
    {
        return $this->status === self::STATUS_ALLOCATED;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function hasCheckIn(): bool
    {
        return $this->checkIn !== null;
    }

    public function hasCheckOut(): bool
    {
        return $this->checkOut !== null;
    }

    public function hasSlaViolation(): bool
    {
        return $this->slaViolation !== null;
    }

    public function isFullyAllocated(): bool
    {
        return count($this->allocatedProfessionals) === $this->requiredProfessionals;
    }

    // Getters

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getOrderUuid(): string
    {
        return $this->orderUuid;
    }

    public function getBriefingId(): int
    {
        return $this->briefingId;
    }

    public function getRequestedTime(): \DateTimeImmutable
    {
        return $this->requestedTime;
    }

    public function getValidWindow(): TimeWindow
    {
        return $this->validWindow;
    }

    public function getEstimatedDurationMinutes(): int
    {
        return $this->estimatedDurationMinutes;
    }

    public function getLocation(): ServiceLocation
    {
        return $this->location;
    }

    public function getAllocatedProfessionals(): array
    {
        return $this->allocatedProfessionals;
    }

    public function getRequiredProfessionals(): int
    {
        return $this->requiredProfessionals;
    }

    public function getCheckIn(): ?CheckIn
    {
        return $this->checkIn;
    }

    public function getCheckOut(): ?CheckOut
    {
        return $this->checkOut;
    }

    public function getSlaViolation(): ?SlaViolation
    {
        return $this->slaViolation;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAllocatedAt(): ?\DateTimeImmutable
    {
        return $this->allocatedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'order_uuid' => $this->orderUuid,
            'briefing_id' => $this->briefingId,
            'requested_time' => $this->requestedTime->format('Y-m-d H:i:s'),
            'valid_window' => $this->validWindow->toArray(),
            'estimated_duration_minutes' => $this->estimatedDurationMinutes,
            'location' => $this->location->toArray(),
            'allocated_professionals' => array_map(
                fn(TimeSlot $slot) => $slot->toArray(),
                $this->allocatedProfessionals
            ),
            'required_professionals' => $this->requiredProfessionals,
            'check_in' => $this->checkIn?->toArray(),
            'check_out' => $this->checkOut?->toArray(),
            'sla_violation' => $this->slaViolation?->toArray(),
            'status' => $this->status,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'allocated_at' => $this->allocatedAt?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
