<?php

declare(strict_types=1);

namespace LimpVix\Domain\Verification\Enums;

enum ProfessionalStatus: string
{
    case PENDING_VERIFICATION = 'PENDING_VERIFICATION';
    case ACTIVE               = 'ACTIVE';
    case ACTIVE_MONITORED     = 'ACTIVE_MONITORED';
    case UNDER_REVIEW         = 'UNDER_REVIEW';
    case NOT_ELIGIBLE         = 'NOT_ELIGIBLE';
    case SUSPENDED            = 'SUSPENDED';

    public function label(): string
    {
        return match($this) {
            self::PENDING_VERIFICATION => 'Aguardando Verificação',
            self::ACTIVE               => 'Ativo',
            self::ACTIVE_MONITORED     => 'Ativo (Monitorado)',
            self::UNDER_REVIEW         => 'Em Revisão',
            self::NOT_ELIGIBLE         => 'Não Elegível',
            self::SUSPENDED            => 'Suspenso',
        };
    }

    public function canAcceptOffers(): bool
    {
        return $this === self::ACTIVE || $this === self::ACTIVE_MONITORED;
    }

    public function isActive(): bool
    {
        return $this->canAcceptOffers();
    }

    public function badgeColor(): string
    {
        return match($this) {
            self::ACTIVE               => 'green',
            self::ACTIVE_MONITORED     => 'yellow',
            self::UNDER_REVIEW         => 'orange',
            self::PENDING_VERIFICATION => 'blue',
            self::NOT_ELIGIBLE         => 'red',
            self::SUSPENDED            => 'gray',
        };
    }
}
