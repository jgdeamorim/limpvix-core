<?php

declare(strict_types=1);

namespace LimpVix\Domain\Verification\Enums;

enum RiskLevel: string
{
    case LOW    = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH   = 'HIGH';

    public function label(): string
    {
        return match($this) {
            self::LOW    => 'Baixo Risco',
            self::MEDIUM => 'Risco Médio',
            self::HIGH   => 'Alto Risco',
        };
    }

    public function score(): int
    {
        return match($this) {
            self::LOW    => 100,
            self::MEDIUM => 60,
            self::HIGH   => 20,
        };
    }
}
