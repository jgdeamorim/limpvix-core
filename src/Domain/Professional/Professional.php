<?php
/**
 * Professional Aggregate Root
 *
 * Profissional autônomo da plataforma marketplace
 * Sem vínculo empregatício, modelo gig economy (Uber/99)
 *
 * RESPONSABILIDADES:
 * - Score e performance tracking
 * - Região de atuação e proximidade
 * - Skills e certificações
 * - Disponibilidade semanal
 * - Aceitação/rejeição de ofertas
 *
 * INVARIANTES:
 * - Score sempre entre 0.00 e 5.00
 * - Região de atuação válida
 * - Pelo menos 1 skill
 * - Disponibilidade válida sem overlaps
 *
 * @package LimpVix\Domain\Professional
 */

namespace LimpVix\Domain\Professional;

use LimpVix\Domain\Professional\ValueObjects\ServiceRegion;
use LimpVix\Domain\Professional\ValueObjects\WeeklyAvailability;
use LimpVix\Domain\Professional\ValueObjects\ProfessionalSkills;

defined('ABSPATH') || exit;

class Professional
{
    // Identificação
    private ?int $id;
    private int $userId;
    private string $fullName;
    private string $cpf;
    private string $phone;
    private string $email;

    // Score e Performance
    private float $score;
    private int $totalServices;
    private int $completedServices;
    private int $cancelledServices;
    private int $noShowCount;
    private float $acceptanceRate;

    // Pontualidade
    private int $onTimeCount;
    private int $lateCount;
    private int $avgDelayMinutes;

    // Localização e Skills
    private ServiceRegion $serviceRegion;
    private ProfessionalSkills $skills;
    private WeeklyAvailability $availability;
    private int $maxDailyHours;

    // Financeiro
    private ?array $bankAccount;
    private ?string $pixKey;
    private ?string $pixKeyType;

    // Status
    private bool $isActive;
    private bool $isVerified;
    private ?\DateTimeImmutable $verifiedAt;
    private ?int $verifiedBy;

    // Suspensão
    private ?\DateTimeImmutable $suspendedUntil;
    private ?string $suspensionReason;
    private bool $isPermanentlyBanned;
    private ?string $banReason;

    // Compliance
    private bool $hasValidDocuments;
    private ?\DateTimeImmutable $documentExpiryDate;
    private ?array $documents;

    // EPI
    private bool $hasEpi;
    private ?\DateTimeImmutable $epiLastCheck;

    // Timestamps
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    private ?\DateTimeImmutable $lastActivityAt;

    /**
     * Construtor privado - usar factories
     */
    private function __construct()
    {
        $this->id = null;
        $this->score = 5.00;
        $this->totalServices = 0;
        $this->completedServices = 0;
        $this->cancelledServices = 0;
        $this->noShowCount = 0;
        $this->acceptanceRate = 100.00;
        $this->onTimeCount = 0;
        $this->lateCount = 0;
        $this->avgDelayMinutes = 0;
        $this->maxDailyHours = 8;
        $this->bankAccount = null;
        $this->pixKey = null;
        $this->pixKeyType = null;
        $this->isActive = true;
        $this->isVerified = false;
        $this->verifiedAt = null;
        $this->verifiedBy = null;
        $this->suspendedUntil = null;
        $this->suspensionReason = null;
        $this->isPermanentlyBanned = false;
        $this->banReason = null;
        $this->hasValidDocuments = false;
        $this->documentExpiryDate = null;
        $this->documents = null;
        $this->hasEpi = false;
        $this->epiLastCheck = null;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->lastActivityAt = null;
    }

    /**
     * Factory: Criar novo profissional
     */
    public static function create(
        int $userId,
        string $fullName,
        string $cpf,
        string $phone,
        string $email,
        ServiceRegion $serviceRegion,
        ProfessionalSkills $skills,
        WeeklyAvailability $availability
    ): self {
        $professional = new self();

        $professional->validateUserId($userId);
        $professional->validateCpf($cpf);
        $professional->validateEmail($email);

        $professional->userId = $userId;
        $professional->fullName = trim($fullName);
        $professional->cpf = preg_replace('/\D/', '', $cpf);
        $professional->phone = $phone;
        $professional->email = $email;
        $professional->serviceRegion = $serviceRegion;
        $professional->skills = $skills;
        $professional->availability = $availability;

        return $professional;
    }

