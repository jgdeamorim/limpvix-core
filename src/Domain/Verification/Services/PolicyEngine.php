<?php

declare(strict_types=1);

namespace LimpVix\Domain\Verification\Services;

use LimpVix\Domain\Verification\Enums\BackgroundStatus;
use LimpVix\Domain\Verification\Enums\KycStatus;
use LimpVix\Domain\Verification\Enums\ProfessionalStatus;
use LimpVix\Domain\Verification\Enums\RiskLevel;
use LimpVix\Domain\Verification\ValueObjects\BackgroundResult;
use LimpVix\Domain\Verification\ValueObjects\KycResult;

/**
 * PolicyEngine — Motor de decisão determinístico e auditável
 *
 * REGRAS:
 * - Nunca bloquear por texto livre (apenas enums e constantes internas)
 * - Resultado sempre determinístico para o mesmo input
 * - Configurável via WordPress options
 *
 * IMUTABILIDADE:
 * - As regras base (crimes sexuais, crimes violentos) nunca mudam
 * - As regras configuráveis podem ser ajustadas pelo admin
 */
final class PolicyEngine
{
    /** Categorias que resultam em NOT_ELIGIBLE imediatamente (imutáveis) */
    private const HARD_BLOCK_CATEGORIES = [
        'SEXUAL_CRIME',
        'VIOLENT_CRIME',
    ];

    /** Categorias que resultam em UNDER_REVIEW (configuráveis) */
    private const DEFAULT_REVIEW_CATEGORIES = [
        'FRAUD_RELEVANT',
    ];

    private array $reviewCategories;
    private int $minKycConfidence; // percentual mínimo (0-100)

    public function __construct()
    {
        $this->reviewCategories = get_option(
            'limpvix_policy_review_categories',
            self::DEFAULT_REVIEW_CATEGORIES
        );

        $this->minKycConfidence = (int) get_option('limpvix_policy_min_kyc_confidence', 85);
    }

    /**
     * Determina o ProfessionalStatus final com base nos resultados das camadas
     */
    public function evaluate(
        bool             $otpVerified,
        KycResult        $kycResult,
        BackgroundResult $backgroundResult,
        float            $behaviorScore = 100.0,
    ): ProfessionalStatus {
        // OTP obrigatório
        if (!$otpVerified) {
            return ProfessionalStatus::PENDING_VERIFICATION;
        }

        // KYC reprovado = não elegível
        if ($kycResult->status !== KycStatus::APPROVED) {
            return ProfessionalStatus::NOT_ELIGIBLE;
        }

        // Fraude detectada no KYC = não elegível
        if ($kycResult->fraudFlag) {
            return ProfessionalStatus::NOT_ELIGIBLE;
        }

        // Confiança KYC abaixo do mínimo = não elegível
        $confidencePct = (int) ($kycResult->confidenceScore * 100);
        if ($confidencePct < $this->minKycConfidence) {
            return ProfessionalStatus::UNDER_REVIEW;
        }

        // Background bloqueante = não elegível (regras imutáveis)
        if ($backgroundResult->status === BackgroundStatus::NOT_ELIGIBLE) {
            return ProfessionalStatus::NOT_ELIGIBLE;
        }

        // Categorias de hard block (nunca configurável)
        foreach (self::HARD_BLOCK_CATEGORIES as $category) {
            if (in_array($category, $backgroundResult->riskCategories, true)) {
                return ProfessionalStatus::NOT_ELIGIBLE;
            }
        }

        // Alto risco = não elegível
        if ($backgroundResult->riskLevel === RiskLevel::HIGH) {
            return ProfessionalStatus::NOT_ELIGIBLE;
        }

        // Categorias que requerem revisão (configuráveis)
        foreach ($this->reviewCategories as $category) {
            if (in_array($category, $backgroundResult->riskCategories, true)) {
                return ProfessionalStatus::UNDER_REVIEW;
            }
        }

        // Background restrito = revisão
        if ($backgroundResult->status === BackgroundStatus::RESTRICTED) {
            return ProfessionalStatus::UNDER_REVIEW;
        }

        // Risk engine — score final (85+ = ACTIVE, 70-84 = MONITORED, 50-69 = REVIEW, <50 = SUSPENDED)
        $finalScore = $this->calculateFinalScore($kycResult, $backgroundResult, $behaviorScore);

        return match(true) {
            $finalScore >= 85 => ProfessionalStatus::ACTIVE,
            $finalScore >= 70 => ProfessionalStatus::ACTIVE_MONITORED,
            $finalScore >= 50 => ProfessionalStatus::UNDER_REVIEW,
            default           => ProfessionalStatus::SUSPENDED,
        };
    }

    /**
     * Calcula score final combinado das 3 dimensões
     */
    public function calculateFinalScore(
        KycResult        $kycResult,
        BackgroundResult $backgroundResult,
        float            $behaviorScore,
    ): float {
        $identityScore    = min(100, (int) ($kycResult->confidenceScore * 100));
        $backgroundScore  = $backgroundResult->riskLevel->score();
        $behaviorScore    = min(100, max(0, $behaviorScore));

        // Pesos: 30% identidade, 40% background, 30% comportamento
        return ($identityScore * 0.30) + ($backgroundScore * 0.40) + ($behaviorScore * 0.30);
    }

    /**
     * Retorna o motivo da decisão para auditoria
     */
    public function explainDecision(ProfessionalStatus $status, KycResult $kyc, BackgroundResult $bg): string
    {
        return match($status) {
            ProfessionalStatus::NOT_ELIGIBLE     => "Não elegível: KYC={$kyc->status->value}, Background={$bg->status->value}, Risco={$bg->riskLevel->value}",
            ProfessionalStatus::UNDER_REVIEW     => "Em revisão: Categorias=" . implode(',', $bg->riskCategories),
            ProfessionalStatus::ACTIVE           => "Aprovado: Score alto, sem restrições",
            ProfessionalStatus::ACTIVE_MONITORED => "Aprovado com monitoramento: Risco médio",
            ProfessionalStatus::SUSPENDED        => "Suspenso: Score abaixo do mínimo",
            default                              => "Verificação pendente",
        };
    }
}
