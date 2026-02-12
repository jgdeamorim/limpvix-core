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
    // KYC (Know Your Customer) - Verificação Biométrica
    private string $kycStatus;
    private ?DateTimeImmutable $kycStartedAt;
    private ?DateTimeImmutable $kycSubmittedAt;
    private ?DateTimeImmutable $kycApprovedAt;
    private ?DateTimeImmutable $kycRejectedAt;
    private ?DateTimeImmutable $kycExpiresAt;
    private ?string $kycDocumentUrl;
    private ?string $kycSelfieUrl;
    private ?array $kycOcrData;
    private ?array $kycLivenessData;
    private ?array $kycFaceMatchData;
    private ?string $kycRejectionReason;
    private ?string $kycAdminNotes;
    private ?int $kycApprovedBy;
    private ?int $kycRejectedBy;
    private ?string $kycDocumentType;
    private int $kycRetryCount;
    private ?DateTimeImmutable $kycLastRetryAt;


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
        // KYC initialization
        $this->kycStatus = "not_started";
        $this->kycStartedAt = null;
        $this->kycSubmittedAt = null;
        $this->kycApprovedAt = null;
        $this->kycRejectedAt = null;
        $this->kycExpiresAt = null;
        $this->kycDocumentUrl = null;
        $this->kycSelfieUrl = null;
        $this->kycOcrData = null;
        $this->kycLivenessData = null;
        $this->kycFaceMatchData = null;
        $this->kycRejectionReason = null;
        $this->kycAdminNotes = null;
        $this->kycApprovedBy = null;
        $this->kycRejectedBy = null;
        $this->kycDocumentType = null;
        $this->kycRetryCount = 0;
        $this->kycLastRetryAt = null;
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

        // KYC fields
        $professional->kycStatus = $data['kyc_status'] ?? 'not_started';
        $professional->kycStartedAt = $data['kyc_started_at'] ? new \DateTimeImmutable($data['kyc_started_at']) : null;
        $professional->kycSubmittedAt = $data['kyc_submitted_at'] ? new \DateTimeImmutable($data['kyc_submitted_at']) : null;
        $professional->kycApprovedAt = $data['kyc_approved_at'] ? new \DateTimeImmutable($data['kyc_approved_at']) : null;
        $professional->kycRejectedAt = $data['kyc_rejected_at'] ? new \DateTimeImmutable($data['kyc_rejected_at']) : null;
        $professional->kycExpiresAt = $data['kyc_expires_at'] ? new \DateTimeImmutable($data['kyc_expires_at']) : null;
        $professional->kycDocumentUrl = $data['kyc_document_url'] ?? null;
        $professional->kycSelfieUrl = $data['kyc_selfie_url'] ?? null;
        $professional->kycOcrData = $data['kyc_ocr_data'] ? json_decode($data['kyc_ocr_data'], true) : null;
        $professional->kycLivenessData = $data['kyc_liveness_data'] ? json_decode($data['kyc_liveness_data'], true) : null;
        $professional->kycFaceMatchData = $data['kyc_facematch_data'] ? json_decode($data['kyc_facematch_data'], true) : null;
        $professional->kycRejectionReason = $data['kyc_rejection_reason'] ?? null;
        $professional->kycAdminNotes = $data['kyc_admin_notes'] ?? null;
        $professional->kycApprovedBy = $data['kyc_approved_by'] ? (int) $data['kyc_approved_by'] : null;
        $professional->kycRejectedBy = $data['kyc_rejected_by'] ? (int) $data['kyc_rejected_by'] : null;
        $professional->kycDocumentType = $data['kyc_document_type'] ?? null;
        $professional->kycRetryCount = (int) ($data['kyc_retry_count'] ?? 0);
        $professional->kycLastRetryAt = $data['kyc_last_retry_at'] ? new \DateTimeImmutable($data['kyc_last_retry_at']) : null;

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


    // ========================================================================
    // KYC (Know Your Customer) - Métodos de Verificação Biométrica
    // ========================================================================

    /**
     * Inicia processo de KYC
     */
    public function startKyc(): void
    {
        if ($this->kycStatus !== 'not_started' && $this->kycStatus !== 'rejected') {
            throw new \DomainException('KYC já iniciado ou aprovado');
        }

        if ($this->kycRetryCount >= 3) {
            throw new \DomainException('Limite de tentativas de KYC excedido (3 tentativas). Contate o suporte.');
        }

        $this->kycStatus = 'pending';
        $this->kycStartedAt = new \DateTimeImmutable();
        $this->recordActivity();
    }

    /**
     * Submete documentos para processamento KYC
     */
    public function submitKycDocuments(
        string $documentUrl,
        string $selfieUrl,
        string $documentType
    ): void {
        if ($this->kycStatus !== 'pending') {
            throw new \DomainException('KYC deve estar em status pending para submeter documentos');
        }

        $validTypes = ['rg', 'cnh', 'passport', 'other'];
        if (!in_array($documentType, $validTypes, true)) {
            throw new \InvalidArgumentException("Tipo de documento inválido: {$documentType}");
        }

        $this->kycDocumentUrl = $documentUrl;
        $this->kycSelfieUrl = $selfieUrl;
        $this->kycDocumentType = $documentType;
        $this->kycSubmittedAt = new \DateTimeImmutable();
        $this->kycStatus = 'processing';
        $this->recordActivity();
    }

    /**
     * Armazena resultado do OCR (extraído do documento)
     */
    public function storeOcrData(array $ocrData): void
    {
        $this->kycOcrData = $ocrData;
        $this->recordActivity();
    }

    /**
     * Armazena resultado do Liveness Detection
     */
    public function storeLivenessData(array $livenessData): void
    {
        $this->kycLivenessData = $livenessData;
        $this->recordActivity();
    }

    /**
     * Armazena resultado do Face Match
     */
    public function storeFaceMatchData(array $faceMatchData): void
    {
        $this->kycFaceMatchData = $faceMatchData;
        $this->recordActivity();
    }

    /**
     * Aprova KYC (automaticamente ou manualmente pelo admin)
     */
    public function approveKyc(?int $approvedBy = null, int $validityMonths = 24): void
    {
        if ($this->kycStatus !== 'processing' && $this->kycStatus !== 'pending') {
            throw new \DomainException('KYC deve estar em processing ou pending para aprovar');
        }

        $this->kycStatus = 'approved';
        $this->kycApprovedAt = new \DateTimeImmutable();
        $this->kycApprovedBy = $approvedBy;
        $this->kycExpiresAt = (new \DateTimeImmutable())->modify("+{$validityMonths} months");
        $this->kycRejectionReason = null;
        $this->kycRetryCount = 0; // Reset retry count on approval
        $this->recordActivity();
    }

    /**
     * Rejeita KYC (falha na validação biométrica ou manual)
     */
    public function rejectKyc(string $reason, ?int $rejectedBy = null): void
    {
        if ($this->kycStatus !== 'processing' && $this->kycStatus !== 'pending') {
            throw new \DomainException('KYC deve estar em processing ou pending para rejeitar');
        }

        $this->kycStatus = 'rejected';
        $this->kycRejectedAt = new \DateTimeImmutable();
        $this->kycRejectedBy = $rejectedBy;
        $this->kycRejectionReason = $reason;
        $this->kycRetryCount++;
        $this->kycLastRetryAt = new \DateTimeImmutable();
        $this->recordActivity();
    }

    /**
     * Adiciona notas do admin sobre o KYC
     */
    public function addKycAdminNotes(string $notes): void
    {
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $existingNotes = $this->kycAdminNotes ?? '';
        
        $this->kycAdminNotes = $existingNotes 
            ? "{$existingNotes}\n\n[{$timestamp}] {$notes}"
            : "[{$timestamp}] {$notes}";
        
        $this->recordActivity();
    }

    /**
     * Verifica se KYC está expirado
     */
    public function isKycExpired(): bool
    {
        if ($this->kycStatus !== 'approved') {
            return false;
        }

        if ($this->kycExpiresAt === null) {
            return false;
        }

        return $this->kycExpiresAt < new \DateTimeImmutable();
    }

    /**
     * Marca KYC como expirado (executado por cron job)
     */
    public function expireKyc(): void
    {
        if ($this->kycStatus !== 'approved') {
            throw new \DomainException('Apenas KYC aprovado pode expirar');
        }

        if (!$this->isKycExpired()) {
            throw new \DomainException('KYC ainda não expirou');
        }

        $this->kycStatus = 'expired';
        $this->recordActivity();
    }

    /**
     * Verifica se profissional pode aceitar offers (KYC aprovado e não expirado)
     */
    public function canAcceptOffers(): bool
    {
        // KYC deve estar aprovado
        if ($this->kycStatus !== 'approved') {
            return false;
        }

        // KYC não pode estar expirado
        if ($this->isKycExpired()) {
            return false;
        }

        // Profissional deve estar ativo e verificado
        if (!$this->isActive || !$this->isVerified) {
            return false;
        }

        // Não pode estar suspenso
        if ($this->isSuspended()) {
            return false;
        }

        return true;
    }

    // ========================================================================
    // KYC Getters
    // ========================================================================

    public function getKycStatus(): string
    {
        return $this->kycStatus;
    }

    public function getKycStartedAt(): ?\DateTimeImmutable
    {
        return $this->kycStartedAt;
    }

    public function getKycSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->kycSubmittedAt;
    }

    public function getKycApprovedAt(): ?\DateTimeImmutable
    {
        return $this->kycApprovedAt;
    }

    public function getKycRejectedAt(): ?\DateTimeImmutable
    {
        return $this->kycRejectedAt;
    }

    public function getKycExpiresAt(): ?\DateTimeImmutable
    {
        return $this->kycExpiresAt;
    }

    public function getKycDocumentUrl(): ?string
    {
        return $this->kycDocumentUrl;
    }

    public function getKycSelfieUrl(): ?string
    {
        return $this->kycSelfieUrl;
    }

    public function getKycOcrData(): ?array
    {
        return $this->kycOcrData;
    }

    public function getKycLivenessData(): ?array
    {
        return $this->kycLivenessData;
    }

    public function getKycFaceMatchData(): ?array
    {
        return $this->kycFaceMatchData;
    }

    public function getKycRejectionReason(): ?string
    {
        return $this->kycRejectionReason;
    }

    public function getKycAdminNotes(): ?string
    {
        return $this->kycAdminNotes;
    }

    public function getKycApprovedBy(): ?int
    {
        return $this->kycApprovedBy;
    }

    public function getKycRejectedBy(): ?int
    {
        return $this->kycRejectedBy;
    }

    public function getKycDocumentType(): ?string
    {
        return $this->kycDocumentType;
    }

    public function getKycRetryCount(): int
    {
        return $this->kycRetryCount;
    }

    public function getKycLastRetryAt(): ?\DateTimeImmutable
    {
        return $this->kycLastRetryAt;
    }

    /**
     * Retorna informações resumidas do KYC para exibição
     */
    public function getKycSummary(): array
    {
        return [
            'status' => $this->kycStatus,
            'started_at' => $this->kycStartedAt?->format('Y-m-d H:i:s'),
            'submitted_at' => $this->kycSubmittedAt?->format('Y-m-d H:i:s'),
            'approved_at' => $this->kycApprovedAt?->format('Y-m-d H:i:s'),
            'rejected_at' => $this->kycRejectedAt?->format('Y-m-d H:i:s'),
            'expires_at' => $this->kycExpiresAt?->format('Y-m-d H:i:s'),
            'is_expired' => $this->isKycExpired(),
            'can_accept_offers' => $this->canAcceptOffers(),
            'retry_count' => $this->kycRetryCount,
            'rejection_reason' => $this->kycRejectionReason,
            'document_type' => $this->kycDocumentType,
        ];
    }
    public function __toString(): string
    {
        return sprintf('Professional(id: %d, name: %s, score: %.2f)', $this->id ?? 0, $this->fullName, $this->score);
    }
}