    /**
     * Factory: Reconstitui Professional do banco de dados
     */
    public static function reconstitute(array $data): self
    {
        $professional = new self();

        $professional->id = (int) $data['id'];
        $professional->userId = (int) $data['user_id'];
        $professional->fullName = $data['full_name'];
        $professional->cpf = $data['cpf'];
        $professional->phone = $data['phone'];
        $professional->email = $data['email'];

        $professional->score = (float) $data['score'];
        $professional->totalServices = (int) $data['total_services'];
        $professional->completedServices = (int) $data['completed_services'];
        $professional->cancelledServices = (int) $data['cancelled_services'];
        $professional->noShowCount = (int) $data['no_show_count'];
        $professional->acceptanceRate = (float) $data['acceptance_rate'];

        $professional->onTimeCount = (int) $data['on_time_count'];
        $professional->lateCount = (int) $data['late_count'];
        $professional->avgDelayMinutes = (int) $data['avg_delay_minutes'];

        $professional->serviceRegion = ServiceRegion::fromArray(json_decode($data['service_region'], true));
        $professional->skills = ProfessionalSkills::fromJson(
            $data['skills'],
            $data['certifications'] ?? null,
            $data['physical_limitations'] ?? null
        );
        $professional->availability = WeeklyAvailability::fromJson($data['weekly_availability']);
        $professional->maxDailyHours = (int) $data['max_daily_hours'];

        $professional->bankAccount = $data['bank_account'] ? json_decode($data['bank_account'], true) : null;
        $professional->pixKey = $data['pix_key'];
        $professional->pixKeyType = $data['pix_key_type'];

        $professional->isActive = (bool) $data['is_active'];
        $professional->isVerified = (bool) $data['is_verified'];
        $professional->verifiedAt = $data['verified_at'] ? new \DateTimeImmutable($data['verified_at']) : null;
        $professional->verifiedBy = $data['verified_by'] ? (int) $data['verified_by'] : null;

        $professional->suspendedUntil = $data['suspended_until'] ? new \DateTimeImmutable($data['suspended_until']) : null;
        $professional->suspensionReason = $data['suspension_reason'];
        $professional->isPermanentlyBanned = (bool) $data['is_permanently_banned'];
        $professional->banReason = $data['ban_reason'];

        $professional->hasValidDocuments = (bool) $data['has_valid_documents'];
        $professional->documentExpiryDate = $data['document_expiry_date'] ? new \DateTimeImmutable($data['document_expiry_date']) : null;
        $professional->documents = $data['documents'] ? json_decode($data['documents'], true) : null;

        $professional->hasEpi = (bool) $data['has_epi'];
        $professional->epiLastCheck = $data['epi_last_check'] ? new \DateTimeImmutable($data['epi_last_check']) : null;

        $professional->createdAt = new \DateTimeImmutable($data['created_at']);
        $professional->updatedAt = new \DateTimeImmutable($data['updated_at']);
        $professional->lastActivityAt = $data['last_activity_at'] ? new \DateTimeImmutable($data['last_activity_at']) : null;

        return $professional;
    }

    // COMPORTAMENTOS
    public function acceptOffer(): void
    {
        $this->ensureIsActive();
        $this->ensureNotSuspended();
        $this->totalServices++;
        $this->recordActivity();
    }

    public function rejectOffer(): void
    {
        $this->totalServices++;
        $this->recalculateAcceptanceRate();
        $this->recordActivity();
    }

    public function completeService(bool $wasOnTime = true, int $delayMinutes = 0): void
    {
        $this->completedServices++;
        if ($wasOnTime) {
            $this->onTimeCount++;
        } else {
            $this->lateCount++;
            $this->avgDelayMinutes = (int) (($this->avgDelayMinutes * ($this->lateCount - 1) + $delayMinutes) / $this->lateCount);
        }
        $this->recordActivity();
    }

    public function cancelService(): void
    {
        $this->cancelledServices++;
        $this->recordActivity();
    }

    public function recordNoShow(): void
    {
        $this->noShowCount++;
        $this->recordActivity();
    }

