<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling;

use LimpVix\Domain\Scheduling\ValueObjects\ProfessionalSkills;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceRegion;
use LimpVix\Domain\Scheduling\ValueObjects\WeeklyAvailability;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceLocation;
use LimpVix\Domain\Scheduling\ValueObjects\TimeSlot;

/**
 * Aggregate Root: Professional
 *
 * Representa um profissional de limpeza com disponibilidade, região e skills.
 * Gerencia cálculo de score de alocação.
 */
final class Professional
{
    private int $staffId; // ID do Booknetic
    private int $userId; // WordPress user ID
    private string $name;

    private WeeklyAvailability $availability;
    private int $maxDailyHours;

    private ServiceRegion $serviceRegion;
    private ProfessionalSkills $skills;
    private ?array $physicalLimitations;

    // Métricas para score
    private float $allocationScore;
    private int $completedServices;
    private float $averageRating;

    private bool $isActive;

    private function __construct(
        int $staffId,
        int $userId,
        string $name,
        WeeklyAvailability $availability,
        int $maxDailyHours,
        ServiceRegion $serviceRegion,
        ProfessionalSkills $skills,
        ?array $physicalLimitations = null
    ) {
        if ($staffId <= 0) {
            throw new \InvalidArgumentException('Staff ID must be positive');
        }

        if ($userId <= 0) {
            throw new \InvalidArgumentException('User ID must be positive');
        }

        if (trim($name) === '') {
            throw new \InvalidArgumentException('Name cannot be empty');
        }

        if ($maxDailyHours <= 0 || $maxDailyHours > 12) {
            throw new \InvalidArgumentException('Max daily hours must be between 1 and 12');
        }

        $this->staffId = $staffId;
        $this->userId = $userId;
        $this->name = $name;
        $this->availability = $availability;
        $this->maxDailyHours = $maxDailyHours;
        $this->serviceRegion = $serviceRegion;
        $this->skills = $skills;
        $this->physicalLimitations = $physicalLimitations;

        $this->allocationScore = 0.0;
        $this->completedServices = 0;
        $this->averageRating = 0.0;
        $this->isActive = true;
    }

    public static function create(
        int $staffId,
        int $userId,
        string $name,
        WeeklyAvailability $availability,
        int $maxDailyHours,
        ServiceRegion $serviceRegion,
        ProfessionalSkills $skills,
        ?array $physicalLimitations = null
    ): self {
        return new self(
            $staffId,
            $userId,
            $name,
            $availability,
            $maxDailyHours,
            $serviceRegion,
            $skills,
            $physicalLimitations
        );
    }

    /**
     * Atualiza disponibilidade semanal
     */
    public function updateAvailability(WeeklyAvailability $availability): void
    {
        $this->availability = $availability;
    }

    /**
     * Atualiza região de atuação
     */
    public function updateServiceRegion(ServiceRegion $region): void
    {
        $this->serviceRegion = $region;
    }

    /**
     * Adiciona skill
     */
    public function addSkill(string $skill): void
    {
        $this->skills = $this->skills->withSkill($skill);
    }

    /**
     * Remove skill
     */
    public function removeSkill(string $skill): void
    {
        $this->skills = $this->skills->withoutSkill($skill);
    }

    /**
     * Atualiza métricas após conclusão de serviço
     */
    public function recordServiceCompletion(float $rating): void
    {
        $this->completedServices++;

        // Recalcular média de rating
        $totalRating = ($this->averageRating * ($this->completedServices - 1)) + $rating;
        $this->averageRating = $totalRating / $this->completedServices;

        // Recalcular score de alocação (baseado em experiência + rating)
        $this->recalculateAllocationScore();
    }

    /**
     * Verifica se está disponível em uma data específica
     */
    public function isAvailableOn(\DateTimeImmutable $date): bool
    {
        if (!$this->isActive) {
            return false;
        }

        return $this->availability->isAvailableAt($date);
    }

    /**
     * Verifica se tem skill para complexidade do serviço
     */
    public function hasSkillFor(ValueObjects\ServiceComplexity $complexity): bool
    {
        return $complexity->isCompatibleWith($this->skills);
    }

    /**
     * Verifica se pode atender localização (dentro da região)
     */
    public function canServeLocation(ServiceLocation $location): bool
    {
        return $this->serviceRegion->covers($location);
    }

    /**
     * Calcula distância até uma localização em km
     */
    public function distanceToLocationInKm(ServiceLocation $location): float
    {
        return $this->serviceRegion->distanceFromCenterInKm($location);
    }

    /**
     * Verifica se pode fazer um slot específico (disponibilidade + não excede max diário)
     */
    public function canTakeSlot(TimeSlot $slot): bool
    {
        if (!$this->isActive) {
            return false;
        }

        // Verificar se o slot está dentro da disponibilidade
        if (!$this->availability->isAvailableAt($slot->getStart())) {
            return false;
        }

        // Verificar se duração do slot não excede max diário
        $durationHours = $slot->getDurationInMinutes() / 60;

        return $durationHours <= $this->maxDailyHours;
    }

    /**
     * Ativa profissional
     */
    public function activate(): void
    {
        $this->isActive = true;
    }

    /**
     * Desativa profissional
     */
    public function deactivate(): void
    {
        $this->isActive = false;
    }

    // Getters

    public function getStaffId(): int
    {
        return $this->staffId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAvailability(): WeeklyAvailability
    {
        return $this->availability;
    }

    public function getMaxDailyHours(): int
    {
        return $this->maxDailyHours;
    }

    public function getServiceRegion(): ServiceRegion
    {
        return $this->serviceRegion;
    }

    public function getSkills(): ProfessionalSkills
    {
        return $this->skills;
    }

    public function getPhysicalLimitations(): ?array
    {
        return $this->physicalLimitations;
    }

    public function getAllocationScore(): float
    {
        return $this->allocationScore;
    }

    public function getCompletedServices(): int
    {
        return $this->completedServices;
    }

    public function getAverageRating(): float
    {
        return $this->averageRating;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * Recalcula score de alocação baseado em experiência e rating
     * Score de 0-100
     */
    private function recalculateAllocationScore(): void
    {
        // Score baseado em:
        // - Experiência: 50% (máx 50 serviços = 50 pontos)
        // - Rating: 50% (máx 5 estrelas = 50 pontos)

        $experienceScore = min(50, $this->completedServices);
        $ratingScore = ($this->averageRating / 5) * 50;

        $this->allocationScore = $experienceScore + $ratingScore;
    }

    public function toArray(): array
    {
        return [
            'staff_id' => $this->staffId,
            'user_id' => $this->userId,
            'name' => $this->name,
            'availability' => $this->availability->toArray(),
            'max_daily_hours' => $this->maxDailyHours,
            'service_region' => $this->serviceRegion->toArray(),
            'skills' => $this->skills->toArray(),
            'physical_limitations' => $this->physicalLimitations,
            'allocation_score' => $this->allocationScore,
            'completed_services' => $this->completedServices,
            'average_rating' => $this->averageRating,
            'is_active' => $this->isActive,
        ];
    }
}
