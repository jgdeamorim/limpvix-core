<?php

declare(strict_types=1);

namespace LimpVix\Domain\Verification\ValueObjects;

use LimpVix\Domain\Verification\Enums\KycStatus;

/**
 * KycResult — Output normalizado de qualquer provedor KYC
 *
 * Nunca expõe o retorno bruto do provedor externo.
 * O provedor (PPID ou Mock) é responsável por mapear para este formato.
 */
final readonly class KycResult
{
    public function __construct(
        public readonly KycStatus $status,
        public readonly float     $confidenceScore,
        public readonly bool      $fraudFlag,
        public readonly string    $provider,
        public readonly \DateTimeImmutable $checkedAt,
    ) {}

    public static function approved(float $confidence, string $provider): self
    {
        return new self(
            status:          KycStatus::APPROVED,
            confidenceScore: $confidence,
            fraudFlag:       false,
            provider:        $provider,
            checkedAt:       new \DateTimeImmutable(),
        );
    }

    public static function rejected(float $confidence, string $provider, bool $fraud = false): self
    {
        return new self(
            status:          KycStatus::REJECTED,
            confidenceScore: $confidence,
            fraudFlag:       $fraud,
            provider:        $provider,
            checkedAt:       new \DateTimeImmutable(),
        );
    }

    public function toArray(): array
    {
        return [
            'kycStatus'       => $this->status->value,
            'confidenceScore' => $this->confidenceScore,
            'fraudFlag'       => $this->fraudFlag,
            'provider'        => $this->provider,
            'checkedAt'       => $this->checkedAt->format('Y-m-d H:i:s'),
        ];
    }
}