    public function updateScore(float $newScore): void
    {
        if ($newScore < 0.00 || $newScore > 5.00) {
            throw new \InvalidArgumentException("Score inválido");
        }
        $this->score = round($newScore, 2);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function suspend(\DateTimeImmutable $until, string $reason): void
    {
        $this->suspendedUntil = $until;
        $this->suspensionReason = $reason;
        $this->isActive = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function removeSuspension(): void
    {
        $this->suspendedUntil = null;
        $this->suspensionReason = null;
        $this->isActive = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function banPermanently(string $reason): void
    {
        $this->isPermanentlyBanned = true;
        $this->banReason = $reason;
        $this->isActive = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function verify(int $verifiedByUserId): void
    {
        $this->isVerified = true;
        $this->verifiedAt = new \DateTimeImmutable();
        $this->verifiedBy = $verifiedByUserId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updateAvailability(WeeklyAvailability $newAvailability): void
    {
        $this->availability = $newAvailability;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updateServiceRegion(ServiceRegion $newRegion): void
    {
        $this->serviceRegion = $newRegion;
        $this->updatedAt = new \DateTimeImmutable();
    }

    // QUERIES
    public function isAvailableAt(\DateTimeImmutable $dateTime): bool
    {
        if (!$this->isActive || $this->isSuspended() || $this->isPermanentlyBanned) {
            return false;
        }
        return $this->availability->isAvailableAtDateTime($dateTime);
    }

    public function canServeLocation(float $targetLat, float $targetLng): bool
    {
        return $this->serviceRegion->coversLocation($targetLat, $targetLng);
    }

    public function proximityScore(float $targetLat, float $targetLng): float
    {
        return $this->serviceRegion->proximityScore($targetLat, $targetLng);
    }

    public function hasRequiredSkills(array $requiredSkills): bool
    {
        return $this->skills->hasAllSkills($requiredSkills);
    }

    public function isSuspended(): bool
    {
        if ($this->suspendedUntil === null) return false;
        return $this->suspendedUntil > new \DateTimeImmutable();
    }

    public function isInGoodStanding(): bool
    {
        return $this->isActive && $this->isVerified && !$this->isSuspended() && !$this->isPermanentlyBanned;
    }

    // GETTERS
    public function getId(): ?int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getFullName(): string { return $this->fullName; }
    public function getCpf(): string { return $this->cpf; }
    public function getPhone(): string { return $this->phone; }
    public function getEmail(): string { return $this->email; }
    public function getScore(): float { return $this->score; }
    public function getTotalServices(): int { return $this->totalServices; }
    public function getCompletedServices(): int { return $this->completedServices; }
    public function getCancelledServices(): int { return $this->cancelledServices; }
    public function getNoShowCount(): int { return $this->noShowCount; }
    public function getAcceptanceRate(): float { return $this->acceptanceRate; }
    public function getOnTimeCount(): int { return $this->onTimeCount; }
    public function getLateCount(): int { return $this->lateCount; }
    public function getAvgDelayMinutes(): int { return $this->avgDelayMinutes; }
    public function getServiceRegion(): ServiceRegion { return $this->serviceRegion; }
    public function getSkills(): ProfessionalSkills { return $this->skills; }
    public function getAvailability(): WeeklyAvailability { return $this->availability; }
    public function getMaxDailyHours(): int { return $this->maxDailyHours; }
    public function isActive(): bool { return $this->isActive; }
    public function isVerified(): bool { return $this->isVerified; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    // HELPERS
    private function ensureIsActive(): void
    {
        if (!$this->isActive) throw new \DomainException('Profissional não está ativo');
    }

    private function ensureNotSuspended(): void
    {
        if ($this->isSuspended()) throw new \DomainException('Profissional está suspenso');
    }

    private function recalculateAcceptanceRate(): void
    {
        if ($this->totalServices === 0) {
            $this->acceptanceRate = 100.00;
            return;
        }
        $accepted = $this->completedServices;
        $this->acceptanceRate = round(($accepted / $this->totalServices) * 100, 2);
    }

    private function recordActivity(): void
    {
        $this->lastActivityAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function validateUserId(int $userId): void
    {
        if ($userId <= 0) throw new \InvalidArgumentException('User ID inválido');
    }

    private function validateCpf(string $cpf): void
    {
        $cpfClean = preg_replace('/\D/', '', $cpf);
        if (strlen($cpfClean) !== 11) throw new \InvalidArgumentException('CPF inválido');
    }

    private function validateEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException("Email inválido");
    }

    public function __toString(): string
    {
        return sprintf('Professional(id: %d, name: %s, score: %.2f)', $this->id ?? 0, $this->fullName, $this->score);
    }
}
