<?php

declare(strict_types=1);

namespace LimpVix\Domain\Verification\Enums;

enum KycStatus: string
{
    case PENDING  = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';

    public function label(): string
    {
        return match($this) {
            self::PENDING  => 'Aguardando KYC',
            self::APPROVED => 'KYC Aprovado',
            self::REJECTED => 'KYC Rejeitado',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::APPROVED || $this === self::REJECTED;
    }
}
