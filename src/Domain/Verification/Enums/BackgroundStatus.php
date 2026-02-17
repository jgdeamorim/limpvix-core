<?php

declare(strict_types=1);

namespace LimpVix\Domain\Verification\Enums;

enum BackgroundStatus: string
{
    case PENDING      = 'PENDING';
    case APPROVED     = 'APPROVED';
    case RESTRICTED   = 'RESTRICTED';
    case NOT_ELIGIBLE = 'NOT_ELIGIBLE';

    public function label(): string
    {
        return match($this) {
            self::PENDING      => 'Aguardando Verificação',
            self::APPROVED     => 'Aprovado',
            self::RESTRICTED   => 'Restrito (revisão necessária)',
            self::NOT_ELIGIBLE => 'Não Elegível',
        };
    }

    public function blocksActivation(): bool
    {
        return $this === self::NOT_ELIGIBLE;
    }

    public function requiresReview(): bool
    {
        return $this === self::RESTRICTED;
    }
}
