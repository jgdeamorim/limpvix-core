<?php

declare(strict_types=1);

namespace LimpVix\Domain\Verification;

use LimpVix\Domain\Verification\Enums\BackgroundStatus;
use LimpVix\Domain\Verification\Enums\KycStatus;
use LimpVix\Domain\Verification\Enums\ProfessionalStatus;
use LimpVix\Domain\Verification\Enums\RiskLevel;
use LimpVix\Domain\Verification\ValueObjects\BackgroundResult;
use LimpVix\Domain\Verification\ValueObjects\KycResult;

/**
 * ProfessionalVerification — Aggregate Root do pipeline de elegibilidade
 *
 * Centraliza o estado de verificação em 4 camadas:
 * OTP → KYC → Background → Risk Engine → Status Final
 *
 * Eventos disparados via WordPress actions para auditoria completa.
 */
final class ProfessionalVerification
{
    private string             $id;
    private int                $userId;
    private bool               $otpVerified;
    private KycStatus          $kycStatus;
    private ?KycResult         $kycResult;
    private BackgroundStatus   $backgroundStatus;
    private ?BackgroundResult  $backgroundResult;
    private ?RiskLevel         $riskLevel;
    private ProfessionalStatus $finalStatus;
    private ?\DateTimeImmutable $backgroundExpiresAt;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        string             $id,
        int                $userId,
        bool               $otpVerified,
        KycStatus          $kycStatus,
        BackgroundStatus   $backgroundStatus,
        ProfessionalStatus $finalStatus,
        ?\DateTimeImmutable $backgroundExpiresAt = null,
        ?KycResult         $kycResult = null,
        ?BackgroundResult  $backgroundResult = null,
        ?RiskLevel         $riskLevel = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id                  = $id;
        $this->userId              = $userId;
        $this->otpVerified         = $otpVerified;
        $this->kycStatus           = $kycStatus;
        $this->kycResult           = $kycResult;
        $this->backgroundStatus    = $backgroundStatus;
        $this->backgroundResult    = $backgroundResult;
        $this->riskLevel           = $riskLevel;
        $this->finalStatus         = $finalStatus;
        $this->backgroundExpiresAt = $backgroundExpiresAt;
        $this->createdAt           = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt           = $updatedAt ?? new \DateTimeImmutable();
    }

    /** Inicia um novo pipeline de verificação */
    public static function initiate(int $userId): self
    {
        $instance = new self(
            id:               wp_generate_uuid4(),
            userId:           $userId,
            otpVerified:      false,
            kycStatus:        KycStatus::PENDING,
            backgroundStatus: BackgroundStatus::PENDING,
            finalStatus:      ProfessionalStatus::PENDING_VERIFICATION,
        );

        do_action('limpvix_verification_initiated', $instance->id, $userId);

        return $instance;
    }

    /** Reconstitui a partir do banco de dados */
    public static function reconstitute(array $data): self
    {
        return new self(
            id:               $data['id'],
            userId:           (int) $data['user_id'],
            otpVerified:      (bool) $data['otp_verified'],
            kycStatus:        KycStatus::from($data['kyc_status']),
            backgroundStatus: BackgroundStatus::from($data['background_status']),
            finalStatus:      ProfessionalStatus::from($data['final_status']),
            backgroundExpiresAt: isset($data['background_expires_at'])
                ? new \DateTimeImmutable($data['background_expires_at'])
                : null,
            riskLevel: isset($data['risk_level'])
                ? RiskLevel::from($data['risk_level'])
                : null,
            kycResult: isset($data['kyc_result_json'])
                ? self::hydrateKycResult($data['kyc_result_json'])
                : null,
            backgroundResult: isset($data['background_result_json'])
                ? self::hydrateBackgroundResult($data['background_result_json'])
                : null,
            createdAt: new \DateTimeImmutable($data['created_at'] ?? 'now'),
            updatedAt: new \DateTimeImmutable($data['updated_at'] ?? 'now'),
        );
    }

    // ─── OTP Layer ──────────────────────────────────────────────────────────

    public function markOtpVerified(): void
    {
        $this->otpVerified = true;
        $this->updatedAt   = new \DateTimeImmutable();

        do_action('limpvix_user_otp_verified', $this->userId, $this->id);
    }

    // ─── KYC Layer ──────────────────────────────────────────────────────────

    public function applyKycResult(KycResult $result): void
    {
        $this->kycStatus = $result->status;
        $this->kycResult = $result;
        $this->updatedAt = new \DateTimeImmutable();

        do_action('limpvix_user_kyc_completed', $this->userId, $this->id, $result->status->value);

        if ($result->status === KycStatus::APPROVED) {
            do_action('limpvix_user_kyc_approved', $this->userId, $this->id);
        }
    }

    // ─── Background Layer ───────────────────────────────────────────────────

    public function requestBackgroundCheck(): void
    {
        $this->backgroundStatus = BackgroundStatus::PENDING;
        $this->updatedAt        = new \DateTimeImmutable();

        do_action('limpvix_background_check_requested', $this->userId, $this->id);
    }

    public function applyBackgroundResult(BackgroundResult $result): void
    {
        $this->backgroundStatus    = $result->status;
        $this->backgroundResult    = $result;
        $this->riskLevel           = $result->riskLevel;
        $this->backgroundExpiresAt = $result->expiresAt;
        $this->updatedAt           = new \DateTimeImmutable();

        do_action('limpvix_background_check_completed', $this->userId, $this->id, $result->status->value);
    }

    // ─── Risk Engine / Final Status ─────────────────────────────────────────

    public function applyFinalStatus(ProfessionalStatus $status): void
    {
        $previous         = $this->finalStatus;
        $this->finalStatus = $status;
        $this->updatedAt   = new \DateTimeImmutable();

        do_action('limpvix_professional_status_updated', $this->userId, $this->id, $previous->value, $status->value);
    }

    // ─── Queries ────────────────────────────────────────────────────────────

    public function isFullyApproved(): bool
    {
        return $this->finalStatus === ProfessionalStatus::ACTIVE
            || $this->finalStatus === ProfessionalStatus::ACTIVE_MONITORED;
    }

    public function isBackgroundExpired(): bool
    {
        if ($this->backgroundExpiresAt === null) {
            return false;
        }
        return $this->backgroundExpiresAt < new \DateTimeImmutable();
    }

    public function needsRevalidation(): bool
    {
        return $this->isBackgroundExpired() && $this->isFullyApproved();
    }

    // ─── Getters ────────────────────────────────────────────────────────────

    public function getId(): string                      { return $this->id; }
    public function getUserId(): int                     { return $this->userId; }
    public function isOtpVerified(): bool                { return $this->otpVerified; }
    public function getKycStatus(): KycStatus            { return $this->kycStatus; }
    public function getKycResult(): ?KycResult           { return $this->kycResult; }
    public function getBackgroundStatus(): BackgroundStatus { return $this->backgroundStatus; }
    public function getBackgroundResult(): ?BackgroundResult { return $this->backgroundResult; }
    public function getRiskLevel(): ?RiskLevel           { return $this->riskLevel; }
    public function getFinalStatus(): ProfessionalStatus { return $this->finalStatus; }
    public function getBackgroundExpiresAt(): ?\DateTimeImmutable { return $this->backgroundExpiresAt; }
    public function getCreatedAt(): \DateTimeImmutable   { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable   { return $this->updatedAt; }

    // ─── Persistence ────────────────────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'id'                     => $this->id,
            'user_id'                => $this->userId,
            'otp_verified'           => $this->otpVerified ? 1 : 0,
            'kyc_status'             => $this->kycStatus->value,
            'background_status'      => $this->backgroundStatus->value,
            'risk_level'             => $this->riskLevel?->value,
            'final_status'           => $this->finalStatus->value,
            'background_expires_at'  => $this->backgroundExpiresAt?->format('Y-m-d H:i:s'),
            'kyc_result_json'        => $this->kycResult ? json_encode($this->kycResult->toArray()) : null,
            'background_result_json' => $this->backgroundResult ? json_encode($this->backgroundResult->toArray()) : null,
            'created_at'             => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at'             => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    // ─── Hydration helpers ──────────────────────────────────────────────────

    private static function hydrateKycResult(string $json): ?KycResult
    {
        $data = json_decode($json, true);
        if (!$data) return null;

        return new KycResult(
            status:          KycStatus::from($data['kycStatus']),
            confidenceScore: (float) $data['confidenceScore'],
            fraudFlag:       (bool) $data['fraudFlag'],
            provider:        $data['provider'],
            checkedAt:       new \DateTimeImmutable($data['checkedAt']),
        );
    }

    private static function hydrateBackgroundResult(string $json): ?BackgroundResult
    {
        $data = json_decode($json, true);
        if (!$data) return null;

        return new BackgroundResult(
            status:         BackgroundStatus::from($data['backgroundStatus']),
            riskLevel:      RiskLevel::from($data['riskLevel']),
            riskCategories: $data['riskCategories'] ?? [],
            provider:       $data['provider'],
            checkedAt:      new \DateTimeImmutable($data['checkedAt']),
            expiresAt:      new \DateTimeImmutable($data['expiresAt']),
        );
    }
}
