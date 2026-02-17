<?php

declare(strict_types=1);

namespace LimpVix\Domain\Verification\ValueObjects;

use LimpVix\Domain\Verification\Enums\BackgroundStatus;
use LimpVix\Domain\Verification\Enums\RiskLevel;

/**
 * BackgroundResult — Output normalizado de qualquer provedor de background check
 *
 * REGRA: Nunca armazenar dados brutos da Exato.
 * Apenas a classificação final é persistida.
 * Categorias de risco são mapeadas para enums internos — nunca texto livre.
 */
final readonly class BackgroundResult
{
    /** Categorias internas fixas — nunca usar strings livres */
    public const CATEGORY_VIOLENT_CRIME = 'VIOLENT_CRIME';
    public const CATEGORY_SEXUAL_CRIME  = 'SEXUAL_CRIME';
    public const CATEGORY_FRAUD         = 'FRAUD_RELEVANT';
    public const CATEGORY_DRUGS         = 'DRUGS';
    public const CATEGORY_THEFT         = 'THEFT';
    public const CATEGORY_OTHER         = 'OTHER';

    public function __construct(
        public readonly BackgroundStatus   $status,
        public readonly RiskLevel          $riskLevel,
        public readonly array              $riskCategories, // subset das constantes acima
        public readonly string             $provider,
        public readonly \DateTimeImmutable $checkedAt,
        public readonly \DateTimeImmutable $expiresAt,
    ) {}

    public static function approved(string $provider, int $validityDays = 365): self
    {
        $now = new \DateTimeImmutable();
        return new self(
            status:         BackgroundStatus::APPROVED,
            riskLevel:      RiskLevel::LOW,
            riskCategories: [],
            provider:       $provider,
            checkedAt:      $now,
            expiresAt:      $now->modify("+{$validityDays} days"),
        );
    }

    public static function notEligible(array $riskCategories, RiskLevel $riskLevel, string $provider): self
    {
        $now = new \DateTimeImmutable();
        return new self(
            status:         BackgroundStatus::NOT_ELIGIBLE,
            riskLevel:      $riskLevel,
            riskCategories: $riskCategories,
            provider:       $provider,
            checkedAt:      $now,
            expiresAt:      $now->modify('+365 days'),
        );
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'backgroundStatus' => $this->status->value,
            'riskLevel'        => $this->riskLevel->value,
            'riskCategories'   => $this->riskCategories,
            'provider'         => $this->provider,
            'checkedAt'        => $this->checkedAt->format('Y-m-d H:i:s'),
            'expiresAt'        => $this->expiresAt->format('Y-m-d H:i:s'),
        ];
    }
}
